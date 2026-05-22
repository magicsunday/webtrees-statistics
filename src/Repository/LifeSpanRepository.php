<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\ModuleBase\Processor\NameProcessor;
use MagicSunday\Webtrees\Statistic\Model\Dto\Metric\WinterPeakScore;
use MagicSunday\Webtrees\Statistic\Support\CenturyName;
use MagicSunday\Webtrees\Statistic\Support\HistogramTrim;
use MagicSunday\Webtrees\Statistic\Support\SeasonalityScore;

use function array_fill_keys;
use function array_key_last;
use function array_map;
use function getdate;
use function GregorianToJD;
use function intdiv;
use function is_numeric;
use function ksort;
use function max;
use function round;

/**
 * Life-span aggregations for the LifeSpan tab. Wraps the public
 * accessors core's {@see StatisticsData} exposes (statsAgeQuery,
 * topTenOldestQuery, topTenOldestAliveQuery) into the widget-ready
 * shapes the Templates/LifeSpan.phtml partials consume.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class LifeSpanRepository
{
    /**
     * 10-year buckets up to "100+" — caps the histogram so the
     * long-tail outliers (a documented 110-year-old) don't stretch
     * the x-axis. Anything ≥ 100 collapses onto the 100+ bucket.
     */
    private const int BUCKET_WIDTH = 10;

    private const int MAX_BUCKET = 100;

    /**
     * Minimum per-cohort sample size for the
     * lifespan-by-sex × century LineChart. A century × sex group
     * with fewer than this many dated deaths gets suppressed
     * (rendered as zero / "no data" tooltip) so a 1-sample
     * outlier doesn't drag the cohort mean.
     */
    private const int MIN_COHORT_SIZE = 5;

    /**
     * Living-individual age-band cut-offs. The buckets are designed
     * to read as life-stages (minor / young adult / working age /
     * retired) rather than equal-width decades.
     *
     * @var list<array{label: string, max: int|null}>
     */
    private const array LIVING_AGE_BANDS = [
        ['label' => '0–17', 'max' => 17],
        ['label' => '18–35', 'max' => 35],
        ['label' => '36–65', 'max' => 65],
        ['label' => '65+', 'max' => null],
    ];

    /**
     * @param Tree           $tree The tree the statistics are computed for
     * @param StatisticsData $data Core accessor that already exposes the queries we need
     */
    public function __construct(
        private Tree $tree,
        private StatisticsData $data,
    ) {
    }

    /**
     * Age-at-death distribution bucketed into 10-year bands plus a
     * "100+" overflow. Empty buckets are kept in the output so the
     * histogram renders a continuous x-axis without gaps.
     *
     * @return array<string, int>
     */
    public function ageAtDeathDistribution(): array
    {
        $rows = $this->data->statsAgeQuery('ALL', 0, 0);

        $buckets = [];

        for ($age = 0; $age < self::MAX_BUCKET; $age += self::BUCKET_WIDTH) {
            $buckets[$this->bucketLabel($age)] = 0;
        }

        $buckets[$this->overflowLabel()] = 0;

        foreach ($rows as $row) {
            $days = $row->days;

            if ($days < 0) {
                continue;
            }

            $years = intdiv($days, 365);
            $label = $years >= self::MAX_BUCKET
                ? $this->overflowLabel()
                : $this->bucketLabel(intdiv($years, self::BUCKET_WIDTH) * self::BUCKET_WIDTH);

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Births grouped by decade across the entire tree — the
     * "Stammbaum-Wachstum" indicator for the TreeHealth tab. Each
     * BIRT date contributes one tick to its decade bucket. Leading
     * and trailing zero-decades are trimmed via
     * {@see HistogramTrim::dropZeroEnds()} so the visible range
     * starts at the first decade with a recorded birth and ends at
     * the last; inner zero-decades stay so a gap in the recorded
     * history remains visible.
     *
     * Decade keys are integer starts (e.g. `1900` for the 1900s);
     * the display layer renders them through `I18N::translate('%ss', $decade)`
     * so the suffix follows the user's locale ("1900s", "1900er", …).
     *
     * @return array<int, int>
     */
    public function birthsByDecade(): array
    {
        $rows = DB::table('dates')
            ->where('d_file', '=', $this->tree->id())
            ->where('d_fact', '=', 'BIRT')
            ->whereIn('d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
            ->where('d_year', '<>', 0)
            ->select(['d_year'])
            ->get();

        $byDecade = [];

        foreach ($rows as $row) {
            $year = is_numeric($row->d_year ?? null) ? (int) $row->d_year : 0;

            if ($year === 0) {
                continue;
            }

            $decade            = intdiv($year, 10) * 10;
            $byDecade[$decade] = ($byDecade[$decade] ?? 0) + 1;
        }

        if ($byDecade === []) {
            return [];
        }

        // Fill internal zero-decades so the histogram renders the
        // gaps as zero bars rather than collapsing them. Iterate
        // from the first to the last seen decade in 10-year steps.
        ksort($byDecade);
        $decadeKeys = array_keys($byDecade);
        $firstDec   = $decadeKeys[0];
        $lastDec    = $decadeKeys[array_key_last($decadeKeys)];
        $dense      = [];

        for ($d = $firstDec; $d <= $lastDec; $d += 10) {
            $dense[$d] = $byDecade[$d] ?? 0;
        }

        return HistogramTrim::dropZeroEnds($dense);
    }

    /**
     * Winter-peak indicator for deaths — relative density of
     * December + January + February death events compared to a
     * perfectly-even 12-month baseline. Returns null when fewer
     * than 12 dated deaths are recorded (below that threshold the
     * score is too noisy to be meaningful).
     */
    public function deathWinterPeakScore(): ?WinterPeakScore
    {
        return SeasonalityScore::score(
            $this->data->countEventsByMonth('DEAT', 0, 0),
            SeasonalityScore::NORTHERN_WINTER,
            12,
        );
    }

    /**
     * Top-N oldest deceased individuals across the tree, formatted
     * as {label: "Given Surname (years)", value: years}.
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return array<string, int>
     */
    public function topOldestDeceased(int $limit): array
    {
        return $this->shapeOldest(
            $this->data->topTenOldestQuery('ALL', $limit),
        );
    }

    /**
     * Top-N oldest living individuals across the tree. Same
     * shape as {@see topOldestDeceased()} — age is the difference
     * between today and the BIRT date.
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return array<string, int>
     */
    public function topOldestLiving(int $limit): array
    {
        // `topTenOldestAliveQuery` returns Individual objects directly
        // (not the `{individual, days}` shape `topTenOldestQuery` uses)
        // — the query has no DEAT date to compute age from, so we
        // derive age from today minus BIRT julianday ourselves.
        $today          = getdate();
        $todayJulianDay = GregorianToJD($today['mon'], $today['mday'], $today['year']);
        $out            = [];

        foreach ($this->data->topTenOldestAliveQuery('ALL', $limit) as $individual) {
            $birthDate = $individual->getBirthDate();

            if (!$birthDate->isOK()) {
                continue;
            }

            $birthJd = $birthDate->minimumJulianDay();

            if ($birthJd <= 0) {
                continue;
            }

            $years                              = intdiv($todayJulianDay - $birthJd, 365);
            $out[$this->plainName($individual)] = $years;
        }

        return $out;
    }

    /**
     * Single oldest-deceased record holder: the individual with the
     * largest DEAT − BIRT julian-day delta, capped at 120 years so
     * a single typo cannot win the slot. Used by the Hall-of-Fame
     * widget on the Overview tab.
     *
     * @return array{individual: Individual, ageYears: int}|null
     */
    public function oldestDeceasedRecord(): ?array
    {
        $maxAge = $this->maxPlausibleAge();

        // Walk top-N until a plausible age (≤ MAX_ALIVE_AGE) wins;
        // data-entry typos like "DEAT 9999" otherwise steal the
        // slot. Scan depth 10 is enough — real outliers always
        // have a real record-holder just below them.
        foreach ($this->data->topTenOldestQuery('ALL', 10) as $entry) {
            $individual = $entry->individual ?? null;
            $days       = $entry->days ?? 0;

            if (!$individual instanceof Individual) {
                continue;
            }

            $years = intdiv($days, 365);

            if ($years <= 0) {
                continue;
            }

            if ($years > $maxAge) {
                continue;
            }

            return ['individual' => $individual, 'ageYears' => $years];
        }

        return null;
    }

    /**
     * Single oldest-living record holder: the individual without a
     * recorded DEAT whose BIRT julian-day is earliest in the tree,
     * capped at 120 years to discard implausible BIRT typos.
     *
     * @return array{individual: Individual, ageYears: int}|null
     */
    public function oldestLivingRecord(): ?array
    {
        $today          = getdate();
        $todayJulianDay = GregorianToJD($today['mon'], $today['mday'], $today['year']);
        $maxAge         = $this->maxPlausibleAge();

        // Same walk-until-plausible loop as oldestDeceasedRecord —
        // an obviously-wrong BIRT year cannot drag the slot empty.
        foreach ($this->data->topTenOldestAliveQuery('ALL', 10) as $individual) {
            $birthDate = $individual->getBirthDate();

            if (!$birthDate->isOK()) {
                continue;
            }

            $birthJd = $birthDate->minimumJulianDay();

            if ($birthJd <= 0) {
                continue;
            }

            $years = intdiv($todayJulianDay - $birthJd, 365);

            if ($years <= 0) {
                continue;
            }

            if ($years > $maxAge) {
                continue;
            }

            return ['individual' => $individual, 'ageYears' => $years];
        }

        return null;
    }

    /**
     * Mean lifespan grouped by birth-century × sex. Returns the
     * standard `{categories, series}` shape so it can feed straight
     * into the chart-lib LineChart multi-series render path —
     * categories are the localised century labels in chronological
     * order, two series (Male / Female). Per-cohort sample counts
     * below {@see MIN_COHORT_SIZE} are suppressed (replaced with
     * zero) so a single outlier doesn't dominate the visual.
     *
     * @return array{
     *     categories: list<string>,
     *     series: list<array{name: string, values: list<float>, tooltips: list<string>, tooltipLabels: list<string>, class: string}>
     * }
     */
    public function averageLifespanBySexAndCentury(): array
    {
        $maxAge = $this->maxPlausibleAge();

        // Per-birth-century × sex aggregation. Day-totals divided
        // by sample counts produce the cohort mean; both numerator
        // and denominator live in PHP because MySQL's per-row
        // century classification (floor((d_year - 1) / 100) + 1)
        // would clutter the SQL more than the PHP fold does.
        $rows = BirthDeathPairsQuery::for($this->tree)
            ->whereIn('i_sex', ['M', 'F'])
            ->select([
                'i_sex AS sex',
                'birth.d_year AS birth_year',
                'birth.d_julianday1 AS birth_jd',
                'death.d_julianday1 AS death_jd',
            ])
            ->get();

        /** @var array<int, array{M: array{sum: int, n: int}, F: array{sum: int, n: int}}> $cohorts */
        $cohorts = [];

        foreach ($rows as $row) {
            $year = is_numeric($row->birth_year ?? null) ? (int) $row->birth_year : 0;

            if ($year <= 0) {
                continue;
            }

            $sex     = $row->sex === 'F' ? 'F' : 'M';
            $birthJd = is_numeric($row->birth_jd ?? null) ? (int) $row->birth_jd : 0;
            $deathJd = is_numeric($row->death_jd ?? null) ? (int) $row->death_jd : 0;

            if ($birthJd <= 0) {
                continue;
            }

            if ($deathJd <= $birthJd) {
                continue;
            }

            $years = intdiv($deathJd - $birthJd, 365);

            if ($years <= 0) {
                continue;
            }

            if ($years > $maxAge) {
                continue;
            }

            // $year is already > 0 (guarded earlier), so the
            // century derivation cannot be non-positive — no
            // second guard needed.
            $century = intdiv($year - 1, 100) + 1;

            $cohorts[$century] ??= ['M' => ['sum' => 0, 'n' => 0], 'F' => ['sum' => 0, 'n' => 0]];
            $cohorts[$century][$sex]['sum'] += $years;
            ++$cohorts[$century][$sex]['n'];
        }

        if ($cohorts === []) {
            return ['categories' => [], 'series' => []];
        }

        ksort($cohorts);

        $categories          = [];
        $maleValues          = [];
        $femaleValues        = [];
        $maleTooltips        = [];
        $femaleTooltips      = [];
        $maleTooltipLabels   = [];
        $femaleTooltipLabels = [];

        foreach ($cohorts as $century => $perSex) {
            // Drop centuries where neither sex meets the cohort-
            // size floor — otherwise the x-axis would lead with
            // empty "15th: 0 / 16th: 0" entries that carry no
            // information.
            if (
                $perSex['M']['n'] < self::MIN_COHORT_SIZE
                && $perSex['F']['n'] < self::MIN_COHORT_SIZE
            ) {
                continue;
            }

            $label        = CenturyName::for($century);
            $categories[] = $label;

            $maleAverage = $perSex['M']['n'] >= self::MIN_COHORT_SIZE
                ? round($perSex['M']['sum'] / $perSex['M']['n'], 1)
                : 0;
            $femaleAverage = $perSex['F']['n'] >= self::MIN_COHORT_SIZE
                ? round($perSex['F']['sum'] / $perSex['F']['n'], 1)
                : 0;
            $maleValues[]   = $maleAverage;
            $femaleValues[] = $femaleAverage;

            $maleTooltips[] = $perSex['M']['n'] >= self::MIN_COHORT_SIZE
                ? I18N::translate(
                    '%1$s years (n = %2$s)',
                    I18N::number($maleAverage, 1),
                    I18N::number($perSex['M']['n']),
                )
                : I18N::translate('no data (n < %s)', I18N::number(self::MIN_COHORT_SIZE));
            $femaleTooltips[] = $perSex['F']['n'] >= self::MIN_COHORT_SIZE
                ? I18N::translate(
                    '%1$s years (n = %2$s)',
                    I18N::number($femaleAverage, 1),
                    I18N::number($perSex['F']['n']),
                )
                : I18N::translate('no data (n < %s)', I18N::number(self::MIN_COHORT_SIZE));

            $longLabel             = CenturyName::longLabel($label);
            $maleTooltipLabels[]   = $longLabel;
            $femaleTooltipLabels[] = $longLabel;
        }

        return [
            'categories' => $categories,
            'series'     => [
                [
                    'name'          => I18N::translate('Male'),
                    'values'        => $maleValues,
                    'tooltips'      => $maleTooltips,
                    'tooltipLabels' => $maleTooltipLabels,
                    'class'         => 'male',
                ],
                [
                    'name'          => I18N::translate('Female'),
                    'values'        => $femaleValues,
                    'tooltips'      => $femaleTooltips,
                    'tooltipLabels' => $femaleTooltipLabels,
                    'class'         => 'female',
                ],
            ],
        ];
    }

    /**
     * Per-tree plausibility ceiling for "max lifespan" / "max age
     * still considered alive" — webtrees keeps this as the
     * `MAX_ALIVE_AGE` preference on every tree (default 120).
     * Falls back to 120 when the preference is missing or
     * non-numeric so a fresh-import tree without preferences
     * still produces sensible records.
     */
    private function maxPlausibleAge(): int
    {
        $pref = $this->tree->getPreference('MAX_ALIVE_AGE');

        if (!is_numeric($pref)) {
            return 120;
        }

        $value = (int) $pref;

        return $value > 0 ? $value : 120;
    }

    /**
     * Living-individual count grouped by life-stage age-band. The
     * `data-widget=donut` partial reads this as
     * `[{label, value, class}]`; the `class` slot is wired through
     * to the SVG slice so the CSS palette can colour them
     * consistently with the existing donut widgets.
     *
     * @return list<array{label: string, value: int, class: string}>
     */
    public function livingByAgeBand(): array
    {
        $rows = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->whereNotExists(static function (Builder $query): void {
                $query
                    ->from('dates')
                    ->whereColumn('d_file', '=', 'i_file')
                    ->whereColumn('d_gid', '=', 'i_id')
                    ->where('d_fact', '=', 'DEAT');
            })
            ->join('dates AS birth', static function (JoinClause $join): void {
                $join
                    ->on('birth.d_file', '=', 'i_file')
                    ->on('birth.d_gid', '=', 'i_id')
                    ->where('birth.d_fact', '=', 'BIRT')
                    ->whereIn('birth.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);
            })
            ->select([
                new Expression(
                    'FLOOR((' . $this->julianTodayExpression()
                    . ' - ' . DB::connection()->getTablePrefix() . 'birth.d_julianday1) / 365) AS age',
                ),
            ])
            ->get();

        $bandCounts = array_fill_keys(
            array_map(static fn (array $b): string => $b['label'], self::LIVING_AGE_BANDS),
            0,
        );

        foreach ($rows as $row) {
            $rawAge = is_numeric($row->age) ? (int) $row->age : 0;
            $age    = max(0, $rawAge);
            ++$bandCounts[$this->bandLabel($age)];
        }

        $palette = ['age-band-0', 'age-band-1', 'age-band-2', 'age-band-3'];
        $entries = [];
        $index   = 0;

        foreach ($bandCounts as $label => $count) {
            $entries[] = [
                'label' => $label,
                'value' => $count,
                'class' => $palette[$index] ?? 'age-band-default',
            ];
            ++$index;
        }

        return $entries;
    }

    /**
     * @param iterable<object> $individuals Core query result (Individual collection).
     *
     * @return array<string, int>
     */
    private function shapeOldest(iterable $individuals): array
    {
        $out = [];

        foreach ($individuals as $entry) {
            $individual = $entry->individual ?? null;
            $rawDays    = $entry->days ?? 0;
            $days       = is_numeric($rawDays) ? (int) $rawDays : 0;

            if (!$individual instanceof Individual) {
                continue;
            }

            $years                              = intdiv($days, 365);
            $out[$this->plainName($individual)] = $years;
        }

        return $out;
    }

    /**
     * Plain-text full name suitable for dropping into a
     * progress-bar aria-label. Delegates to module-base's
     * {@see NameProcessor::getFullName()}, which strips the HTML
     * `<span class="NAME">…</span>` wrappers that webtrees'
     * `Individual::fullName()` emits and would otherwise tear
     * apart a double-quoted attribute value.
     */
    private function plainName(Individual $individual): string
    {
        return (new NameProcessor($individual))->getFullName();
    }

    /**
     * Map a 0-indexed age value to the life-stage label whose
     * `max` is the smallest cap that still contains it.
     */
    private function bandLabel(int $age): string
    {
        foreach (self::LIVING_AGE_BANDS as $band) {
            if ($band['max'] === null || $age <= $band['max']) {
                return $band['label'];
            }
        }

        return self::LIVING_AGE_BANDS[array_key_last(self::LIVING_AGE_BANDS)]['label'];
    }

    /**
     * "0–9", "10–19", … for a given lower-bound age.
     */
    private function bucketLabel(int $lowerAge): string
    {
        return $lowerAge . '–' . ($lowerAge + self::BUCKET_WIDTH - 1);
    }

    /**
     * Overflow bucket label for ages ≥ MAX_BUCKET.
     */
    private function overflowLabel(): string
    {
        return self::MAX_BUCKET . '+';
    }

    /**
     * SQL expression that yields today's Julian-day-number for the
     * current calendar date. Lives in a helper so the driver
     * difference (SQLite vs MySQL/MariaDB) stays in one place.
     */
    private function julianTodayExpression(): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "CAST(julianday('now') AS INTEGER)";
        }

        return 'TO_DAYS(CURDATE()) + 1721060';
    }
}
