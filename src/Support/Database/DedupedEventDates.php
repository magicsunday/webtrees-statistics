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
 * cross-calendar tie the representative is still ONE coherent row: among the
 * rows sharing the lower-bound julian day the builder pins the
 * lexicographically smallest `d_type`, so every exposed column — `d_type`,
 * `d_year`, `d_mon`, `d_day` — is read from that single chosen calendar. The
 * tie-break is deterministic rather than semantic, and that suffices: a tied
 * Gregorian and Julian transcription of one day picks the Gregorian row
 * (`@#DGREGORIAN@` < `@#DJULIAN@`). A Gregorian-scale winner (Gregorian or
 * Julian) keeps its native year — the value webtrees core itself buckets by —
 * while any other calendar (`@#DFRENCH R@`, `@#DHEBREW@`, …) converts the shared
 * julian day through {@see \MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate};
 * either way the single deterministic winner lands the record in the correct
 * Gregorian period. This matters when a Gregorian and
 * a Julian transcription of the same physical day carry diverging native years
 * (`1 JAN 1800` Gregorian vs `21 DEC 1799` Julian): a naive column-wise `MIN()`
 * would mix the Gregorian `d_type` with the Julian row's lower `d_year` and
 * bucket the record one year — and at an edge one century — off. Pinning the
 * whole row keeps `d_type`/`d_year` in lock-step, so a single-column consumer
 * (century, year, month) and a multi-column one (the zodiac card's `d_mon` +
 * `d_day`) alike see a self-consistent date.
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
 * period MUST convert it through {@see \MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate}
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
        $lowerBound = self::restrictToDatedFact(TreeScope::table($tree, 'dates'), $fact, '')
            ->select(['d_gid', new Expression('MIN(d_julianday1) AS min_jd')])
            ->groupBy('d_gid');

        // Among the rows sharing that lower-bound julian day, pick a single
        // calendar deterministically — the lexicographically smallest `d_type`.
        // This resolves the exact same-julian-day cross-calendar tie, where two
        // same-fact rows share `d_julianday1` but carry diverging native years
        // (a Gregorian and a Julian transcription of one physical day), so the
        // final join-back matches ONE whole row instead of mixing a Gregorian
        // `d_type` with a Julian `d_year` column-wise. A Gregorian/Julian tie
        // resolves to the Gregorian row (`@#DGREGORIAN@` < `@#DJULIAN@`); any
        // other calendar bucketing through the shared julian day lands in the
        // same Gregorian period whichever wins, so the choice is always correct.
        $representatives = self::restrictToDatedFact(TreeScope::table($tree, 'dates', 'd'), $fact, 'd.')
            ->joinSub($lowerBound, 'lb', static function (JoinClause $join): void {
                $join
                    ->on('lb.d_gid', '=', 'd.d_gid')
                    ->on('lb.min_jd', '=', 'd.d_julianday1');
            })
            ->groupBy('d.d_gid')
            ->select([
                'd.d_gid',
                DateAggregate::min('d', 'd_julianday1', 'min_jd'),
                DateAggregate::min('d', 'd_type', 'rep_type'),
            ]);

        $representativeRows = self::restrictToDatedFact(TreeScope::table($tree, 'dates', 'd'), $fact, 'd.')
            ->joinSub($representatives, 'rep', static function (JoinClause $join): void {
                $join
                    ->on('rep.d_gid', '=', 'd.d_gid')
                    ->on('rep.min_jd', '=', 'd.d_julianday1')
                    ->on('rep.rep_type', '=', 'd.d_type');
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
