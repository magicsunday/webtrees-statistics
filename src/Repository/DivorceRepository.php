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
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Enum\Sex;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarPayload;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarSeries;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventCenturyTally;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventMonthTally;
use MagicSunday\Webtrees\Statistic\Support\Calc\AgeBuckets;
use MagicSunday\Webtrees\Statistic\Support\Calc\CalendarSpan;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;

use function array_key_last;
use function array_keys;
use function array_slice;
use function intdiv;
use function ksort;
use function max;
use function round;

/**
 * Divorce-related aggregations for the Family tab. Built on the same join chain
 * core uses for marriage stats but anchored on `1 DIV` events. `1 DIVF`
 * (Divorce Filed) is intentionally excluded — same anchoring rule the marital
 * classifier uses.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DivorceRepository
{
    private const int AGE_AT_DIVORCE_BUCKET = 10;

    private const int AGE_AT_DIVORCE_MAX = 80;

    private const int AGE_AT_DIVORCE_TYPO_CAP = 110;

    /**
     * Five life-stage age bands plus an Unknown catch-all so the per-century
     * totals of `divorcesByCenturyAndAgeBand` stay equal to `divorcesByCentury`
     * even when BIRT records are sparse. The Unknown row carries the literal
     * English name so the const stays constant-expression-only; the legend
     * translates it via `I18N::translate(...)` at series-build time.
     *
     * The `I18N::translate('Unknown')` call below registers the key for static
     * extractors that scan literal arguments — the runtime translation happens
     * through `$band['name']` in the series-build loop.
     *
     * @see I18N::translate('Unknown')
     */
    private const array DIVORCE_AGE_BANDS = [
        ['name' => '0–9', 'class' => 'age-band-0', 'lo' => 0, 'hi' => 9],
        ['name' => '10–19', 'class' => 'age-band-1', 'lo' => 10, 'hi' => 19],
        ['name' => '20–29', 'class' => 'age-band-2', 'lo' => 20, 'hi' => 29],
        ['name' => '30–39', 'class' => 'age-band-3', 'lo' => 30, 'hi' => 39],
        ['name' => '40–49', 'class' => 'age-band-4', 'lo' => 40, 'hi' => 49],
        ['name' => '50–59', 'class' => 'age-band-5', 'lo' => 50, 'hi' => 59],
        ['name' => '60–69', 'class' => 'age-band-6', 'lo' => 60, 'hi' => 69],
        ['name' => '70–79', 'class' => 'age-band-7', 'lo' => 70, 'hi' => 79],
        ['name' => '80–89', 'class' => 'age-band-8', 'lo' => 80, 'hi' => 89],
        ['name' => '90+', 'class' => 'age-band-9', 'lo' => 90, 'hi' => self::AGE_AT_DIVORCE_TYPO_CAP],
        ['name' => 'Unknown', 'class' => 'age-band-unknown', 'lo' => null, 'hi' => null],
    ];

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Divorces grouped by century, keyed by the localised ordinal label. Counts
     * each family once even when its DIV is a range date (stored as two `dates`
     * rows).
     *
     * @return array<string, int>
     */
    public function divorcesByCentury(): array
    {
        return EventCenturyTally::countByCentury($this->tree, 'DIV');
    }

    /**
     * Divorces grouped by GEDCOM month code. Counts each family once even when
     * its DIV is a month-spanning range date (stored as two `dates` rows).
     *
     * @return array<string, int>
     */
    public function divorcesByMonth(): array
    {
        return EventMonthTally::countByMonth($this->tree, 'DIV');
    }

    /**
     * Age-at-divorce histogram for one sex (5-year bands up to 80+).
     *
     * @param string $sex 'M' for husband, 'F' for wife
     *
     * @return array<string, int>
     */
    public function ageAtDivorceDistribution(string $sex): array
    {
        $spouseColumn = Sex::from($sex)->spouseColumn();

        // Ranged DIV / BIRT dates produce two `dates` rows per anchor,
        // so a FAM with a ranged DIV or a ranged spouse BIRT would
        // surface multiple times with slightly different julian-day
        // pairs. Grouping by `families.f_id` and aggregating each
        // anchor with `MIN(d_julianday1)` collapses the duplicates
        // onto the lower-bound julian day; one row per FAM.
        $rows = TreeScope::table($this->tree, 'families')
            ->join('dates AS divr', static function (JoinClause $join): void {
                DateJoin::on($join, 'divr', 'f_file', 'f_id', 'DIV');
            })
            ->join('dates AS birth', static function (JoinClause $join) use ($spouseColumn): void {
                DateJoin::on($join, 'birth', 'f_file', $spouseColumn, 'BIRT', DateJoin::JD_NOT_EQUAL_ZERO);
            })
            ->select([
                DateAggregate::min('divr', 'd_julianday1', 'div_jd'),
                DateAggregate::min('birth', 'd_julianday1', 'birth_jd'),
            ])
            ->groupBy('families.f_id')
            ->get();

        $buckets = AgeBuckets::init(0, self::AGE_AT_DIVORCE_MAX, self::AGE_AT_DIVORCE_BUCKET);

        foreach ($rows as $row) {
            $divJd   = RowCast::int($row, 'div_jd');
            $birthJd = RowCast::int($row, 'birth_jd');

            if ($divJd <= 0) {
                continue;
            }

            if ($birthJd <= 0) {
                continue;
            }

            if ($divJd <= $birthJd) {
                continue;
            }

            $years = CalendarSpan::wholeYears($birthJd, $divJd);
            $label = AgeBuckets::label($years, self::AGE_AT_DIVORCE_MAX, self::AGE_AT_DIVORCE_BUCKET);

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Divorces cross-tabulated by divorce century and age-at-divorce band.
     * Returns the unified `{categories, series}` payload so the result feeds
     * straight into the chart-lib StackedBar widget. Categories are the
     * localised century labels of the DIV event in chronological order; series
     * are the age bands (`0–24`, `25–34`, `35–44`, `45–54`, `55+`), each
     * carrying one count per century. The bands are coarser than the
     * `ageAtDivorceDistribution` 5-year buckets — a 10-band stack reads as
     * visual noise, the broader life-stage bands let the "younger / older at
     * divorce" story come through.
     *
     * Counts one tick per divorce so the per-century totals match the
     * `divorcesByCentury` LineChart side-by-side. The husband's BIRT classifies
     * the cohort when present; the wife's BIRT is the fallback when his is
     * missing. Divorces with no usable BIRT on either spouse, with a BIRT that
     * places the spouse after the divorce, or with an age outside the [0, 110]
     * sanity window fall into a sixth "Unknown" band so the per-century totals
     * stay equal to `divorcesByCentury` even on sparsely dated trees.
     */
    public function divorcesByCenturyAndAgeBand(): StackedBarPayload
    {
        // Match core's `countEventsByCentury` reach — accept DIV
        // rows with any positive year, including ones that lack a
        // resolvable julian day (year-only DATEs). Such rows can't
        // contribute an age but they still count in the line chart,
        // so the Unknown catch-all keeps the totals aligned.
        // Ranged DIV / BIRT dates produce two `dates` rows per anchor,
        // so a FAM with ranged anchors on all three columns could
        // produce up to 2^3 = 8 rows in the JOIN. Grouping by
        // `families.f_id` and aggregating each anchor with
        // `MIN(d_julianday1)` collapses the duplicates onto the
        // lower-bound julian day. `MIN(divr.d_year)` keeps the
        // century classification aligned with the picked DIV anchor.
        $rows = TreeScope::table($this->tree, 'families')
            ->join('dates AS divr', static function (JoinClause $join): void {
                DateJoin::on($join, 'divr', 'f_file', 'f_id', 'DIV');
            })
            ->leftJoin('dates AS hb', static function (JoinClause $join): void {
                DateJoin::on($join, 'hb', 'f_file', 'f_husb', 'BIRT', DateJoin::JD_GREATER_THAN_ZERO);
            })
            ->leftJoin('dates AS wb', static function (JoinClause $join): void {
                DateJoin::on($join, 'wb', 'f_file', 'f_wife', 'BIRT', DateJoin::JD_GREATER_THAN_ZERO);
            })
            ->select([
                DateAggregate::min('divr', 'd_year', 'div_year'),
                DateAggregate::min('divr', 'd_julianday1', 'div_jd'),
                DateAggregate::min('hb', 'd_julianday1', 'hb_jd'),
                DateAggregate::min('wb', 'd_julianday1', 'wb_jd'),
            ])
            ->groupBy('families.f_id')
            ->get();

        $unknownBandIndex = array_key_last(self::DIVORCE_AGE_BANDS);

        /** @var array<int, array<int, int>> $cohorts century => bandIndex => count */
        $cohorts = [];

        foreach ($rows as $row) {
            $divYear = RowCast::int($row, 'div_year');
            $divJd   = RowCast::int($row, 'div_jd');

            if ($divYear <= 0) {
                continue;
            }

            $century = CenturyName::fromYear($divYear);

            // Husband first, wife fallback — one tick per divorce
            // so the per-century totals match `divorcesByCentury`
            // exactly. Counting both spouses would render twice the
            // sample size and confuse cross-card comparison.
            $birthJd = RowCast::int($row, 'hb_jd');

            if ($birthJd <= 0) {
                $birthJd = RowCast::int($row, 'wb_jd');
            }

            $classified = false;

            // Require divorce strictly after birth — an inverted (typo) pair
            // would otherwise read as a plausible positive age via the
            // order-independent span and land in a real band instead of the
            // Unknown bucket. Mirrors the guard in ageAtDivorceDistribution().
            if (($divJd > 0) && ($birthJd > 0) && ($divJd > $birthJd)) {
                $years = CalendarSpan::wholeYears($birthJd, $divJd);

                if ($years <= self::AGE_AT_DIVORCE_TYPO_CAP) {
                    foreach (self::DIVORCE_AGE_BANDS as $bandIndex => $band) {
                        if ($bandIndex === $unknownBandIndex) {
                            continue;
                        }

                        if (($years >= $band['lo']) && ($years <= $band['hi'])) {
                            $cohorts[$century][$bandIndex] = ($cohorts[$century][$bandIndex] ?? 0) + 1;
                            $classified                    = true;

                            break;
                        }
                    }
                }
            }

            if (!$classified) {
                $cohorts[$century][$unknownBandIndex] = ($cohorts[$century][$unknownBandIndex] ?? 0) + 1;
            }
        }

        if ($cohorts === []) {
            return new StackedBarPayload(categories: [], tooltipLabels: [], series: []);
        }

        ksort($cohorts);

        $categories    = [];
        $tooltipLabels = [];

        foreach (array_keys($cohorts) as $century) {
            $short           = CenturyName::for($century);
            $categories[]    = $short;
            $tooltipLabels[] = CenturyName::longLabel($short);
        }

        $series = [];

        foreach (self::DIVORCE_AGE_BANDS as $bandIndex => $band) {
            $values = [];

            foreach ($cohorts as $perBand) {
                $values[] = $perBand[$bandIndex] ?? 0;
            }

            // Keep every band in the result — a band with zero
            // counts everywhere still belongs in the legend so the
            // reader sees the full age scale and understands which
            // life stage is absent from the recorded divorces. The
            // Unknown name is the only translatable label; the age-
            // range labels stay locale-neutral.
            $displayName = $bandIndex === $unknownBandIndex
                ? I18N::translate($band['name'])
                : $band['name'];
            $series[] = new StackedBarSeries(
                name: $displayName,
                data: $values,
                class: $band['class'],
            );
        }

        return new StackedBarPayload(
            categories: $categories,
            tooltipLabels: $tooltipLabels,
            series: $series,
        );
    }

    /**
     * Divorce rate per marriage cohort. Cohort = decade of MARR event; rate =
     * `divorced / total` within that decade. Output is keyed by integer decade
     * start (1900, 1910, …); the value is a fraction 0.0–1.0 rounded to 4
     * decimals. The display layer renders the suffix via
     * `I18N::translate('%ss', $cohort)`.
     *
     * Three filters keep the result tight on real trees that span many
     * centuries:
     *
     *  1. Adaptive sample threshold: cohorts with fewer than
     *     `max(3, total_marriages / 100)` marriages drop out — at
     *     that size the rate is dominated by noise.
     *  2. Leading / trailing cohorts where divorced == 0 drop out
     *     so the visible range starts at the first cohort with a
     *     divorce and ends at the last.
     *  3. Inner cohorts with divorced == 0 stay so a quiet decade
     *     between two active ones is visible as a gap.
     *
     * @return array<int, float>
     */
    public function divorceRateByMarriageCohort(): array
    {
        // Ranged MARR / DIV dates produce two `dates` rows per anchor,
        // so a FAM with a ranged MARR or DIV would surface multiple
        // times and inflate both `total` and `divorced` within the
        // affected cohort. Grouping by `families.f_id` and
        // aggregating each year with `MIN(d_year)` collapses the
        // duplicates onto the lower-bound year so each FAM
        // contributes exactly one tick to its decade cohort.
        $rows = TreeScope::table($this->tree, 'families')
            ->join('dates AS marr', static function (JoinClause $join): void {
                DateJoin::on($join, 'marr', 'f_file', 'f_id', 'MARR');
                $join->where('marr.d_year', '<>', 0);
            })
            ->leftJoin('dates AS divr', static function (JoinClause $join): void {
                $join
                    ->on('divr.d_file', '=', 'f_file')
                    ->on('divr.d_gid', '=', 'f_id')
                    ->where('divr.d_fact', '=', 'DIV');
            })
            ->select([
                DateAggregate::min('marr', 'd_year', 'marr_year'),
                DateAggregate::min('divr', 'd_year', 'div_year'),
            ])
            ->groupBy('families.f_id')
            ->get();

        $perCohort = [];

        foreach ($rows as $row) {
            $marrYear = RowCast::int($row, 'marr_year');

            if ($marrYear === 0) {
                continue;
            }

            $cohort = intdiv($marrYear, 10) * 10;

            if (!isset($perCohort[$cohort])) {
                $perCohort[$cohort] = ['total' => 0, 'divorced' => 0];
            }

            ++$perCohort[$cohort]['total'];

            if (($row->div_year ?? null) !== null) {
                ++$perCohort[$cohort]['divorced'];
            }
        }

        ksort($perCohort);

        // Adaptive sample threshold: 1% of total marriages, floored at 3.
        $totalMarriages = 0;

        foreach ($perCohort as $tally) {
            $totalMarriages += $tally['total'];
        }

        $threshold = max(3, intdiv($totalMarriages, 100));

        // Identify the cohort window — first / last cohort that BOTH
        // passes the sample threshold AND saw at least one divorce.
        // Everything between those two anchors stays in the window,
        // INCLUDING cohorts that didn't pass the threshold and
        // cohorts with rate == 0. That preserves the gap-visibility
        // user intent (a quiet decade between two active ones is
        // informative, dropping it would lie about the timeline).
        $keys        = array_keys($perCohort);
        $firstAnchor = null;
        $lastAnchor  = null;

        foreach ($keys as $index => $key) {
            $tally = $perCohort[$key];

            if ($tally['total'] < $threshold) {
                continue;
            }

            if ($tally['divorced'] === 0) {
                continue;
            }

            $firstAnchor ??= $index;
            $lastAnchor = $index;
        }

        if (($firstAnchor === null) || ($lastAnchor === null)) {
            return [];
        }

        $window = array_slice(
            $perCohort,
            $firstAnchor,
            ($lastAnchor - $firstAnchor) + 1,
            true,
        );

        $rates = [];

        foreach ($window as $cohort => $tally) {
            $rates[$cohort] = round($tally['divorced'] / $tally['total'], 4);
        }

        return $rates;
    }
}
