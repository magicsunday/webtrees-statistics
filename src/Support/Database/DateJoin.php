<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Database;

use Illuminate\Database\Query\JoinClause;

/**
 * Pure helper for the recurring `dates` table join the repositories use to
 * anchor a parent row (individual or family) to a BIRT, DEAT, MARR or DIV
 * event: file column join, GEDCOM-id join, fact filter and one resolvable-date
 * guard on `d_julianday1`. One helper keeps the guard and the order of
 * conditions consistent across every consumer.
 *
 * The join keeps EVERY calendar (by default it guards on `d_julianday1 <> 0`,
 * not on `d_type`): the julian day is calendar-neutral, so an age/duration span
 * between two dates is correct whatever calendar each was written in, and a
 * period-bucketing consumer converts the bucket year through
 * {@see \MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate} using the
 * row's `d_type` and `d_julianday1`. For Gregorian/Julian rows the default guard
 * is a no-op (the import always synthesises a julian day for a known date), so
 * the guard filters out nothing for them and a non-Gregorian/Julian date is kept
 * where a calendar filter would have dropped it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DateJoin
{
    /**
     * Operator for the julian-day guard of {@see on()} when the caller needs a
     * positive julian day instead of the default non-zero one.
     */
    public const string JD_GREATER_THAN_ZERO = '>';

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Attach the standard four conditions to a `dates` table join: file column
     * equality, GEDCOM-id column equality, fact filter, and one resolvable-date
     * guard on `d_julianday1`. Without `$jdOperator` the guard is `<> 0`, which
     * keeps every calendar and drops only the literal-zero sentinel of date-less
     * rows. Pass {@see JD_GREATER_THAN_ZERO} to require a positive julian day
     * instead, the guard then replaces the default and is not emitted twice.
     *
     * Pass `$requireFullDate = true` when the consumer evaluates the julian-day
     * diff day-precisely (same-day match, sub-year diffs, JD-sort within the
     * same year). Year-only records (`d_day = 0`), month-only records (`d_day =
     * 0, d_mon > 0`) AND modifier-affected rows (BEF / AFT / ABT / BET..AND /
     * FROM..TO — webtrees writes them with `d_day = 0` and synthesises a
     * default julian-day = 01.01.YYYY which would otherwise leak into the JD
     * comparison) all fall away in one stroke. Equivalent to manually emitting
     * `->where($alias.'.d_day', '>', 0)->where($alias.'.d_mon', '>', 0)` at
     * every call site.
     */
    public static function on(
        JoinClause $join,
        string $alias,
        string $fileCol,
        string $gidCol,
        string $fact,
        ?string $jdOperator = null,
        bool $requireFullDate = false,
    ): void {
        $join
            ->on($alias . '.d_file', '=', $fileCol)
            ->on($alias . '.d_gid', '=', $gidCol)
            ->where($alias . '.d_fact', '=', $fact)
            ->where($alias . '.d_julianday1', $jdOperator ?? '<>', 0);

        if ($requireFullDate) {
            $join
                ->where($alias . '.d_day', '>', 0)
                ->where($alias . '.d_mon', '>', 0);
        }
    }
}
