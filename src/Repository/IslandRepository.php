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
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Model\Metric\IslandSummary;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function array_filter;
use function array_keys;
use function array_map;
use function array_slice;
use function array_sum;
use function array_values;
use function count;
use function max;
use function strcmp;
use function usort;

/**
 * Builds the "unconnected islands" summary for the Tree-health tab: the connected
 * components of the relationship graph, where every co-member of a family
 * (spouses and their children) shares an edge and an individual in no family is
 * a component of size one. Computed with a union-find pass over the family
 * memberships — near-linear in the number of individuals, so it scales to large
 * trees in a single request. The largest island is the tree's main lineage; the
 * rest are detached sub-trees or lone individuals.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class IslandRepository
{
    /**
     * @param Tree $tree The tree the islands are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Summarise the tree's connected components. `$limit` caps the descending
     * `top` list (each island carrying its rank, member count and dominant
     * surname); islands beyond the cap fold into `restMembers`. The headline
     * counts always span the whole tree.
     *
     * @param int $limit Maximum number of islands to surface individually
     */
    public function getConnectedComponents(int $limit): IslandSummary
    {
        $parent = $this->seedComponents();

        foreach ($this->familyMembers() as $members) {
            $present = array_values(
                array_filter($members, static fn (string $member): bool => isset($parent[$member]))
            );

            for ($i = 1, $count = count($present); $i < $count; ++$i) {
                $this->union($parent, $present[0], $present[$i]);
            }
        }

        /** @var array<array-key, list<string>> $components */
        $components = [];

        foreach (array_keys($parent) as $id) {
            $member              = (string) $id;
            $root                = $this->find($parent, $member);
            $components[$root][] = $member;
        }

        if ($components === []) {
            return new IslandSummary([], 0, 0, 0, 0);
        }

        $islands = [];

        foreach ($components as $root => $memberList) {
            $islands[] = ['root' => (string) $root, 'members' => $memberList, 'size' => count($memberList)];
        }

        // Descending by size; the root XREF is a deterministic tie-break so the
        // ranking is stable when several islands share a size.
        usort(
            $islands,
            static function (array $a, array $b): int {
                $bySize = $b['size'] <=> $a['size'];

                return $bySize !== 0 ? $bySize : strcmp($a['root'], $b['root']);
            }
        );

        $totalPersons = array_sum(array_map(static fn (array $island): int => $island['size'], $islands));
        $largestSize  = $islands[0]['size'];

        $cap         = max(0, $limit);
        $topIslands  = array_slice($islands, 0, $cap);
        $restMembers = array_sum(array_map(static fn (array $island): int => $island['size'], array_slice($islands, $cap)));

        $surnames = $this->surnamesByIndividual();
        $top      = [];

        foreach ($topIslands as $index => $island) {
            $top[] = [
                'rank'    => $index + 1,
                'members' => $island['size'],
                'label'   => $this->dominantSurname($island['members'], $surnames),
            ];
        }

        return new IslandSummary($top, count($islands), $totalPersons, $largestSize, $restMembers);
    }

    /**
     * Seed the union-find forest with one singleton component per individual, so
     * that an individual in no family still counts as an island of size one.
     *
     * @return array<array-key, string> Each individual XREF mapped to itself
     */
    private function seedComponents(): array
    {
        $parent = [];

        foreach (TreeScope::table($this->tree, 'individuals')->select(['i_id'])->get() as $row) {
            $id = RowCast::string($row, 'i_id');

            if ($id === '') {
                continue;
            }

            $parent[$id] = $id;
        }

        return $parent;
    }

    /**
     * Member XREF lists per family: husband, wife and every linked child. Used
     * to union the family's individuals into one component.
     *
     * @return list<list<string>>
     */
    private function familyMembers(): array
    {
        $families = [];

        $familyRows = TreeScope::table($this->tree, 'families')
            ->select(['f_id', 'f_husb AS husb', 'f_wife AS wife'])
            ->get();

        foreach ($familyRows as $row) {
            $familyId = RowCast::string($row, 'f_id');

            if ($familyId === '') {
                continue;
            }

            $members = [];

            $husband = RowCast::string($row, 'husb');

            if ($husband !== '') {
                $members[] = $husband;
            }

            $wife = RowCast::string($row, 'wife');

            if ($wife !== '') {
                $members[] = $wife;
            }

            $families[$familyId] = $members;
        }

        $childRows = TreeScope::table($this->tree, 'link')
            ->where('l_type', '=', 'FAMC')
            ->join('families', static function (JoinClause $join): void {
                $join
                    ->on('families.f_file', '=', 'link.l_file')
                    ->on('families.f_id', '=', 'link.l_to');
            })
            ->select(['link.l_from AS child', 'link.l_to AS family'])
            ->get();

        foreach ($childRows as $row) {
            $child  = RowCast::string($row, 'child');
            $family = RowCast::string($row, 'family');

            if ($child === '') {
                continue;
            }

            if (!isset($families[$family])) {
                continue;
            }

            $families[$family][] = $child;
        }

        return array_values($families);
    }

    /**
     * Primary birth surname per individual XREF. Married names (`_MARNM`) and
     * transliteration variants are ignored — only the main `NAME` fact feeds the
     * island label.
     *
     * @return array<array-key, string>
     */
    private function surnamesByIndividual(): array
    {
        $rows = DB::table('name')
            ->where('n_file', '=', $this->tree->id())
            ->where('n_type', '=', 'NAME')
            ->where('n_surn', '<>', '')
            ->select(['n_id', 'n_surn AS surname'])
            ->get();

        $surnames = [];

        foreach ($rows as $row) {
            $id = RowCast::string($row, 'n_id');

            if ($id === '') {
                continue;
            }

            // One surname per individual: first NAME row wins.
            $surnames[$id] ??= RowCast::string($row, 'surname');
        }

        return $surnames;
    }

    /**
     * The most common surname across an island's members, used as its label.
     * Returns an empty string when no member carries a recorded surname; ties
     * resolve to the alphabetically first surname so the label is deterministic.
     *
     * @param list<string>             $members  Member XREFs of the island
     * @param array<array-key, string> $surnames Individual XREF → surname map
     */
    private function dominantSurname(array $members, array $surnames): string
    {
        // A purely numeric surname coerces the array key to int, so the key is
        // array-key, not string — the (string) cast below is therefore real.
        /** @var array<array-key, int> $counts */
        $counts = [];

        foreach ($members as $member) {
            $surname = $surnames[$member] ?? '';

            if ($surname === '') {
                continue;
            }

            $counts[$surname] = ($counts[$surname] ?? 0) + 1;
        }

        if ($counts === []) {
            return '';
        }

        $ranked = [];

        foreach ($counts as $surname => $occurrences) {
            $ranked[] = ['surname' => (string) $surname, 'count' => $occurrences];
        }

        usort(
            $ranked,
            static function (array $a, array $b): int {
                $byCount = $b['count'] <=> $a['count'];

                return $byCount !== 0 ? $byCount : strcmp($a['surname'], $b['surname']);
            }
        );

        return $ranked[0]['surname'];
    }

    /**
     * Resolve the component root of an individual, compressing the path so
     * repeated lookups stay flat.
     *
     * @param array<array-key, string> $parent Union-find forest, mutated in place
     * @param string                   $xref   Individual to resolve
     */
    private function find(array &$parent, string $xref): string
    {
        $root = $xref;

        while ($parent[$root] !== $root) {
            $root = $parent[$root];
        }

        while ($parent[$xref] !== $root) {
            $next          = $parent[$xref];
            $parent[$xref] = $root;
            $xref          = $next;
        }

        return $root;
    }

    /**
     * Merge the components of two individuals.
     *
     * @param array<array-key, string> $parent Union-find forest, mutated in place
     * @param string                   $a      First individual
     * @param string                   $b      Second individual
     */
    private function union(array &$parent, string $a, string $b): void
    {
        $rootA = $this->find($parent, $a);
        $rootB = $this->find($parent, $b);

        if ($rootA !== $rootB) {
            $parent[$rootA] = $rootB;
        }
    }
}
