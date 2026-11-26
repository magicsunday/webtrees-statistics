<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Database;

use Closure;
use Fisharebest\Webtrees\DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

/**
 * The key of the representative date row of each individual: the lowest julian
 * day and, among the rows that share it, one calendar. Two dates of the same day
 * in two calendars would otherwise mix the calendar of one row with the year or
 * month of the other. Rows that share the key can differ only in how precise
 * they are within that calendar.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class LowestDateRows
{
    /**
     * Prevent instantiation, this is a static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * One row per individual with the columns `d_file`, `d_gid`, `min_jd` and
     * `min_type`.
     *
     * @param Closure(string): Builder $dates Builds the restricted `dates` query for a column prefix, `''` for the bare table or `'d.'` for the alias `d`
     *
     * @return Builder The key query
     */
    public static function query(Closure $dates): Builder
    {
        $lowestDay = $dates('')
            ->select(['d_file', 'd_gid', new Expression('MIN(d_julianday1) AS min_jd')])
            ->groupBy('d_file', 'd_gid');

        $prefix = DB::connection()->getTablePrefix();

        return $dates('d.')
            ->joinSub($lowestDay, 'low', static function (JoinClause $join): void {
                $join
                    ->on('low.d_file', '=', 'd.d_file')
                    ->on('low.d_gid', '=', 'd.d_gid')
                    ->on('low.min_jd', '=', 'd.d_julianday1');
            })
            ->groupBy('d.d_file', 'd.d_gid')
            ->select([
                'd.d_file',
                'd.d_gid',
                new Expression('MIN(' . $prefix . 'd.d_julianday1) AS min_jd'),
                new Expression('MIN(' . $prefix . 'd.d_type) AS min_type'),
            ]);
    }
}
