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
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;

/**
 * Builder factory for individuals joined to their dated BIRT and
 * DEAT events. Used by every repository that needs a "deceased
 * individual with both anchors" row — child-mortality cohort
 * survival, lifespan by sex × century, etc. Centralising the
 * join here keeps jscpd quiet AND means a future date-type
 * widening only has to land in one place.
 *
 * The factory only sets up `from + 2× join + tree filter`;
 * callers add their own `select()` / `where()` / `groupBy()`
 * on top.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class BirthDeathPairsQuery
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Build an `individuals` query joined to dated BIRT (`birth`
     * alias) and DEAT (`death` alias) rows, restricted to the
     * given tree.
     *
     * @param Tree $tree The tree the statistics are computed for
     *
     * @return Builder
     */
    public static function for(Tree $tree): Builder
    {
        return DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->join('dates AS birth', static function (JoinClause $join): void {
                $join
                    ->on('birth.d_file', '=', 'i_file')
                    ->on('birth.d_gid', '=', 'i_id')
                    ->where('birth.d_fact', '=', 'BIRT')
                    ->whereIn('birth.d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
                    ->where('birth.d_julianday1', '>', 0);
            })
            ->join('dates AS death', static function (JoinClause $join): void {
                $join
                    ->on('death.d_file', '=', 'i_file')
                    ->on('death.d_gid', '=', 'i_id')
                    ->where('death.d_fact', '=', 'DEAT')
                    ->whereIn('death.d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
                    ->where('death.d_julianday1', '>', 0);
            });
    }
}
