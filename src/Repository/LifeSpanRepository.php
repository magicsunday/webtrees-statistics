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
use MagicSunday\Webtrees\Statistic\Model\Heatmap\HeatmapPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Model\Metric\WinterPeakScore;
use MagicSunday\Webtrees\Statistic\Model\Mortality\MortalityAnomaly;
use MagicSunday\Webtrees\Statistic\Model\Pyramid\PopulationPyramidPayload;
use MagicSunday\Webtrees\Statistic\Model\Ranking\RankingEntry;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualAgeRecord;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventMonthTally;
use MagicSunday\Webtrees\Statistic\Support\Calc\HistogramTrim;
use MagicSunday\Webtrees\Statistic\Support\Calc\MortalityAnomalies;
use MagicSunday\Webtrees\Statistic\Support\Database\BirthDeathPairsQuery;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use MagicSunday\Webtrees\Statistic\Support\Locale\HistoricalEventCatalog;
use MagicSunday\Webtrees\Statistic\Support\Locale\IsoCountryMap;
use MagicSunday\Webtrees\Statistic\Support\Locale\MonthName;

use function array_column;
use function array_fill_keys;
use function array_key_last;
use function array_keys;
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
 * Life-span aggregations for the LifeSpan tab. Wraps the public accessors
 * core's {@see StatisticsData} exposes (statsAgeQuery, topTenOldestQuery,
 * topTenOldestAliveQuery) into the widget-ready shapes the tabs/life-span.phtml
 * partials consume.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class LifeSpanRepository
{
    /**
     * 10-year buckets up to "100+" — caps the histogram so the long-tail
     * outliers (a documented 110-year-old) don't stretch the x-axis. Anything ≥
     * 100 collapses onto the 100+ bucket.
     */
    private const int BUCKET_WIDTH = 10;

    private const int MAX_BUCKET = 100;

    /**
     * Tree-wide fallback for the per-tree `MAX_ALIVE_AGE` preference — webtrees
     * defaults to 120 and so do we when the preference cannot be read or
     * parsed.
     */
    private const int DEFAULT_MAX_ALIVE_AGE = 120;

    /**
     * Minimum per-cohort sample size for the lifespan-by-sex × century
     * LineChart. A century × sex group with fewer than this many dated deaths
     * gets suppressed (rendered as zero / "no data" tooltip) so a 1-sample
     * outlier doesn't drag the cohort mean.
     */
    private const int MIN_COHORT_SIZE = 5;

    /**
     * Minimum per-cohort sample size for the survival-function chart. A century
     * with fewer recorded BIRT+DEAT pairs is dropped from the chart entirely —
     * a 20-person cohort already lets a single death move the survival rate by
     * 5 percentage points, and below that the curve reads as noise.
     */
    private const int MIN_COHORT_SIZE_SURVIVAL = 30;

    /**
     * Age thresholds (in years) at which the survival fraction is sampled. 0 is
     * included so every cohort starts at 100 %; 100 caps the centenarian tail
     * at one anchor regardless of the documented extremes in the underlying
     * data.
     *
     * @var list<int>
     */
    private const array SURVIVAL_AGE_THRESHOLDS = [0, 10, 20, 30, 40, 50, 60, 70, 80, 90, 100];

    /**
     * Minimum recorded-deaths sample below which the {@see
     * deathWinterPeakScore()} returns null because the baseline-to-season ratio
     * is too noisy to be meaningful at lower sample sizes. 12 = at least one
     * death per calendar month on average.
     */
    private const int WINTER_PEAK_MIN_SAMPLE = 12;

    /**
     * Northern-hemisphere winter months as a `[month => true]` hash for O(1)
     * lookup. Map shape (vs a list) lets the foreach in {@see
     * deathWinterPeakScore()} test membership via `isset` without paying for an
     * `in_array` linear scan per iteration.
     */
    private const array WINTER_MONTHS = ['DEC' => true, 'JAN' => true, 'FEB' => true];

    /**
     * The twelve valid GEDCOM month codes. Acts as a whitelist when tallying
     * deaths-by-month rows so individuals without a dated BIRT/DEAT month
     * (returned by core under an empty string key) don't inflate the
     * seasonality baseline.
     */
    private const array GEDCOM_MONTHS = [
        'JAN' => true, 'FEB' => true, 'MAR' => true, 'APR' => true,
        'MAY' => true, 'JUN' => true, 'JUL' => true, 'AUG' => true,
        'SEP' => true, 'OCT' => true, 'NOV' => true, 'DEC' => true,
    ];

    /**
     * Living-individual age-band cut-offs. The buckets are designed to read as
     * life-stages (minor / young adult / working age / retired) rather than
     * equal-width decades.
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
     * Minimum datable death places a country must have in an anomaly year for
     * that year to be annotated with the country's historical events — a single
     * emigrant record must not pull in its host country's events.
     */
    private const int MIN_DEATHS_PER_COUNTRY = 2;

    /**
     * @param Tree           $tree   The tree the statistics are computed for
     * @param StatisticsData $data   Core accessor that already exposes the queries we need
     * @param IsoCountryMap  $isoMap Resolves death-place strings to ISO-3166-1 alpha-2 country codes
     */
    public function __construct(
        private Tree $tree,
        private StatisticsData $data,
        private IsoCountryMap $isoMap,
    ) {
    }

    /**
     * Age-at-death distribution bucketed into 10-year bands plus a "100+"
     * overflow. Empty buckets are kept in the output so the histogram renders a
     * continuous x-axis without gaps.
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
     * "Stammbaum-Wachstum" indicator for the TreeHealth tab. Each BIRT date
     * contributes one tick to its decade bucket. Leading and trailing
     * zero-decades are trimmed via {@see HistogramTrim::dropZeroEnds()} so the
     * visible range starts at the first decade with a recorded birth and ends
     * at the last; inner zero-decades stay so a gap in the recorded history
     * remains visible.
     *
     * Decade keys are integer starts (e.g. `1900` for the 1900s); the display
     * layer renders them through `I18N::translate('%ss', $decade)` so the
     * suffix follows the user's locale ("1900s", "1900er", …).
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
     * Years whose recorded death count stands out against the surrounding
     * baseline — the statistical fingerprint of an epidemic, war or famine
     * year. Death counts are aggregated per year, then
     * {@see MortalityAnomalies::detect()} compares each year against its
     * rolling 11-year window: the window median is the expected baseline, and a
     * year is flagged when its standard score reaches `$zScoreThreshold`. Only
     * years with a full window on both sides are considered, so the most recent
     * and oldest years are never falsely flagged. The anomalies are selected by
     * descending standard score, capped at `$topN`, and returned chronologically.
     *
     * Each anomaly is then annotated with the broadly-documented historical
     * events it coincides with ({@see HistoricalEventCatalog}), correlated via
     * the countries the year's death places resolve to: only a country with at
     * least {@see MIN_DEATHS_PER_COUNTRY} distinct individuals dying there that
     * year counts, so a single emigrant record cannot pull in its host
     * country's events.
     *
     * @param float $zScoreThreshold Minimum standard score for a year to count as an anomaly
     * @param int   $topN            Maximum number of anomaly years to return
     *
     * @return list<MortalityAnomaly>
     */
    public function mortalityAnomalies(float $zScoreThreshold = 2.0, int $topN = 10): array
    {
        // Count distinct individuals per year: webtrees stores two `dates` rows
        // for an imprecise date (its lower and upper bound), so a row count would
        // double an individual whose death is recorded as ABT / BET … AND … .
        $rows = TreeScope::table($this->tree, 'dates')
            ->where('d_fact', '=', 'DEAT')
            ->whereIn('d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
            ->where('d_year', '>', 0)
            ->groupBy('d_year')
            ->select(['d_year', new Expression('COUNT(DISTINCT d_gid) AS deaths')])
            ->get();

        $deathsByYear = [];

        foreach ($rows as $row) {
            $year = RowCast::int($row, 'd_year');

            if ($year <= 0) {
                continue;
            }

            $deathsByYear[$year] = RowCast::int($row, 'deaths');
        }

        $detected        = MortalityAnomalies::detect($deathsByYear, $zScoreThreshold, $topN);
        $countriesByYear = $this->deathPlaceCountriesByYear(array_column($detected, 'year'));

        $anomalies = [];

        foreach ($detected as $anomaly) {
            $countries = $countriesByYear[$anomaly['year']] ?? [];

            $anomalies[] = new MortalityAnomaly(
                year: $anomaly['year'],
                deaths: $anomaly['deaths'],
                baseline: $anomaly['baseline'],
                multiplier: $anomaly['multiplier'],
                zScore: $anomaly['zScore'],
                events: HistoricalEventCatalog::labelsFor($anomaly['year'], $countries),
            );
        }

        return $anomalies;
    }

    /**
     * The ISO-3166-1 alpha-2 countries that each of the given years' death
     * places resolve to, restricted to countries with at least
     * {@see MIN_DEATHS_PER_COUNTRY} *distinct individuals* whose death place that
     * year resolves to the country. Counting individuals (not `dates` rows)
     * keeps an imprecise date — for which webtrees stores two bound rows — from
     * crossing the threshold on its own. Death places are read from each
     * individual's GEDCOM (the `dates` table does not carry the place) and
     * resolved via {@see IsoCountryMap::resolveFromPlace()}. Years with no
     * qualifying country are omitted.
     *
     * Resolution relies on the death place carrying a recognisable country
     * segment ("…, Germany"). A place recorded as bare "City, State" without a
     * country (common in some regions) does not resolve to its country, so that
     * year simply stays unannotated rather than being mis-attributed.
     *
     * @param list<int> $years The anomaly years to resolve
     *
     * @return array<int, list<string>> Year → list of qualifying ISO-3166-1 alpha-2 codes
     */
    private function deathPlaceCountriesByYear(array $years): array
    {
        if ($years === []) {
            return [];
        }

        $rows = TreeScope::table($this->tree, 'dates')
            ->where('d_fact', '=', 'DEAT')
            ->whereIn('d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
            ->whereIn('d_year', $years)
            ->join('individuals', static function (JoinClause $join): void {
                $join
                    ->on('i_file', '=', 'd_file')
                    ->on('i_id', '=', 'd_gid');
            })
            ->select(['d_year', 'd_gid', 'i_gedcom'])
            ->get();

        // [year][iso2][individualXref => true] — the inner set deduplicates the
        // two date-bound rows webtrees writes for an imprecise death date.
        /** @var array<int, array<string, array<string, bool>>> $individuals */
        $individuals = [];

        foreach ($rows as $row) {
            $year  = RowCast::int($row, 'd_year');
            $place = GedcomScanner::extractEventPlace(RowCast::string($row, 'i_gedcom'), 'DEAT');

            if ($place === null) {
                continue;
            }

            $iso2 = $this->isoMap->resolveFromPlace($place);

            if ($iso2 === null) {
                continue;
            }

            $individuals[$year][$iso2][RowCast::string($row, 'd_gid')] = true;
        }

        $countriesByYear = [];

        foreach ($individuals as $year => $isoIndividuals) {
            $countries = [];

            foreach ($isoIndividuals as $iso2 => $individualSet) {
                if (count($individualSet) >= self::MIN_DEATHS_PER_COUNTRY) {
                    $countries[] = $iso2;
                }
            }

            if ($countries !== []) {
                $countriesByYear[$year] = $countries;
            }
        }

        return $countriesByYear;
    }

    /**
     * Age-at-death samples grouped by birth century. Each entry carries the raw
     * integer ages so the consumer (typically the chart-lib BoxPlot widget) can
     * compute quartiles, whiskers and outliers itself. Sub-day fractions are
     * dropped via `intdiv(deathJd − birthJd, 365)`; non-positive ages, ages
     * above the tree's plausible-age cap, and BCE birth years are filtered out.
     *
     * Cohorts below {@see self::MIN_COHORT_SIZE} samples are skipped — a
     * five-number summary on a 1-person cohort is noise.
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
     * Running cumulative population: for each decade in the visible birth
     * window, the total number of individuals born up to and including that
     * decade. Layers a running sum on top of the existing {@see
     * birthsByDecade()} payload — same decade keys, same trimmed window,
     * monotonically non-decreasing values.
     *
     * Empty when no dated birth exists.
     *
     * Decade keys are integer starts (e.g. `1900` for the 1900s); the display
     * layer renders them through `I18N::translate('%ss', $decade)`.
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
     * Winter-peak indicator for deaths — relative density of December + January
     * + February death events compared to a perfectly-even 12-month baseline.
     * Returns null when fewer than {@see self::WINTER_PEAK_MIN_SAMPLE} dated
     * deaths are recorded (below that threshold the score is too noisy to be
     * meaningful).
     *
     * Score = (winterDeaths / 3) / (totalDeaths / 12); 1.0 means the season
     * carries its proportional share, > 1.0 means an actual winter peak, < 1.0
     * means winter is under-represented.
     */
    public function deathWinterPeakScore(): ?WinterPeakScore
    {
        $monthCounts = EventMonthTally::countByMonth($this->tree, 'DEAT');

        // The tally already drops month-less deaths and counts each
        // deceased individual once (a ranged DEAT would otherwise split
        // across two months and inflate the baseline), so the GEDCOM_MONTHS
        // whitelist below is now a defensive guard rather than the filter
        // that keeps the seasonality numerator and denominator aligned.
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
     * Top-N oldest deceased individuals across the tree. Each row carries the
     * XREF, display name and age in years, so two same-named individuals stay
     * distinct.
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return list<RankingEntry>
     */
    public function topOldestDeceased(int $limit): array
    {
        return $this->shapeOldest(
            $this->data->topTenOldestQuery('ALL', $limit),
        );
    }

    /**
     * Top-N oldest living individuals across the tree. Same shape as {@see
     * topOldestDeceased()} — age is the difference between today and the BIRT
     * date.
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return list<RankingEntry>
     */
    public function topOldestLiving(int $limit): array
    {
        // `topTenOldestAliveQuery` returns Individual objects directly
        // (not the `{individual, days}` shape `topTenOldestQuery` uses)
        // — the query has no DEAT date to compute age from, so we
        // derive age from today minus BIRT julianday ourselves.
        //
        // The core query applies its row cap BEFORE this privacy/parse filter,
        // so a single unparseable birth date among the oldest rows would leave
        // the card one entry short. Over-fetch a margin and stop once $limit
        // valid entries are collected (the query is already oldest-first).
        $today          = getdate();
        $todayJulianDay = gregoriantojd($today['mon'], $today['mday'], $today['year']);
        $entries        = [];

        $overFetch = ($limit > 0) ? ($limit * 3) : $limit;

        foreach ($this->data->topTenOldestAliveQuery('ALL', $overFetch) as $individual) {
            $birthDate = $individual->getBirthDate();

            if (!$birthDate->isOK()) {
                continue;
            }

            $birthJd = $birthDate->minimumJulianDay();

            if ($birthJd <= 0) {
                continue;
            }

            $years     = intdiv($todayJulianDay - $birthJd, 365);
            $entries[] = new RankingEntry($individual->xref(), $this->plainName($individual), $years);

            if (($limit > 0) && (count($entries) >= $limit)) {
                break;
            }
        }

        return $entries;
    }

    /**
     * Single oldest-deceased record holder: the individual with the largest
     * DEAT − BIRT julian-day delta, capped at 120 years so a single typo cannot
     * win the slot. Used by the Hall-of-Fame widget on the Overview tab.
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
     * Single oldest-living record holder: the individual without a recorded
     * DEAT whose BIRT julian-day is earliest in the tree, capped at 120 years
     * to discard implausible BIRT typos.
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
     * Mean lifespan grouped by birth-century × sex. Returns the standard
     * `{categories, series}` shape so it can feed straight into the chart-lib
     * LineChart multi-series render path — categories are the localised century
     * labels in chronological order, two series (Male / Female). Per-cohort
     * sample counts below {@see MIN_COHORT_SIZE} are suppressed (replaced with
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
     * Survival curve per birth century. For every individual with a recorded
     * BIRT+DEAT pair, derives the lifespan in whole years and counts at each
     * {@see SURVIVAL_AGE_THRESHOLDS} threshold how many cohort members reached
     * at least that age. The fraction is rendered as a percentage relative to
     * the cohort size at age 0 (the full count of individuals with both events
     * recorded), so every series starts at 100 % and falls monotonically.
     * Centuries below {@see MIN_COHORT_SIZE_SURVIVAL} are dropped entirely — a
     * 20-person cohort already lets one death shift a threshold by 5 percentage
     * points.
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
     * Age-at-death distribution split by sex, faceted by birth century — the
     * payload behind the population-pyramid widget. Reuses the exact cohort
     * definition the box-plot sibling ({@see deathAgeDistributionByCentury()})
     * applies: one BIRT+DEAT pair per individual, birth-century classification,
     * age in whole years capped at the tree's plausibility ceiling. Unlike the
     * cohort-mean and box-plot metrics, zero-year lifespans are KEPT — an infant
     * death belongs in the youngest band and carries real demographic signal in
     * a pyramid.
     *
     * Bands are emitted oldest-first (100+ at the top) so the chart reads as a
     * conventional pyramid with the youngest cohort at the base. Centuries with
     * no qualifying death never enter the output, so the picker only ever lists
     * populated columns. An empty tree yields empty axes, which the widget
     * renders as its empty state.
     */
    public function deathsByCenturyAgeBandSex(): PopulationPyramidPayload
    {
        $maxAge = $this->maxPlausibleAge();

        $rows = BirthDeathPairsQuery::for($this->tree)
            ->whereIn('i_sex', ['M', 'F'])
            ->select([
                'i_sex AS sex',
                ...$this->aggregatedPairColumns(),
            ])
            ->groupBy('individuals.i_id', 'individuals.i_sex')
            ->get();

        $bands = $this->ageBandLabels();

        // Two flat `[century][band] => count` tallies kept separate per sex.
        // Building the `{m, f}` cells as literals at the end (rather than
        // mutating a pre-filled map through a dynamic band key) keeps the
        // static array{m: int, f: int} shape the payload constructor expects.
        /** @var array<int, array<string, int>> $male */
        $male = [];
        /** @var array<int, array<string, int>> $female */
        $female = [];
        /** @var array<int, bool> $seenCenturies */
        $seenCenturies = [];

        foreach ($rows as $row) {
            $cohort = $this->birthDeathCohortOrNull($row, $maxAge);

            if ($cohort === null) {
                continue;
            }

            $century = $cohort['century'];
            $band    = $this->ageBandLabel($cohort['years']);

            $seenCenturies[$century] = true;

            if ($row->sex === 'F') {
                $female[$century][$band] = ($female[$century][$band] ?? 0) + 1;
            } else {
                $male[$century][$band] = ($male[$century][$band] ?? 0) + 1;
            }
        }

        if ($seenCenturies === []) {
            return new PopulationPyramidPayload(groups: [], bands: [], data: []);
        }

        ksort($seenCenturies);

        $centuries = [];
        $data      = [];

        foreach ($seenCenturies as $century => $_present) {
            $centuries[] = CenturyName::compactLabel(CenturyName::for($century));

            $column = [];

            foreach ($bands as $band) {
                // Map the sex tally onto the widget's neutral two-sided cell:
                // male → left, female → right (the LifeSpan card pins the
                // captions to match).
                $column[] = [
                    'left'  => $male[$century][$band] ?? 0,
                    'right' => $female[$century][$band] ?? 0,
                ];
            }

            $data[] = $column;
        }

        return new PopulationPyramidPayload(
            groups: $centuries,
            bands: $bands,
            data: $data,
        );
    }

    /**
     * Events of one fact type faceted by period × calendar month — feeds the
     * chart-lib heatmap on the LifeSpan tab. Each GREGORIAN / JULIAN date with a
     * known year and month contributes one tick to the `period × (month − 1)`
     * cell, where a period is a fixed 25-year span (a quarter-century) so the
     * grid stays compact however far back the tree reaches.
     *
     * Rows are dense from the first to the last period that carries any event
     * (25-year steps); inner empty periods stay as all-zero rows so a gap in the
     * recorded history reads as a blank band rather than collapsing. Leading and
     * trailing empty periods never appear because a period row is only seeded
     * once an event lands in it. Row labels are the plain period-start year
     * ("1900", "1925", …); columns are the abbreviated month names, with the
     * full names carried alongside for the tooltip.
     *
     * @param string $fact GEDCOM fact tag to tally (e.g. `BIRT`, `DEAT`)
     *
     * @return HeatmapPayload Period rows × twelve month columns of event counts
     */
    public function eventHeatmapByPeriodMonth(string $fact): HeatmapPayload
    {
        $periodYears = 25;

        // Bound the year on BOTH sides so a single out-of-range date can't blow
        // the dense period-fill into hundreds of empty rows. `d_year > 0`
        // excludes the year-0 sentinel and BCE (negative) years; `d_year <=`
        // this year excludes future dates — a non-physical birth/death year, and
        // crucially the upper bound a `BET … AND <far-future>` range writes as a
        // second `dates` row (webtrees stores both range bounds). `d_mon BETWEEN
        // 1 AND 12` keeps only dates with a real calendar month, so the foreach
        // below trusts the bounds and adds no redundant in-PHP re-checks.
        $currentYear = getdate()['year'];

        $rows = TreeScope::table($this->tree, 'dates')
            ->where('d_fact', '=', $fact)
            ->whereIn('d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
            ->where('d_year', '>', 0)
            ->where('d_year', '<=', $currentYear)
            ->where('d_mon', '>=', 1)
            ->where('d_mon', '<=', 12)
            ->select(['d_year', 'd_mon'])
            ->get();

        /** @var array<int, array<int, int>> $byPeriodMonth Period start year → month index (0-11) → count */
        $byPeriodMonth = [];

        foreach ($rows as $row) {
            $year  = RowCast::int($row, 'd_year');
            $month = RowCast::int($row, 'd_mon');

            $period     = intdiv($year, $periodYears) * $periodYears;
            $monthIndex = $month - 1;

            $byPeriodMonth[$period][$monthIndex] = ($byPeriodMonth[$period][$monthIndex] ?? 0) + 1;
        }

        if ($byPeriodMonth === []) {
            return new HeatmapPayload(rows: [], cols: [], values: []);
        }

        ksort($byPeriodMonth);
        $periodKeys  = array_keys($byPeriodMonth);
        $firstPeriod = $periodKeys[0];
        $lastPeriod  = $periodKeys[array_key_last($periodKeys)];

        $rowLabels = [];
        $values    = [];

        for ($period = $firstPeriod; $period <= $lastPeriod; $period += $periodYears) {
            $rowLabels[] = (string) $period;

            $monthCounts = $byPeriodMonth[$period] ?? [];
            $rowValues   = [];

            for ($monthIndex = 0; $monthIndex < 12; ++$monthIndex) {
                $rowValues[] = $monthCounts[$monthIndex] ?? 0;
            }

            $values[] = $rowValues;
        }

        return new HeatmapPayload(
            rows: $rowLabels,
            cols: MonthName::abbreviated(),
            values: $values,
            colTitles: MonthName::ordered(),
        );
    }

    /**
     * Column set every cohort query selects on top of {@see
     * BirthDeathPairsQuery} so the per-individual aggregation stays consistent
     * across `deathAgeDistributionByCentury`, `averageLifespanBySexAndCentury`
     * and `survivalFunctionByCentury`.
     *
     * Webtrees writes TWO rows into the `dates` table for every BET..AND /
     * FROM..TO date (one per range bound), so a JOIN without `GROUP BY
     * individuals.i_id` would see the same individual twice and double-count
     * them. Aggregating the earliest BIRT julian-day with
     * `MIN(birth.d_julianday1)` and the latest DEAT julian-day with
     * `MAX(death.d_julianday2)` collapses the doubled rows into a single
     * per-individual lifespan; the convention also matches webtrees core's
     * `death.d_julianday2 - birth.d_julianday1` idiom (see
     * `StatisticsData::averageLifespan*` queries) which produces the
     * maximum-possible lifespan for year-only / modifier rows instead of the
     * lower-bound figure the inline `d_julianday1` pair returned previously.
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
     * Decode one row from {@see BirthDeathPairsQuery} into a `[century, years]`
     * tuple, applying every date-validity guard that every cohort metric on
     * this repository shares. Returns null when the row should be skipped —
     * non-positive birth year, missing julian-day anchors, death-before-birth,
     * or a lifespan above the plausibility ceiling. Caller-side filters (e.g.
     * drop zero-day lifespans for cohort means) sit on top of the tuple value.
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
     * Per-tree plausibility ceiling for "max lifespan" / "max age still
     * considered alive" — webtrees keeps this as the `MAX_ALIVE_AGE` preference
     * on every tree (default 120). Falls back to {@see
     * self::DEFAULT_MAX_ALIVE_AGE} when the preference is missing or
     * non-numeric so a fresh-import tree without preferences still produces
     * sensible records.
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
     * `data-widget=donut` partial reads this as `[{label, value, class}]`; the
     * `class` slot is wired through to the SVG slice so the CSS palette can
     * colour them consistently with the existing donut widgets.
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
     * @param iterable<object> $individuals Core query result ({individual, days} rows).
     *
     * @return list<RankingEntry>
     */
    private function shapeOldest(iterable $individuals): array
    {
        $entries = [];

        foreach ($individuals as $entry) {
            $individual = $entry->individual ?? null;
            $rawDays    = $entry->days ?? 0;
            $days       = is_numeric($rawDays) ? (int) $rawDays : 0;

            if (!$individual instanceof Individual) {
                continue;
            }

            $years     = intdiv($days, 365);
            $entries[] = new RankingEntry($individual->xref(), $this->plainName($individual), $years);
        }

        return $entries;
    }

    /**
     * Plain-text full name suitable for dropping into a progress-bar
     * aria-label. Delegates to module-base's {@see
     * NameProcessor::getFullName()}, which strips the HTML `<span
     * class="NAME">…</span>` wrappers that webtrees' `Individual::fullName()`
     * emits and would otherwise tear apart a double-quoted attribute value.
     */
    private function plainName(Individual $individual): string
    {
        return (new NameProcessor($individual))->getFullName();
    }

    /**
     * Map a 0-indexed age value to the life-stage label whose `max` is the
     * smallest cap that still contains it.
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
     * The full age-at-death band axis in oldest-first order — "100+", "90–99",
     * …, "0–9" — so the population pyramid stacks the oldest cohort at the top
     * and the youngest at the base. Mirrors the bucketing of {@see
     * ageAtDeathDistribution()} so the two LifeSpan widgets bin identically.
     *
     * @return list<string>
     */
    private function ageBandLabels(): array
    {
        $labels = [$this->overflowLabel()];

        for ($age = self::MAX_BUCKET - self::BUCKET_WIDTH; $age >= 0; $age -= self::BUCKET_WIDTH) {
            $labels[] = $this->bucketLabel($age);
        }

        return $labels;
    }

    /**
     * Map a whole-year age at death onto its {@see ageBandLabels()} band,
     * collapsing everything at or beyond {@see MAX_BUCKET} onto the "100+"
     * overflow band.
     */
    private function ageBandLabel(int $years): string
    {
        if ($years >= self::MAX_BUCKET) {
            return $this->overflowLabel();
        }

        return $this->bucketLabel(intdiv($years, self::BUCKET_WIDTH) * self::BUCKET_WIDTH);
    }

    /**
     * Overflow bucket label for ages ≥ MAX_BUCKET.
     */
    private function overflowLabel(): string
    {
        return self::MAX_BUCKET . '+';
    }

    /**
     * SQL expression that yields today's Julian-day-number for the current
     * calendar date. Lives in a helper so the driver difference (SQLite vs
     * MySQL/MariaDB) stays in one place.
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
