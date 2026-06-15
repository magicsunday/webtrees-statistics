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
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

/**
 * Builder factory for individuals joined to their dated BIRT and DEAT events.
 * Used by every repository that needs a "deceased individual with both anchors"
 * row — child-mortality cohort survival, lifespan by sex × century, etc.
 * Centralising the join here keeps jscpd quiet AND means a future date-type
 * widening only has to land in one place.
 *
 * The factory only sets up `from + 2× join + tree filter`; callers add their
 * own `select()` / `where()` / `groupBy()` on top.
 *
 * Lives in the Support layer because the helper is consumed by multiple
 * repositories and carries no repository-specific state (it's a query template,
 * not a domain-aware query).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class BirthDeathPairsQuery
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Build an `individuals` query joined to dated BIRT (`birth` alias) and
     * DEAT (`death` alias) rows, restricted to the given tree.
     *
     * Pass `$requireFullDate = true` when the consumer evaluates the
     * BIRT-to-DEAT diff day-precisely (e.g. the child-mortality under-5
     * threshold). The flag drops year-only / month-only records and the BEF /
     * AFT / ABT / BET..AND / FROM..TO modifier rows that webtrees writes with
     * `d_day = 0` plus a synthesised default julian-day — the modifier rows
     * would otherwise produce phantom under-5 entries from the 01.01.YYYY
     * anchor, and BET..AND / FROM..TO would additionally double-count the same
     * individual via their two-row encoding. The flag only catches the
     * `d_day = 0` ranges, though: a DAY-precise range (`BET 5 JAN AND 20 JAN`)
     * writes two rows that both carry a non-zero day and survive it, so a
     * consumer that joins this template still has to collapse per individual
     * (e.g. `GROUP BY i_id` with `MIN(d_julianday1)`) to avoid the
     * double-count. Year-granularity consumers (mean lifespan, age
     * distribution) leave the flag at its `false` default so their cohorts keep
     * the historical-era modifier rows.
     */
    public static function for(Tree $tree, bool $requireFullDate = false): Builder
    {
        $birthRepresentative = self::lowerBoundBirth($tree, $requireFullDate);

        return TreeScope::table($tree, 'individuals')
            ->joinSub($birthRepresentative, 'birth_rep', static function (JoinClause $join): void {
                $join
                    ->on('birth_rep.d_file', '=', 'i_file')
                    ->on('birth_rep.d_gid', '=', 'i_id');
            })
            ->join('dates AS birth', static function (JoinClause $join) use ($requireFullDate): void {
                DateJoin::on(
                    $join,
                    'birth',
                    'i_file',
                    'i_id',
                    'BIRT',
                    DateJoin::JD_GREATER_THAN_ZERO,
                    $requireFullDate,
                );

                // Pin the birth alias to the individual's lower-bound BIRT row,
                // so a consumer's `MIN(birth.d_year)` / `MIN(birth.d_type)` are
                // read from ONE coherent row — not column-wise from different
                // rows — when an individual carries BIRT facts in more than one
                // calendar. The julian-day pin alone resolves DISTINCT-julian-day
                // calendars; the `d_type` pin additionally resolves the exact
                // julian-day cross-calendar tie (e.g. a Gregorian `1 JAN 1800`
                // and a Julian `21 DEC 1799` transcription of the same physical
                // day, whose native `d_year` differ by one) by selecting the
                // lexicographically smallest calendar — the Gregorian row for a
                // Gregorian/Julian tie (`@#DGREGORIAN@` < `@#DJULIAN@`) — so
                // `birth.d_type` and `birth.d_year` cannot be drawn from
                // different rows. Mirrors {@see DedupedEventDates}.
                $join
                    ->on('birth.d_julianday1', '=', 'birth_rep.min_jd')
                    ->on('birth.d_type', '=', 'birth_rep.rep_type');
            })
            ->join('dates AS death', static function (JoinClause $join) use ($requireFullDate): void {
                DateJoin::on(
                    $join,
                    'death',
                    'i_file',
                    'i_id',
                    'DEAT',
                    DateJoin::JD_GREATER_THAN_ZERO,
                    $requireFullDate,
                );
            });
    }

    /**
     * Per-individual lower-bound BIRT row key: `d_file`, `d_gid`, the minimum
     * resolvable `d_julianday1` and the tie-break calendar `rep_type` — the
     * lexicographically smallest `d_type` among the rows at that lower-bound
     * julian day, so an exact same-julian-day cross-calendar tie pins to one
     * coherent row instead of mixing columns. Mirrors the birth alias's own
     * filters (a resolvable julian day, plus the full-date gate when the caller
     * demands day-precision) so the representative is drawn from exactly the rows
     * the birth join keeps.
     *
     * @param Tree $tree            The tree to scope the birth rows to
     * @param bool $requireFullDate Whether to restrict to day-precise rows (`d_day`/`d_mon` > 0)
     */
    private static function lowerBoundBirth(Tree $tree, bool $requireFullDate): Builder
    {
        $lowerBound = self::birthRows($tree, $requireFullDate)
            ->select(['d_file', 'd_gid', new Expression('MIN(d_julianday1) AS min_jd')])
            ->groupBy('d_file', 'd_gid');

        return self::birthRows($tree, $requireFullDate, 'b')
            ->joinSub($lowerBound, 'lb', static function (JoinClause $join): void {
                $join
                    ->on('lb.d_file', '=', 'b.d_file')
                    ->on('lb.d_gid', '=', 'b.d_gid')
                    ->on('lb.min_jd', '=', 'b.d_julianday1');
            })
            ->groupBy('b.d_file', 'b.d_gid')
            ->select([
                'b.d_file',
                'b.d_gid',
                DateAggregate::min('b', 'd_julianday1', 'min_jd'),
                DateAggregate::min('b', 'd_type', 'rep_type'),
            ]);
    }

    /**
     * The dated BIRT rows the representative is drawn from: a resolvable julian
     * day, plus the day-precision gate when the caller demands it. Shared by the
     * lower-bound subquery and its tie-break join-back so both read the same row
     * universe.
     *
     * @param Tree    $tree            The tree to scope the birth rows to
     * @param bool    $requireFullDate Whether to restrict to day-precise rows (`d_day`/`d_mon` > 0)
     * @param ?string $alias           Optional table alias for the join-back copy
     */
    private static function birthRows(Tree $tree, bool $requireFullDate, ?string $alias = null): Builder
    {
        $prefix = $alias === null ? '' : $alias . '.';

        $query = TreeScope::table($tree, 'dates', $alias)
            ->where($prefix . 'd_fact', '=', 'BIRT')
            ->where($prefix . 'd_julianday1', '>', 0);

        if ($requireFullDate) {
            $query
                ->where($prefix . 'd_day', '>', 0)
                ->where($prefix . 'd_mon', '>', 0);
        }

        return $query;
    }
}
