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

use function is_string;

/**
 * Shared repository that builds the tree-wide
 * `child-id → [father-id|null, mother-id|null]` map. Three other
 * repositories consume it:
 *
 *   - {@see KinshipRepository} (Lacy pedigree completeness)
 *   - {@see GenerationDepthRepository} (max-generation walk)
 *   - {@see EndogamyRepository} (cousin-marriage detection)
 *
 * Centralising the FAMC + FAM scan here keeps every consumer
 * aligned on the same parent-resolution semantics and avoids three
 * separate full-table scans per dashboard render.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ParentMapRepository
{
    /**
     * @param Tree $tree The tree the parent map is computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Build a child-id → [father-id|null, mother-id|null] map for
     * the configured tree. Children with no resolvable FAM-spouse
     * pair are absent from the map; callers treat absence as "root
     * of the walk — no further ancestors known".
     *
     * @return array<string, array{0: string|null, 1: string|null}>
     */
    public function build(): array
    {
        $familyRows = DB::table('families')
            ->where('f_file', '=', $this->tree->id())
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
            ->where('l_file', '=', $this->tree->id())
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
