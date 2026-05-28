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
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Model\Metric\WinterPeakScore;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualAgeRecord;
use MagicSunday\Webtrees\Statistic\Support\Calc\HistogramTrim;
use MagicSunday\Webtrees\Statistic\Support\Database\BirthDeathPairsQuery;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;

use function array_fill_keys;
use function array_key_last;
use function array_map;
use function count;
use function getdate;
use function gregoriantojd;
use function intdiv;
use function is_numeric;
use function ksort;
use function max;
use function round;

/**
 * Life-span aggregations for the LifeSpan tab. Wraps the public
 * accessors core's {@see StatisticsData} exposes (statsAgeQuery,
 * topTenOldestQuery, topTenOldestAliveQuery) into the widget-ready
 * shapes the tabs/life-span.phtml partials consume.
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
     * Tree-wide fallback for the per-tree `MAX_ALIVE_AGE` preference
     * — webtrees defaults to 120 and so do we when the preference
     * cannot be read or parsed.
     */
    private const int DEFAULT_MAX_ALIVE_AGE = 120;

    /**
     * Minimum per-cohort sample size for the
     * lifespan-by-sex × century LineChart. A century × sex group
     * with fewer than this many dated deaths gets suppressed
     * (rendered as zero / "no data" tooltip) so a 1-sample
     * outlier doesn't drag the cohort mean.
     */
    private const int MIN_COHORT_SIZE = 5;

    /**
     * Minimum per-cohort sample size for the survival-function
     * chart. A century with fewer recorded BIRT+DEAT pairs is
     * dropped from the chart entirely — a 20-person cohort already
     * lets a single death move the survival rate by 5 percentage
     * points, and below that the curve reads as noise.
     */
    private const int MIN_COHORT_SIZE_SURVIVAL = 30;

    /**
     * Age thresholds (in years) at which the survival fraction is
     * sampled. 0 is included so every cohort starts at 100 %; 100
     * caps the centenarian tail at one anchor regardless of the
     * documented extremes in the underlying data.
     *
     * @var list<int>
     */
    private const array SURVIVAL_AGE_THRESHOLDS = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

    /**
     * Minimum recorded-deaths sample below which the
     * {@see deathWinterPeakScore()} returns null because the
     * baseline-to-season ratio is too noisy to be meaningful at
     * lower sample sizes. 12 = at least one death per calendar
     * month on average.
     */
    private const int WINTER_PEAK_MIN_SAMPLE = 12;

    /**
     * Northern-hemisphere winter months as a `[month => true]` hash
     * for O(1) lookup. Map shape (vs a list) lets the foreach in
     * {@see deathWinterPeakScore()} test membership via `isset`
     * without paying for an `in_array` linear scan per iteration.
     */
    private const array WINTER_MONTHS = ['DEC' => true, 'JAN' => true, 'FEB' => true];

    /**
     * The twelve valid GEDCOM month codes. Acts as a whitelist when
     * tallying deaths-by-month rows so individuals without a dated
     * BIRT/DEAT month (returned by core under an empty string key)
     * don't inflate the seasonality baseline.
     */
    private const array GEDCOM_MONTHS = [
        'JAN' => true, 'FEB' => true, 'MAR' => true, 'APR' => true,
        'MAY' => true, 'JUN' => true, 'JUL' => true, 'AUG' => true,
        'SEP' => true, 'OCT' => true, 'NOV' => true, 'DEC' => true,
    ];

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
        $rows = TreeScope::table($this->tree, 'dates')
            ->where('d_fact', '=', 'BIRT')
            ->whereIn('d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
            ->where('d_year', '<>', 0)
            ->select(['d_year'])
            ->get();

        $byDecade = [];

        foreach ($rows as $row) {
            $year = RowCast::int($row, 'd_year');

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
     * Age-at-death samples grouped by birth century. Each entry
     * carries the raw integer ages so the consumer (typically the
     * chart-lib BoxPlot widget) can compute quartiles, whiskers and
     * outliers itself. Sub-day fractions are dropped via
     * `intdiv(deathJd − birthJd, 365)`; non-positive ages, ages
     * above the tree's plausible-age cap, and BCE birth years are
     * filtered out.
     *
     * Cohorts below {@see self::MIN_COHORT_SIZE} samples are skipped
     * — a five-number summary on a 1-person cohort is noise.
     *
     * @return list<array{century: int, values: list<int>, n: int}>
     */
    public function deathAgeDistributionByCentury(): array
    {
        $maxAge = $this->maxPlausibleAge();

        $rows = BirthDeathPairsQuery::for($this->tree)
            ->select($this->aggregatedPairColumns())
            ->groupBy('individuals.i_id')
            ->get();

        /** @var array<int, list<int>> $cohorts */
        $cohorts = [];

        foreach ($rows as $row) {
            $cohort = $this->birthDeathCohortOrNull($row, $maxAge);

            if ($cohort === null) {
                continue;
            }

            // Box-plot summary excludes zero-year lifespans for the
            // same reason as the mean-lifespan sibling — a five-number
            // summary built on a same-year-as-birth row would carry
            // no information. Survival-curve keeps these rows since
            // they anchor the age-0 denominator.
            if ($cohort['years'] === 0) {
                continue;
            }

            $cohorts[$cohort['century']][] = $cohort['years'];
        }

        if ($cohorts === []) {
            return [];
        }

        ksort($cohorts);
        $out = [];

        foreach ($cohorts as $century => $ages) {
            $n = count($ages);

            if ($n < self::MIN_COHORT_SIZE) {
                continue;
            }

            $out[] = [
                'century' => $century,
                'values'  => $ages,
                'n'       => $n,
            ];
        }

        return $out;
    }

    /**
     * Running cumulative population: for each decade in the visible
     * birth window, the total number of individuals born up to and
     * including that decade. Layers a running sum on top of the
     * existing {@see birthsByDecade()} payload — same decade keys,
     * same trimmed window, monotonically non-decreasing values.
     *
     * Empty when no dated birth exists.
     *
     * Decade keys are integer starts (e.g. `1900` for the 1900s);
     * the display layer renders them through `I18N::translate('%ss', $decade)`.
     *
     * @return array<int, int>
     */
    public function cumulativeBirthsByDecade(): array
    {
        $byDecade = $this->birthsByDecade();

        if ($byDecade === []) {
            return [];
        }

        $running    = 0;
        $cumulative = [];

        foreach ($byDecade as $decade => $count) {
            $running += $count;
            $cumulative[$decade] = $running;
        }

        return $cumulative;
    }

    /**
     * Winter-peak indicator for deaths — relative density of
     * December + January + February death events compared to a
     * perfectly-even 12-month baseline. Returns null when fewer
     * than {@see self::WINTER_PEAK_MIN_SAMPLE} dated deaths are
     * recorded (below that threshold the score is too noisy to
     * be meaningful).
     *
     * Score = (winterDeaths / 3) / (totalDeaths / 12); 1.0 means
     * the season carries its proportional share, > 1.0 means an
     * actual winter peak, < 1.0 means winter is under-represented.
     */
    public function deathWinterPeakScore(): ?WinterPeakScore
    {
        $monthCounts = $this->data->countEventsByMonth('DEAT', 0, 0);

        // Restrict both numerator and denominator to dated deaths —
        // core's countEventsByMonth returns an empty-string key for
        // deaths without a parseable month, which would otherwise
        // inflate $total and drag the seasonality score downward.
        $total       = 0;
        $winterCount = 0;

        foreach ($monthCounts as $month => $count) {
            if (!isset(self::GEDCOM_MONTHS[$month])) {
                continue;
            }

            $total += $count;

            if (isset(self::WINTER_MONTHS[$month])) {
                $winterCount += $count;
            }
        }

        if ($total < self::WINTER_PEAK_MIN_SAMPLE) {
            return null;
        }

        $score = round(($winterCount / 3) / ($total / 12), 2);

        return new WinterPeakScore(score: $score, seasonCount: $winterCount, total: $total);
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
        $todayJulianDay = gregoriantojd($today['mon'], $today['mday'], $today['year']);
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
     */
    public function oldestDeceasedRecord(): ?IndividualAgeRecord
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

            return new IndividualAgeRecord(individual: $individual, ageYears: $years);
        }

        return null;
    }

    /**
     * Single oldest-living record holder: the individual without a
     * recorded DEAT whose BIRT julian-day is earliest in the tree,
     * capped at 120 years to discard implausible BIRT typos.
     */
    public function oldestLivingRecord(): ?IndividualAgeRecord
    {
        $today          = getdate();
        $todayJulianDay = gregoriantojd($today['mon'], $today['mday'], $today['year']);
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

            return new IndividualAgeRecord(individual: $individual, ageYears: $years);
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
     */
    public function averageLifespanBySexAndCentury(): LineChartPayload
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
                ...$this->aggregatedPairColumns(),
            ])
            ->groupBy('individuals.i_id', 'individuals.i_sex')
            ->get();

        /** @var array<int, array{M: array{sum: int, n: int}, F: array{sum: int, n: int}}> $cohorts */
        $cohorts = [];

        foreach ($rows as $row) {
            $cohort = $this->birthDeathCohortOrNull($row, $maxAge);

            if ($cohort === null) {
                continue;
            }

            // Mean-lifespan excludes rows that round to zero years
            // (any lifespan under 365 days) because their `years`
            // contribution would drag the numerator without adding
            // to the lived-years total. The survival-curve sibling
            // keeps these rows since they count at the age-0 anchor.
            if ($cohort['years'] === 0) {
                continue;
            }

            $sex = $row->sex === 'F' ? 'F' : 'M';

            $cohorts[$cohort['century']] ??= ['M' => ['sum' => 0, 'n' => 0], 'F' => ['sum' => 0, 'n' => 0]];
            $cohorts[$cohort['century']][$sex]['sum'] += $cohort['years'];
            ++$cohorts[$cohort['century']][$sex]['n'];
        }

        if ($cohorts === []) {
            return new LineChartPayload(categories: [], series: []);
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
            $categories[] = CenturyName::compactLabel($label);

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

        return new LineChartPayload(
            categories: $categories,
            series: [
                new LineChartSeries(
                    name: I18N::translate('Male'),
                    values: $maleValues,
                    tooltips: $maleTooltips,
                    tooltipLabels: $maleTooltipLabels,
                    class: 'male',
                ),
                new LineChartSeries(
                    name: I18N::translate('Female'),
                    values: $femaleValues,
                    tooltips: $femaleTooltips,
                    tooltipLabels: $femaleTooltipLabels,
                    class: 'female',
                ),
            ],
        );
    }

    /**
     * Survival curve per birth century. For every individual with a
     * recorded BIRT+DEAT pair, derives the lifespan in whole years
     * and counts at each {@see SURVIVAL_AGE_THRESHOLDS} threshold
     * how many cohort members reached at least that age. The
     * fraction is rendered as a percentage relative to the cohort
     * size at age 0 (the full count of individuals with both
     * events recorded), so every series starts at 100 % and falls
     * monotonically. Centuries below
     * {@see MIN_COHORT_SIZE_SURVIVAL} are dropped entirely — a
     * 20-person cohort already lets one death shift a threshold by
     * 5 percentage points.
     */
    public function survivalFunctionByCentury(): LineChartPayload
    {
        $maxAge = $this->maxPlausibleAge();

        $rows = BirthDeathPairsQuery::for($this->tree)
            ->select($this->aggregatedPairColumns())
            ->groupBy('individuals.i_id')
            ->get();

        /** @var array<int, array{size: int, ages: list<int>}> $cohorts */
        $cohorts = [];

        foreach ($rows as $row) {
            $cohort = $this->birthDeathCohortOrNull($row, $maxAge);

            if ($cohort === null) {
                continue;
            }

            $cohorts[$cohort['century']] ??= ['size' => 0, 'ages' => []];
            ++$cohorts[$cohort['century']]['size'];
            $cohorts[$cohort['century']]['ages'][] = $cohort['years'];
        }

        if ($cohorts === []) {
            return new LineChartPayload(categories: [], series: []);
        }

        ksort($cohorts);

        $categories = array_map(
            static fn (int $age): string => (string) $age,
            self::SURVIVAL_AGE_THRESHOLDS,
        );

        $series = [];

        foreach ($cohorts as $century => $cohort) {
            if ($cohort['size'] < self::MIN_COHORT_SIZE_SURVIVAL) {
                continue;
            }

            $values        = [];
            $tooltips      = [];
            $tooltipLabels = [];

            foreach (self::SURVIVAL_AGE_THRESHOLDS as $threshold) {
                $survivors = 0;

                foreach ($cohort['ages'] as $age) {
                    if ($age >= $threshold) {
                        ++$survivors;
                    }
                }

                $share           = round(($survivors / $cohort['size']) * 100, 1);
                $values[]        = $share;
                $tooltipLabels[] = I18N::translate('Age %s', I18N::number($threshold));
                $tooltips[]      = I18N::translate(
                    '%1$s %% (%2$s of %3$s individuals reached this age)',
                    I18N::number($share, 1),
                    I18N::number($survivors),
                    I18N::number($cohort['size']),
                );
            }

            $series[] = new LineChartSeries(
                name: CenturyName::compactLabel(CenturyName::for($century)),
                values: $values,
                tooltips: $tooltips,
                tooltipLabels: $tooltipLabels,
            );
        }

        if ($series === []) {
            return new LineChartPayload(categories: [], series: []);
        }

        return new LineChartPayload(
            categories: $categories,
            series: $series,
        );
    }

    /**
     * Column set every cohort query selects on top of
     * {@see BirthDeathPairsQuery} so the per-individual aggregation
     * stays consistent across `deathAgeDistributionByCentury`,
     * `averageLifespanBySexAndCentury` and `survivalFunctionByCentury`.
     *
     * Webtrees writes TWO rows into the `dates` table for every
     * BET..AND / FROM..TO date (one per range bound), so a JOIN
     * without `GROUP BY individuals.i_id` would see the same
     * individual twice and double-count them. Aggregating the
     * earliest BIRT julian-day with `MIN(birth.d_julianday1)` and
     * the latest DEAT julian-day with `MAX(death.d_julianday2)`
     * collapses the doubled rows into a single per-individual
     * lifespan; the convention also matches webtrees core's
     * `death.d_julianday2 - birth.d_julianday1` idiom (see
     * `StatisticsData::averageLifespan*` queries) which produces
     * the maximum-possible lifespan for year-only / modifier rows
     * instead of the lower-bound figure the inline `d_julianday1`
     * pair returned previously.
     *
     * @return list<Expression<non-falsy-string>>
     */
    private function aggregatedPairColumns(): array
    {
        return [
            DateAggregate::min('birth', 'd_year', 'birth_year'),
            DateAggregate::min('birth', 'd_julianday1', 'birth_jd'),
            DateAggregate::max('death', 'd_julianday2', 'death_jd'),
        ];
    }

    /**
     * Decode one row from {@see BirthDeathPairsQuery} into a
     * `[century, years]` tuple, applying every date-validity guard
     * that every cohort metric on this repository shares. Returns
     * null when the row should be skipped — non-positive birth
     * year, missing julian-day anchors, death-before-birth, or a
     * lifespan above the plausibility ceiling. Caller-side filters
     * (e.g. drop zero-day lifespans for cohort means) sit on top
     * of the tuple value.
     *
     * @return array{century: int, years: int}|null
     */
    private function birthDeathCohortOrNull(object $row, int $maxAge): ?array
    {
        $year = RowCast::int($row, 'birth_year');

        if ($year <= 0) {
            return null;
        }

        $birthJd = RowCast::int($row, 'birth_jd');
        $deathJd = RowCast::int($row, 'death_jd');

        if ($birthJd <= 0) {
            return null;
        }

        if ($deathJd <= $birthJd) {
            return null;
        }

        $years = intdiv($deathJd - $birthJd, 365);

        if ($years < 0) {
            return null;
        }

        if ($years > $maxAge) {
            return null;
        }

        return [
            'century' => CenturyName::fromYear($year),
            'years'   => $years,
        ];
    }

    /**
     * Per-tree plausibility ceiling for "max lifespan" / "max age
     * still considered alive" — webtrees keeps this as the
     * `MAX_ALIVE_AGE` preference on every tree (default 120).
     * Falls back to {@see self::DEFAULT_MAX_ALIVE_AGE} when the
     * preference is missing or non-numeric so a fresh-import tree
     * without preferences still produces sensible records.
     */
    private function maxPlausibleAge(): int
    {
        $pref = $this->tree->getPreference('MAX_ALIVE_AGE');

        if (!is_numeric($pref)) {
            return self::DEFAULT_MAX_ALIVE_AGE;
        }

        $value = (int) $pref;

        return $value > 0 ? $value : self::DEFAULT_MAX_ALIVE_AGE;
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
        $rows = TreeScope::table($this->tree, 'individuals')
            ->whereNotExists(static function (Builder $query): void {
                $query
                    ->from('dates')
                    ->whereColumn('d_file', '=', 'i_file')
                    ->whereColumn('d_gid', '=', 'i_id')
                    ->where('d_fact', '=', 'DEAT');
            })
            ->join('dates AS birth', static function (JoinClause $join): void {
                DateJoin::on($join, 'birth', 'i_file', 'i_id', 'BIRT');
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
            $rawAge = RowCast::int($row, 'age');
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
