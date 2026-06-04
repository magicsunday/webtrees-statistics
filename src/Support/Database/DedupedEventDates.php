<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Database;

use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

/**
 * Builder factory that collapses webtrees' two-row encoding of an imprecise
 * date down to exactly one representative row per individual, so any "count
 * events by century / decade / month" aggregation buckets each record once and
 * assigns it to a single bucket.
 *
 * Webtrees writes TWO `dates` rows for a range date (`BET..AND`, `FROM..TO`) —
 * a lower-bound row (the `minimumDate()`) and an upper-bound row (the
 * `maximumDate()`). A raw `COUNT(*)` over `dates` therefore double-counts such
 * an individual and, when the two bounds straddle a bucket edge (a century, a
 * year, a month), splits one record across two buckets. (Year-only / `ABT` /
 * month-only dates keep a single row whose `d_julianday1`..`d_julianday2` span
 * encodes the imprecision, so they are unaffected.)
 *
 * The helper picks each individual's **lower-bound** row — the one with the
 * minimum `d_julianday1` — and exposes that row's `d_year`, `d_mon` and
 * `d_day`. The lower bound has to be matched as one whole row rather than
 * computed column-wise: independent `MIN(d_year)` / `MIN(d_mon)` would mix a
 * `BET DEC 1880 AND JAN 1881` record into the non-existent month "January
 * 1880", whereas the lower-bound row is correctly "December 1880".
 *
 * The result is guaranteed exact-once per individual: a `GROUP BY d_gid` over
 * the matched lower-bound rows collapses the rare tie where two same-fact rows
 * share the minimum `d_julianday1` (e.g. a Gregorian and a Julian birth event
 * that map to the same julian day) — without it that individual would surface
 * twice and split across two buckets, the very defect this helper exists to
 * remove. For a single-row individual the `GROUP BY` is a no-op. On a genuine
 * cross-calendar tie the surviving `d_year` / `d_mon` / `d_day` are the
 * per-column minima of the tied rows and may therefore be drawn from different
 * rows. The exact-once cardinality holds regardless — the `GROUP BY` emits one
 * row per individual, so every consumer counts each record once. A consumer
 * that reads a single column (century, year, month) is fully correct; a
 * consumer that combines `d_mon` and `d_day` from the same row (the zodiac
 * card) is also correct for the common range case, where the two bounds carry
 * distinct julian days and the join-back matches one whole row, and on the rare
 * tie could at worst place the still-counted-once individual in an adjacent
 * bucket.
 *
 * The returned builder reads like a virtual `event_dates` table with columns
 * `d_gid, d_year, d_mon, d_day`; consumers chain their own `select()` /
 * `groupBy()` to bucket it, and `->count()` reports the number of distinct
 * individuals. The month is exposed as the numeric `d_mon` (1–12, or 0 when the
 * date carries no month); consumers translate it to a label, so the numeric and
 * string month columns can never disagree. Only Gregorian / Julian dated events
 * with a known year are included, matching webtrees core's
 * `StatisticsData::countEventQuery()` universe. Julian-day columns are
 * deliberately not exposed: for a range date the lower-bound row's
 * `d_julianday2` is that row's own span, not the record's upper bound, so
 * surfacing it would invite mis-bucketing.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DedupedEventDates
{
    /**
     * Calendar date types core counts — the synthesised julian-days are only
     * meaningful for Gregorian and Julian dates.
     */
    private const array DATED_TYPES = ['@#DGREGORIAN@', '@#DJULIAN@'];

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Build a query yielding exactly one representative (lower-bound) row per
     * individual for the given fact within the given tree.
     *
     * @param Tree   $tree The tree whose events to scope the query to
     * @param string $fact The GEDCOM fact tag to collapse (e.g. `BIRT`, `DEAT`, `MARR`, `DIV`)
     */
    public static function query(Tree $tree, string $fact): Builder
    {
        $representatives = self::restrictToDatedFact(TreeScope::table($tree, 'dates'), $fact, '')
            ->select(['d_gid', new Expression('MIN(d_julianday1) AS min_jd')])
            ->groupBy('d_gid');

        $representativeRows = self::restrictToDatedFact(TreeScope::table($tree, 'dates', 'd'), $fact, 'd.')
            ->joinSub($representatives, 'rep', static function (JoinClause $join): void {
                $join
                    ->on('rep.d_gid', '=', 'd.d_gid')
                    ->on('rep.min_jd', '=', 'd.d_julianday1');
            })
            ->groupBy('d.d_gid')
            ->select([
                'd.d_gid',
                DateAggregate::min('d', 'd_year', 'd_year'),
                DateAggregate::min('d', 'd_mon', 'd_mon'),
                DateAggregate::min('d', 'd_day', 'd_day'),
            ]);

        return DB::connection()
            ->query()
            ->fromSub($representativeRows, 'event_dates');
    }

    /**
     * Restrict a `dates` query to the count universe core's `countEventQuery()`
     * uses — the given fact, a Gregorian or Julian calendar type, and a known
     * year. Both the lower-bound subquery and the join-back query share these
     * predicates; the outer copy is load-bearing, not redundant, because a
     * foreign-fact / off-calendar / year-less row sharing the representative's
     * julian day would otherwise join back and corrupt the collapse.
     *
     * @param Builder $query  The `dates` query to constrain
     * @param string  $fact   The GEDCOM fact tag to keep
     * @param string  $prefix Column qualifier (`''` for the bare table, `'d.'` for the aliased one)
     */
    private static function restrictToDatedFact(Builder $query, string $fact, string $prefix): Builder
    {
        return $query
            ->where($prefix . 'd_fact', '=', $fact)
            ->whereIn($prefix . 'd_type', self::DATED_TYPES)
            ->where($prefix . 'd_year', '<>', 0);
    }
}
