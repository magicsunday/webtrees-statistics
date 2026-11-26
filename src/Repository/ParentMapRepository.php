<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\VisibleFamily;

use function array_chunk;
use function array_merge;
use function array_unique;
use function array_values;
use function is_string;
use function preg_match;
use function sort;

use const SORT_STRING;

/**
 * Shared repository that builds the `child-id → [father-id|null,
 * mother-id|null]` map. These repositories consume it:
 *
 *   - {@see KinshipRepository} (Lacy pedigree completeness)
 *   - {@see GenerationDepthRepository} (max-generation walk)
 *   - {@see EndogamyRepository} (cousin-marriage detection)
 *   - {@see OccupationInheritanceRepository} (parent → child occupation flows,
 *     through {@see buildVisible()})
 *
 * Centralising the parent resolution here keeps every consumer on the same rules,
 * and the consumers of {@see build()} share its cached scan. {@see buildVisible()}
 * serves a consumer that must not count a family or a link that the viewer may not
 * see. It reads the families on each call.
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

        $childFamilies = [];

        foreach ($childRows as $row) {
            $child  = RowCast::string($row, 'child');
            $family = RowCast::string($row, 'family');

            if ($child === '') {
                continue;
            }

            if ($family === '') {
                continue;
            }

            $childFamilies[$child][] = $family;
        }

        $familyParents = [];

        foreach ($this->familyRows() as $row) {
            $familyParents[RowCast::string($row, 'f_id')] = $this->parentPair($row);
        }

        $this->cache = $this->resolveParents($childFamilies, $familyParents);

        return $this->cache;
    }

    /**
     * Build the parent map of the given children as the current viewer may see
     * it. A child contributes a family only through a `FAMC` fact the viewer may
     * see, and only a family the viewer may see counts, in which the link to the
     * child is not restricted. A parent counts only when neither the spouse link
     * nor the family link of the parent is restricted. Among those families the
     * same resolution rules apply as in {@see build()}. The result is not cached.
     *
     * @param array<array-key, string> $childGedcoms Raw record text of each child, keyed by its XREF
     *
     * @return array<array-key, array{0: string|null, 1: string|null}>
     */
    public function buildVisible(array $childGedcoms): array
    {
        $parentOf = [];

        foreach (array_chunk($childGedcoms, VisibleFamily::CHUNK_SIZE, true) as $chunk) {
            $parentOf += VisibleFamily::withinScope($this->tree, fn (): array => $this->visibleParents($chunk));
        }

        return $parentOf;
    }

    /**
     * The visible parent map of one chunk of children.
     *
     * @param array<array-key, string> $childGedcoms Raw record text of each child, keyed by its XREF
     *
     * @return array<array-key, array{0: string|null, 1: string|null}>
     */
    private function visibleParents(array $childGedcoms): array
    {
        $childFamilies = [];

        foreach ($childGedcoms as $child => $gedcom) {
            // The factory merges a pending change, so the record that decides the
            // visibility of the family members is the one the host would use.
            $individual = Registry::individualFactory()->make((string) $child, $this->tree, $gedcom);

            if (!$individual instanceof Individual) {
                continue;
            }

            foreach ($individual->facts(['FAMC']) as $fact) {
                // A value that is no pointer links nothing.
                if (preg_match('/^@(' . Gedcom::REGEX_XREF . ')@$/', $fact->value(), $match) === 1) {
                    $childFamilies[$child][] = $match[1];
                }
            }

            if (isset($childFamilies[$child])) {
                sort($childFamilies[$child], SORT_STRING);
            }
        }

        if ($childFamilies === []) {
            return [];
        }

        $rows = [];

        foreach (
            TreeScope::table($this->tree, 'families')
                ->whereIn('f_id', array_values(array_unique(array_merge(...array_values($childFamilies)))))
                ->select(['f_id', 'f_husb AS husb', 'f_wife AS wife', 'f_gedcom'])
                ->get() as $row
        ) {
            $rows[RowCast::string($row, 'f_id')] = $row;
        }

        $parentOf = [];

        foreach ($childFamilies as $child => $families) {
            $familyParents = [];

            foreach ($families as $xref) {
                if (!isset($rows[$xref])) {
                    continue;
                }

                $family = VisibleFamily::find($this->tree, $xref, RowCast::string($rows[$xref], 'f_gedcom'));

                if ((!$family instanceof Family) || !VisibleFamily::showsLink($family, 'CHIL', (string) $child)) {
                    continue;
                }

                [$husb, $wife] = $this->parentPair($rows[$xref]);

                $familyParents[$xref] = [
                    (($husb !== null) && VisibleFamily::showsSpouse($family, 'HUSB', $husb)) ? $husb : null,
                    (($wife !== null) && VisibleFamily::showsSpouse($family, 'WIFE', $wife)) ? $wife : null,
                ];
            }

            $parentOf += $this->resolveParents([$child => $families], $familyParents);
        }

        return $parentOf;
    }

    /**
     * The family rows of the tree with their spouse columns.
     *
     * @return Collection<int, object>
     */
    private function familyRows(): Collection
    {
        return TreeScope::table($this->tree, 'families')
            ->select(['f_id', 'f_husb AS husb', 'f_wife AS wife'])
            ->get();
    }

    /**
     * The father and mother columns of a family row, an empty column read as no
     * parent.
     *
     * @param object $row A family row with `husb` and `wife` columns
     *
     * @return array{0: string|null, 1: string|null}
     */
    private function parentPair(object $row): array
    {
        $husb = is_string($row->husb ?? null) && ($row->husb !== '') ? $row->husb : null;
        $wife = is_string($row->wife ?? null) && ($row->wife !== '') ? $row->wife : null;

        return [$husb, $wife];
    }

    /**
     * Pick one parent pair per child from the families it belongs to.
     *
     * A child may carry several FAMC links (a birth family plus an
     * adoptive/foster one). The family carrying the most parent information
     * wins (both parents > one > none), and equal-score ties resolve to the
     * lexicographically lowest family xref, which an ascending family list makes
     * the first seen. The `2 PEDI` value of a link is not evaluated, so birth vs.
     * adoptive is not distinguished. The choice is deterministic, not
     * pedigree-aware.
     *
     * @param array<array-key, list<string>>                          $childFamilies Families of each child in ascending order
     * @param array<array-key, array{0: string|null, 1: string|null}> $familyParents Parent pair of each family that counts
     *
     * @return array<array-key, array{0: string|null, 1: string|null}>
     */
    private function resolveParents(array $childFamilies, array $familyParents): array
    {
        $parentOf    = [];
        $parentScore = [];

        foreach ($childFamilies as $child => $families) {
            foreach ($families as $family) {
                if (!isset($familyParents[$family])) {
                    continue;
                }

                $pair = $familyParents[$family];

                // Drop malformed parent slots before scoring so a corrupt family
                // cannot out-score a valid one. A person is never their own
                // parent (the child listed as its own family's spouse —
                // reachable via an imported GEDCOM and via the core "Change
                // family members" editor, which enforces no parent ≠ child
                // rule).
                $father = ($pair[0] !== (string) $child) ? $pair[0] : null;
                $mother = ($pair[1] !== (string) $child) ? $pair[1] : null;

                // A parent duplicated across both spouse slots carries one
                // parent's worth of information, not two — keep it in the father
                // slot only.
                if ($father === $mother) {
                    $mother = null;
                }

                $score = ($father !== null ? 1 : 0) + ($mother !== null ? 1 : 0);

                if (!isset($parentScore[$child]) || ($score > $parentScore[$child])) {
                    $parentOf[$child]    = [$father, $mother];
                    $parentScore[$child] = $score;
                }
            }
        }

        return $parentOf;
    }
}
