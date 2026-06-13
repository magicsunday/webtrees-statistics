<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Enum\Sex;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventCenturyTally;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventMonthTally;
use MagicSunday\Webtrees\Statistic\Support\Calc\AgeBuckets;
use MagicSunday\Webtrees\Statistic\Support\Calc\CalendarSpan;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

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

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Divorces grouped by century, keyed by the signed 1-based century number
     * (negative for BCE). Counts each family once even when its DIV is a range
     * date (stored as two `dates` rows).
     *
     * @return array<int, int>
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
                DateAggregate::min('marr', 'd_type', 'marr_type'),
                DateAggregate::min('marr', 'd_julianday1', 'marr_jd'),
                DateAggregate::min('marr', 'd_year', 'marr_year'),
                DateAggregate::min('divr', 'd_year', 'div_year'),
            ])
            ->groupBy('families.f_id')
            ->get();

        $perCohort = [];

        foreach ($rows as $row) {
            // Bucket by the GREGORIAN marriage year: native d_year for
            // Gregorian/Julian, the lower-bound julian day converted otherwise.
            $marrYear = GregorianDate::year(
                RowCast::string($row, 'marr_type'),
                RowCast::int($row, 'marr_year'),
                RowCast::int($row, 'marr_jd'),
            );

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
