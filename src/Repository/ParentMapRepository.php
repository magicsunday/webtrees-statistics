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
     * ancestors known". A child with several FAMC families (a birth family plus
     * an adoptive/foster one) resolves to a single, DETERMINISTIC pair: the
     * family carrying the most parent information, ties broken on the
     * lexicographically lowest family xref (the xref column is text, so the
     * ordering is byte/sort order, not numeric) — never the arbitrary,
     * engine-dependent last row. Malformed parent slots are dropped before
     * scoring: a person is never their own parent, and a parent duplicated
     * across both spouse slots counts once, so a corrupt family cannot
     * out-score a valid one. Result is cached for the lifetime of the
     * repository instance.
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

        // ORDER BY l_to pins the per-child family scan to ascending family-xref
        // order so the tie-break below ("lexicographically lowest family xref
        // wins") is stable regardless of the engine's default row order. l_to is
        // a text column, so the ordering is byte/sort order (F10 sorts before
        // F2), not numeric. l_from is ordered too so the whole scan is
        // deterministic.
        $childRows = TreeScope::table($this->tree, 'link')
            ->where('l_type', '=', 'FAMC')
            ->orderBy('l_from')
            ->orderBy('l_to')
            ->select(['l_from AS child', 'l_to AS family'])
            ->get();

        // A child may carry several FAMC links (a birth family plus an
        // adoptive/foster one). The previous unordered last-write-wins picked an
        // arbitrary, engine-dependent family. Instead keep the family carrying
        // the most parent information (both parents > one > none); equal-score
        // ties resolve to the lexicographically lowest family xref, which the
        // ascending scan makes the first seen. The link table carries no
        // `2 PEDI`, so birth vs. adoptive is not distinguished — the choice is
        // deterministic, not pedigree-aware.
        $parentOf    = [];
        $parentScore = [];

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

            $pair = $familyParents[$family];

            // Drop malformed parent slots before scoring so a corrupt family
            // cannot out-score a valid one. A person is never their own parent
            // (the child listed as its own family's spouse — reachable via an
            // imported GEDCOM and via the core "Change family members" editor,
            // which enforces no parent ≠ child rule), and a parent duplicated
            // across both spouse slots carries one parent's worth of
            // information, not two.
            $father = ($pair[0] !== $child) ? $pair[0] : null;
            $mother = (($pair[1] !== $child) && ($pair[1] !== $father)) ? $pair[1] : null;

            $score = ($father !== null ? 1 : 0) + ($mother !== null ? 1 : 0);

            if (!isset($parentScore[$child]) || ($score > $parentScore[$child])) {
                $parentOf[$child]    = [$father, $mother];
                $parentScore[$child] = $score;
            }
        }

        $this->cache = $parentOf;

        return $parentOf;
    }
}
