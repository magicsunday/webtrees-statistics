<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use Fisharebest\Webtrees\Tree;
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
 * Lives in the Support layer because the helper is consumed by
 * multiple repositories and carries no repository-specific state
 * (it's a query template, not a domain-aware query).
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
     * Build an `individuals` query joined to dated BIRT (`birth`
     * alias) and DEAT (`death` alias) rows, restricted to the
     * given tree.
     */
    public static function for(Tree $tree): Builder
    {
        return TreeScope::table($tree, 'individuals')
            ->join('dates AS birth', static function (JoinClause $join): void {
                DateJoin::on($join, 'birth', 'i_file', 'i_id', 'BIRT', DateJoin::JD_GREATER_THAN_ZERO);
            })
            ->join('dates AS death', static function (JoinClause $join): void {
                DateJoin::on($join, 'death', 'i_file', 'i_id', 'DEAT', DateJoin::JD_GREATER_THAN_ZERO);
            });
    }
}
