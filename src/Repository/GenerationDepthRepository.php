<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Tree\GenerationDepthReport;
use MagicSunday\Webtrees\Statistic\Support\Calc\GenerationDepth;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function array_filter;
use function array_keys;
use function array_pop;
use function array_slice;
use function arsort;
use function count;
use function max;
use function strip_tags;
use function uksort;

/**
 * Brick-wall surfacing for the Family tab. Computes the tree-wide
 * maximum generation depth and the per-individual "generations
 * below me" histogram, so the viewer can see at a glance whether
 * their tree is broadly shallow (most lines stop at parents or
 * grandparents) or has a few deep verified chains alongside many
 * unfinished lines.
 *
 * Reuses {@see ParentMap} so the same SQL pass that feeds the
 * Lacy pedigree-completeness widget also feeds this one, avoiding
 * a second full FAMC + FAM scan.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class GenerationDepthRepository
{
    /**
     * @param Tree                $tree                The tree the statistics are computed for
     * @param ParentMapRepository $parentMapRepository Shared parent-of map provider (FAMC + FAM scan)
     */
    public function __construct(
        private Tree $tree,
        private ParentMapRepository $parentMapRepository,
    ) {
    }

    /**
     * Show exactly one concrete chain. When multiple lineages reach
     * the tree-wide maximum depth, the displayed chain is the one
     * with the youngest-leaf BIRT (= the still-living-or-recent
     * branch); the total chain count is surfaced separately so the
     * reader knows others exist without the card being flooded by
     * 4-5 near-identical chains that diverge only at the leaf.
     */
    private const int MAX_CHAINS_RENDERED = 1;

    /**
     * Tree-wide generation-depth summary: longest recorded vertical
     * descent, histogram across all individuals appearing in the
     * parentage graph (`[depth => count]`), a `capped` flag that
     * signals whether the {@see GenerationDepth::MAX_DEPTH} guard
     * tripped, one concrete chain (resolved to {@see Individual}
     * objects) anchored at the youngest-BIRT leaf that reaches the
     * tree-wide maximum depth, and the total number of distinct
     * leaf-anchored chains so the view can advertise "+N more"
     * when more than one exists.
     */
    public function summary(): GenerationDepthReport
    {
        $parentOf   = $this->parentMapRepository->build();
        $result     = GenerationDepth::compute($parentOf);
        $upDistance = GenerationDepth::upDistanceCache($parentOf);

        $byLeaf = $this->collectDistinctChainsKeyedByLeaf(
            $parentOf,
            $upDistance,
            $result['maxDepth'],
        );

        // Sort the distinct chains youngest-leaf first so the
        // present-day reader recognises the lineage end. A missing
        // BIRT julian-day falls back to alphabetical leaf ID, which
        // keeps the order stable across reloads.
        $leafBirthJday = $this->birthJulianDaysForLeaves(array_keys($byLeaf));

        uksort($byLeaf, static function (string $a, string $b) use ($leafBirthJday): int {
            $jdayA = $leafBirthJday[$a] ?? 0;
            $jdayB = $leafBirthJday[$b] ?? 0;

            if ($jdayA !== $jdayB) {
                return $jdayB <=> $jdayA;
            }

            return $a <=> $b;
        });

        $top    = array_slice($byLeaf, 0, self::MAX_CHAINS_RENDERED, true);
        $chains = [];

        foreach ($top as $chainIds) {
            $resolved = [];

            foreach ($chainIds as $id) {
                $individual = Registry::individualFactory()->make($id, $this->tree);

                if ($individual instanceof Individual) {
                    $resolved[] = $individual;
                }
            }

            if ($resolved !== []) {
                $chains[] = $resolved;
            }
        }

        return new GenerationDepthReport(
            maxDepth: $result['maxDepth'],
            distribution: $result['distribution'],
            capped: $result['capped'],
            chains: $chains,
            totalChainCount: count($byLeaf),
        );
    }

    /**
     * For every leaf descendant whose longest upward chain reaches
     * `$maxDepth`, reconstruct the chain by walking up via parents.
     * Keys are leaf IDs — so two structurally different upward paths
     * landing on the same leaf collapse to one entry (the
     * alphabetically preferred path through the parents). The leaf
     * is the most recognisable end of the chain for a present-day
     * reader, so keying by leaf matches the user-visible notion of
     * "the same line".
     *
     * @param array<string, array{0: string|null, 1: string|null}> $parentOf
     * @param array<string, int>                                   $upDistance
     *
     * @return array<string, list<string>>
     */
    private function collectDistinctChainsKeyedByLeaf(array $parentOf, array $upDistance, int $maxDepth): array
    {
        $byLeaf = [];

        foreach ($upDistance as $leafId => $distance) {
            if ($distance !== $maxDepth) {
                continue;
            }

            $chain = GenerationDepth::walkUpFromLeaf($parentOf, $upDistance, $leafId, $maxDepth);

            if ($chain === null) {
                continue;
            }

            if ($chain === []) {
                continue;
            }

            $byLeaf[$leafId] = $chain;
        }

        return $byLeaf;
    }

    /**
     * Top-N ancestors ranked by their total documented descendant
     * count (transitive: children + grandchildren + great-grandchildren
     * + …). Surfaces the structural "roots" of the tree — the
     * individuals whose branches actually carry the rest of the
     * recorded lineage.
     *
     * Walks the inverted parent map once with per-individual
     * memoisation, so a descendant reached through two different
     * grandparents is still counted once per ancestor. The walk is
     * iterative (no recursion-depth limit) and the cache survives
     * across the foreach so deep trees stay linear in the
     * cardinality of the graph.
     *
     * Privacy: follows the webtrees convention used by
     * {@see ChildrenRepository::topLargestFamilies()}
     * and core's `StatisticsData::familiesWithTheMostChildren()` — the
     * podium row stays in place for every ancestor, and
     * `Individual::fullName()` substitutes the "Private" placeholder
     * when the current user lacks access. Filtering by `canShow()`
     * would shift downstream ranks and surface a smaller-than-N
     * podium, which the module does not do anywhere else.
     *
     * @param int $topN Maximum number of rows to return (default 10)
     *
     * @return array<string, int> Display label → descendant count, descending
     */
    public function topAncestorsByDescendantCount(int $topN = 10): array
    {
        $parentOf   = $this->parentMapRepository->build();
        $childrenOf = GenerationDepth::childrenMap($parentOf);

        // Two passes:
        //   1. Build a complete xref list. parentOf carries every
        //      individual that has at least one recorded parent;
        //      childrenOf carries every individual that has at least
        //      one recorded child. Their union covers everyone who
        //      appears in any parent-child link.
        //   2. For each xref, walk descendants iteratively with a
        //      shared visited set to deduplicate diamond merges and
        //      with the count cache to skip re-computation.
        $allIds = $parentOf;

        foreach (array_keys($childrenOf) as $id) {
            $allIds[$id] ??= [null, null];
        }

        /** @var array<string, int> $descendantCount */
        $descendantCount = [];

        foreach (array_keys($allIds) as $id) {
            $descendantCount[$id] = $this->countDescendantsTransitive($childrenOf, $id);
        }

        // Drop leaves: an individual with zero descendants is not a
        // "root" of the tree and would only clutter the podium below
        // the genuine roots.
        $descendantCount = array_filter($descendantCount, static fn (int $n): bool => $n > 0);

        arsort($descendantCount);

        $out = [];

        foreach ($descendantCount as $xref => $count) {
            if (count($out) >= $topN) {
                break;
            }

            $individual = Registry::individualFactory()->make($xref, $this->tree);

            if (!$individual instanceof Individual) {
                continue;
            }

            $out[strip_tags($individual->fullName())] = $count;
        }

        return $out;
    }

    /**
     * Iterative BFS over the children map starting at `$startId`,
     * collecting the set of transitive descendants and returning
     * its size. The visited set is local to the call so diamond
     * merges (two parent chains meeting at the same descendant)
     * collapse to one count. The visited set seed includes
     * `$startId` itself, which the final size subtracts so the
     * result is "descendants exclusive of self" as the issue
     * specifies.
     *
     * Linear in the size of the reachable subtree; the foreach in
     * {@see topAncestorsByDescendantCount} runs this once per id,
     * so the overall complexity is bounded by O(N · D) where N is
     * the number of individuals and D is the average descendant
     * count.
     *
     * @param array<string, list<string>> $childrenOf Children-of map
     * @param string                      $startId    Ancestor xref to count from
     */
    private function countDescendantsTransitive(array $childrenOf, string $startId): int
    {
        $visited = [$startId => true];
        $queue   = [$startId];

        while ($queue !== []) {
            $current = array_pop($queue);

            foreach ($childrenOf[$current] ?? [] as $child) {
                if (isset($visited[$child])) {
                    continue;
                }

                $visited[$child] = true;
                $queue[]         = $child;
            }
        }

        return count($visited) - 1;
    }

    /**
     * Bulk-fetch the BIRT julian-day for every leaf id in one SQL
     * round-trip. Returns a `[leafId => julianDay]` map; ids with
     * no Gregorian/Julian BIRT date are simply absent (the caller
     * treats absence as "rank below dated leaves").
     *
     * @param list<string> $leafIds
     *
     * @return array<string, int>
     */
    private function birthJulianDaysForLeaves(array $leafIds): array
    {
        if ($leafIds === []) {
            return [];
        }

        $rows = TreeScope::table($this->tree, 'dates')
            ->where('d_fact', '=', 'BIRT')
            ->whereIn('d_gid', $leafIds)
            ->whereIn('d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
            ->where('d_julianday1', '>', 0)
            ->select(['d_gid', 'd_julianday1'])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $id   = RowCast::string($row, 'd_gid');
            $jday = RowCast::int($row, 'd_julianday1');

            if ($id === '') {
                continue;
            }

            if ($jday === 0) {
                continue;
            }

            // Same id can have multiple BIRT date rows; keep the latest.
            $existing = $out[$id] ?? 0;
            $out[$id] = max($existing, $jday);
        }

        return $out;
    }
}
