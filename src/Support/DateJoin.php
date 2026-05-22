<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use Illuminate\Database\Query\JoinClause;

/**
 * Pure helper for the recurring `dates` table join the repositories
 * use to anchor a parent row (individual or family) to a BIRT, DEAT,
 * MARR or DIV event. Twenty-plus repository queries previously
 * inlined the same five-line block — file column join, GEDCOM-id
 * join, fact filter, and the Gregorian/Julian calendar predicate —
 * and many of them additionally required the `d_julianday1` column
 * to carry a usable value. Consolidating into one helper keeps the
 * calendar predicate, the order of conditions, and the optional
 * julian-day filter consistent across every consumer; adding a new
 * calendar (e.g. `@#DFRENCH R@`) or changing the column convention
 * only has to happen in one place.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DateJoin
{
    /**
     * Operator passed to the optional `d_julianday1` predicate when
     * the caller insists on a resolvable date (julian-day > 0).
     * Captures the convention used by the deterministic-pair queries
     * (BirthDeathPairs, age-at-first-child, age-at-divorce-band).
     */
    public const string JD_GREATER_THAN_ZERO = '>';

    /**
     * Operator passed to the optional `d_julianday1` predicate when
     * the caller only wants to filter out the literal `0` sentinel
     * that the import path writes for date-less rows. Convention
     * used by the histogram queries (age-at-divorce distribution,
     * sibling-gap join).
     */
    public const string JD_NOT_EQUAL_ZERO = '<>';

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Attach the standard four conditions to a `dates` table join:
     * file column equality, GEDCOM-id column equality, fact filter,
     * and the Gregorian/Julian calendar predicate. Pass
     * `$jdOperator` when the caller additionally wants the join's
     * `d_julianday1` column constrained — use {@see JD_GREATER_THAN_ZERO}
     * to require a fully resolvable date, or {@see JD_NOT_EQUAL_ZERO}
     * to filter only the literal-zero sentinel.
     */
    public static function on(
        JoinClause $join,
        string $alias,
        string $fileCol,
        string $gidCol,
        string $fact,
        ?string $jdOperator = null,
    ): void {
        $join
            ->on($alias . '.d_file', '=', $fileCol)
            ->on($alias . '.d_gid', '=', $gidCol)
            ->where($alias . '.d_fact', '=', $fact)
            ->whereIn($alias . '.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);

        if ($jdOperator !== null) {
            $join->where($alias . '.d_julianday1', $jdOperator, 0);
        }
    }
}
