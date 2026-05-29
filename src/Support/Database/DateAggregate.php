<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Database;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Expression;

/**
 * Pure helper for the recurring `MIN(prefix.alias.col) AS out` and
 * `MAX(prefix.alias.col) AS out` aggregate column shorthand that the BET..AND /
 * FROM..TO row-dedup queries need across the cohort repositories. Six
 * repository methods previously hand-built the
 * `DB::connection()->getTablePrefix()` lookup plus `new Expression` literal
 * alongside identical "ranged date row" rationale comments — the helper
 * consolidates the boilerplate into one place so a future change to the
 * prefix-quoting strategy or the aggregate choice only has to land in one file.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DateAggregate
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Build a `MIN(prefix.alias.column) AS as` Expression. Used by every cohort
     * query that collapses BET..AND / FROM..TO double rows onto the lower-bound
     * julian day or the lower-bound year.
     *
     * @return Expression<non-falsy-string>
     */
    public static function min(string $alias, string $column, string $as): Expression
    {
        return self::build('MIN', $alias, $column, $as);
    }

    /**
     * Build a `MAX(prefix.alias.column) AS as` Expression. Used by the
     * widowhood histogram which picks the upper-bound DEAT julian day to align
     * with webtrees core's `StatisticsData::averageLifespan*`
     * maximum-possible-lifespan idiom (`death.d_julianday2 -
     * birth.d_julianday1`).
     *
     * @return Expression<non-falsy-string>
     */
    public static function max(string $alias, string $column, string $as): Expression
    {
        return self::build('MAX', $alias, $column, $as);
    }

    /**
     * @return Expression<non-falsy-string>
     */
    private static function build(string $aggregate, string $alias, string $column, string $as): Expression
    {
        $prefix = DB::connection()->getTablePrefix();

        return new Expression($aggregate . '(' . $prefix . $alias . '.' . $column . ') AS ' . $as);
    }
}
