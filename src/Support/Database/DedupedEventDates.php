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
use Generator;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

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
 * The result is exact-once per individual and taken from one calendar:
 * {@see LowestDateRows} keeps one calendar among the rows that share the lowest
 * julian day, so a same-day pair of a Gregorian and a Julian birth does not mix
 * year, month and calendar of two rows, and the `GROUP BY d_gid` collapses rows
 * of one calendar and day, which differ only in precision.
 *
 * The returned builder reads like a virtual `event_dates` table with columns
 * `d_gid, d_type, d_year, d_mon, d_day, d_julianday1`; consumers chain their own
 * `select()` / `groupBy()` to bucket it, and `->count()` reports the number of
 * distinct individuals. The month is exposed as the numeric `d_mon` (1–12, or 0
 * when the date carries no month); consumers translate it to a label, so the
 * numeric and string month columns can never disagree.
 *
 * Every dated event with a known year is included regardless of calendar — wider
 * than webtrees core's Gregorian/Julian-only `StatisticsData::countEventQuery()`
 * universe. A non-Gregorian/Julian date (French Republican, Hebrew, …) carries a
 * native `d_year` meaningless on the Gregorian scale, so consumers that bucket by
 * period MUST convert it through {@see GregorianDate}
 * using the exposed `d_type` and lower-bound `d_julianday1`; for Gregorian/Julian
 * that conversion is a no-op and the native `d_year`/`d_mon`/`d_day` win. The
 * lower-bound `d_julianday1` is exposed for exactly this conversion; `d_julianday2`
 * is not, because for a range date the lower-bound row's `d_julianday2` is that
 * row's own span, not the record's upper bound, so surfacing it would invite
 * mis-bucketing.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DedupedEventDates
{
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
        $representatives = LowestDateRows::query(
            static fn (string $prefix): Builder => self::restrictToDatedFact(
                $prefix === '' ? TreeScope::table($tree, 'dates') : TreeScope::table($tree, 'dates', 'd'),
                $fact,
                $prefix,
            ),
        );

        $representativeRows = self::restrictToDatedFact(TreeScope::table($tree, 'dates', 'd'), $fact, 'd.')
            ->joinSub($representatives, 'rep', static function (JoinClause $join): void {
                $join
                    ->on('rep.d_gid', '=', 'd.d_gid')
                    ->on('rep.min_jd', '=', 'd.d_julianday1')
                    ->on('rep.min_type', '=', 'd.d_type');
            })
            ->groupBy('d.d_gid')
            ->select([
                'd.d_gid',
                DateAggregate::min('d', 'd_type', 'd_type'),
                DateAggregate::min('d', 'd_year', 'd_year'),
                DateAggregate::min('d', 'd_mon', 'd_mon'),
                DateAggregate::min('d', 'd_day', 'd_day'),
                DateAggregate::min('d', 'd_julianday1', 'd_julianday1'),
            ]);

        return DB::connection()
            ->query()
            ->fromSub($representativeRows, 'event_dates');
    }

    /**
     * The Gregorian year of each individual's lower-bound date for a fact, keyed
     * by individual XREF and streamed row by row. Each individual gives one year,
     * an imprecise date counts with its lower bound, and a date of another
     * calendar is converted through {@see GregorianDate::year()}. A year before the
     * common era is kept, a consumer that has no use for it drops it.
     *
     * The rows are read with a query of their own and matched in PHP, because
     * joining the grouped subquery of {@see query()} to the family rows makes the
     * database evaluate it again for each of them, which is far too slow on a
     * large tree.
     *
     * @param Tree   $tree The tree whose events to read
     * @param string $fact The GEDCOM fact tag (`BIRT`, `DEAT`, ...)
     *
     * @return Generator<string, int> Individual XREF to Gregorian year
     */
    public static function gregorianYears(Tree $tree, string $fact): Generator
    {
        $rows = self::query($tree, $fact)
            ->select(['d_gid', 'd_type', 'd_year', 'd_julianday1'])
            ->cursor();

        foreach ($rows as $row) {
            yield RowCast::string($row, 'd_gid') => GregorianDate::year(
                RowCast::string($row, 'd_type'),
                RowCast::int($row, 'd_year'),
                RowCast::int($row, 'd_julianday1'),
            );
        }
    }

    /**
     * Restrict a `dates` query to the resolvable-event universe — the given
     * fact, a known year (`d_year <> 0`), and a usable lower-bound julian day
     * (`d_julianday1 <> 0`) so every calendar can be converted to a Gregorian
     * period. Calendar type is deliberately NOT filtered: unlike core's
     * Gregorian/Julian-only `countEventQuery()`, this universe keeps every
     * calendar and leaves the conversion to the consumer. The `d_julianday1`
     * predicate is a no-op for Gregorian/Julian dated rows (the import always
     * synthesises a julian day for a known year), so their universe is
     * unchanged. Both the lower-bound subquery and the join-back query share
     * these predicates; the outer copy is load-bearing, not redundant, because a
     * foreign-fact / year-less / julian-day-less row sharing the representative's
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
            ->where($prefix . 'd_year', '<>', 0)
            ->where($prefix . 'd_julianday1', '<>', 0);
    }
}
