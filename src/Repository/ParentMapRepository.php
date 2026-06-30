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
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function is_string;

/**
 * Shared repository that builds the tree-wide `child-id → [father-id|null,
 * mother-id|null]` map. Four other repositories consume it:
 *
 *   - {@see KinshipRepository} (Lacy pedigree completeness)
 *   - {@see GenerationDepthRepository} (max-generation walk)
 *   - {@see EndogamyRepository} (cousin-marriage detection)
 *   - {@see OccupationInheritanceRepository} (parent → child occupation flows)
 *
 * Centralising the FAMC + FAM scan here keeps every consumer aligned on the
 * same parent-resolution semantics and avoids a separate full-table scan per
 * consumer per dashboard render.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ParentMapRepository
{
    /**
     * Per-instance memo of the parent map. Repository is shared across multiple
     * statistics-chart consumers within a single request, and the underlying
     * SQL + map construction were dominating load time when each caller rebuilt
     * independently.
     *
     * @var array<array-key, array{0: string|null, 1: string|null}>|null
     */
    private ?array $cache = null;

    /**
     * @param Tree $tree The tree the parent map is computed for
     */
    public function __construct(
        private readonly Tree $tree,
    ) {
    }

    /**
     * Build a child-id → [father-id|null, mother-id|null] map for the
     * configured tree. Children with no resolvable FAM-spouse pair are absent
     * from the map; callers treat absence as "root of the walk — no further
     * ancestors known". Result is cached for the lifetime of the repository
     * instance.
     *
     * The key type is `array-key`, not `string`: a digit-only XREF ("54") is
     * silently coerced to an int the moment it indexes the array, so callers
     * that read a key back out must cast it to string before passing it to a
     * string-typed sink.
     *
     * @return array<array-key, array{0: string|null, 1: string|null}>
     */
    public function build(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $familyRows = TreeScope::table($this->tree, 'families')
            ->select(['f_id', 'f_husb AS husb', 'f_wife AS wife'])
            ->get();

        $familyParents = [];

        foreach ($familyRows as $row) {
            $famId = RowCast::string($row, 'f_id');

            if ($famId === '') {
                continue;
            }

            $husb = is_string($row->husb ?? null) && ($row->husb !== '') ? $row->husb : null;
            $wife = is_string($row->wife ?? null) && ($row->wife !== '') ? $row->wife : null;

            $familyParents[$famId] = [$husb, $wife];
        }

        $childRows = TreeScope::table($this->tree, 'link')
            ->where('l_type', '=', 'FAMC')
            ->select(['l_from AS child', 'l_to AS family'])
            ->get();

        $parentOf = [];

        foreach ($childRows as $row) {
            $child  = RowCast::string($row, 'child');
            $family = RowCast::string($row, 'family');

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

        $this->cache = $parentOf;

        return $parentOf;
    }
}
