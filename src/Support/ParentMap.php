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
use Illuminate\Database\Capsule\Manager as DB;

use function is_string;

/**
 * Shared helper for building the tree-wide
 * `child-id → [father-id|null, mother-id|null]` map. Both
 * KinshipRepository (Lacy pedigree completeness) and
 * GenerationDepthRepository (max-generation walk) need the same
 * data shape; keeping the construction in one place avoids
 * duplicate FAMC + FAM queries and keeps the parent-resolution
 * semantics aligned across widgets.
 *
 * Builds two SQL queries (families + child-of-family links) and
 * fuses them in PHP because the link table is by far the cheapest
 * way to enumerate FAMC relationships without iterating every
 * individual record.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ParentMap
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Build a child-id → [father-id|null, mother-id|null] map for
     * the given tree. Children with no resolvable FAM-spouse pair
     * are absent from the map (callers treat that as "root of the
     * walk: no further ancestors known").
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public static function for(Tree $tree): array
    {
        $familyRows = DB::table('families')
            ->where('f_file', '=', $tree->id())
            ->select(['f_id', 'f_husb AS husb', 'f_wife AS wife'])
            ->get();

        $familyParents = [];

        foreach ($familyRows as $row) {
            $famId = is_string($row->f_id) ? $row->f_id : '';

            if ($famId === '') {
                continue;
            }

            $husb = is_string($row->husb ?? null) && ($row->husb !== '') ? $row->husb : null;
            $wife = is_string($row->wife ?? null) && ($row->wife !== '') ? $row->wife : null;

            $familyParents[$famId] = [$husb, $wife];
        }

        $childRows = DB::table('link')
            ->where('l_file', '=', $tree->id())
            ->where('l_type', '=', 'FAMC')
            ->select(['l_from AS child', 'l_to AS family'])
            ->get();

        $parentOf = [];

        foreach ($childRows as $row) {
            $child  = is_string($row->child ?? null) ? $row->child : '';
            $family = is_string($row->family ?? null) ? $row->family : '';

            if ($child === '') {
                continue;
            }

            if ($family === '') {
                continue;
            }

            if (!isset($familyParents[$family])) {
                continue;
            }

            $parentOf[$child] = $familyParents[$family];
        }

        return $parentOf;
    }
}
