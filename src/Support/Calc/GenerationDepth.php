<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Calc;

use function array_fill;
use function array_keys;
use function array_map;
use function array_pop;
use function array_reverse;
use function max;
use function min;
use function sort;

/**
 * Pure helper that computes per-individual generation depth from a parent-of
 * map. "Depth" is the number of generations recorded BELOW an individual: a
 * leaf (no descendants in the tree) has depth 0, an individual whose only
 * descendants are direct children has depth 1, and so on. The tree-wide maximum
 * is the longest recorded vertical descent.
 *
 * Depth is computed as a longest-path memoisation over the parentage graph
 * (`depth(node) = 1 + max(depth(child))`), so pedigree collapse — where the same
 * descendant is reachable through several lines of possibly different length —
 * always yields the LONGEST descent, independent of traversal order. The walk is
 * bounded by `MAX_DEPTH` and a per-walk on-path set so an accidentally-cyclic
 * GEDCOM (rare but possible — self-referential FAMC + FAMS edits) cannot loop
 * forever.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class GenerationDepth
{
    /**
     * Cap on the downward walk. Set deliberately high — the only real purpose
     * is to guarantee the walk terminates on a cyclic FAMC/FAMS edit, not to
     * bound legitimate long descent lines (which can run very deep on royal /
     * mythical-genealogy trees that trace back to antiquity).
     */
    public const int MAX_DEPTH = 100;

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Compute the generation-depth metrics for the given parent-of map:
     * tree-wide maximum depth, a `[depth => count]` histogram across every
     * individual that appears anywhere in the parentage graph (as a parent or
     * as a child), and whether the walk hit the depth cap (signals data quality
     * concerns: cycle or implausibly long chain).
     *
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     *
     * @return array{maxDepth: int, distribution: array<int, int>, capped: bool}
     */
    public static function compute(array $parentOf): array
    {
        $childrenOf = self::invertToChildrenMap($parentOf);
        $depthCache = self::depthCache($childrenOf, $parentOf);
        $maxDepth   = 0;
        $capped     = false;

        foreach ($depthCache as $depth) {
            if ($depth === self::MAX_DEPTH) {
                $capped = true;
            }

            $maxDepth = max($maxDepth, $depth);
        }

        $distribution = array_fill(0, $maxDepth + 1, 0);

        foreach ($depthCache as $depth) {
            $distribution[$depth] = ($distribution[$depth] ?? 0) + 1;
        }

        return [
            'maxDepth'     => $maxDepth,
            'distribution' => $distribution,
            'capped'       => $capped,
        ];
    }

    /**
     * Build the children-of map from a parent-of map. Public so the repository
     * can reuse the same inverted view — to count transitive descendants and to
     * tell a genuine leaf (no children) apart from a clamped interior node when
     * keying distinct deepest-chains by their leaf.
     *
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     *
     * @return array<array-key, list<string>>
     */
    public static function childrenMap(array $parentOf): array
    {
        return self::invertToChildrenMap($parentOf);
    }

    /**
     * Run the per-individual deepest-descendant walk for every node in the
     * parentage graph and return the `[id => depth]` cache {@see compute()}
     * derives its metrics from.
     *
     * @param array<array-key, list<string>>                          $childrenOf
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     *
     * @return array<array-key, int>
     */
    private static function depthCache(array $childrenOf, array $parentOf): array
    {
        $depthCache = [];

        foreach (self::collectAllIds($parentOf) as $id) {
            self::deepestDescendantDistance($childrenOf, $id, $depthCache);
        }

        return $depthCache;
    }

    /**
     * Invert a parent-of map into a children-of map. The two views carry the
     * same information; the downward walk needs the children-side for an
     * efficient per-individual DFS.
     *
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     *
     * @return array<array-key, list<string>>
     */
    private static function invertToChildrenMap(array $parentOf): array
    {
        $childrenOf = [];

        foreach ($parentOf as $childId => [$father, $mother]) {
            // A numeric-only XREF key ("54") is coerced to int by PHP;
            // cast back so the children-of lists stay string-typed.
            $childIdString = (string) $childId;

            if ($father !== null) {
                $childrenOf[$father][] = $childIdString;
            }

            if ($mother !== null) {
                $childrenOf[$mother][] = $childIdString;
            }
        }

        return $childrenOf;
    }

    /**
     * Iteratively walk downward from `$id` and write the largest generation
     * distance to any leaf descendant into `$depthCache[$id]`. Memoised so the
     * same descendant is not re-explored when reached through multiple
     * ancestors.
     *
     * @param array<array-key, list<string>> $childrenOf
     * @param array<array-key, int>          $depthCache In/out cache, mutated on every call
     */
    private static function deepestDescendantDistance(array $childrenOf, string $id, array &$depthCache): void
    {
        self::walkDeepestDistance(
            $id,
            $depthCache,
            static fn (string $current): array => $childrenOf[$current] ?? [],
        );
    }

    /**
     * Iterative-DFS skeleton shared by {@see deepestDescendantDistance()} and
     * {@see deepestAncestorDistance()}: memoise the LONGEST generation distance
     * from every reachable node to a leaf — `depth(node) = 1 + max(depth(child))`,
     * `0` for a leaf — following whichever half of the parentage graph
     * `$neighbours` exposes. The walk is a post-order pass over an explicit
     * stack (so deep chains stay off PHP's recursion budget), and the per-walk
     * on-path set breaks cycles: a back-edge to a node still being resolved on
     * the current path contributes nothing, so an accidentally-cyclic FAMC/FAMS
     * edit cannot loop — it is absorbed to a small finite depth and does NOT
     * reach the cap. Each depth is clamped to `MAX_DEPTH`, so only a genuine
     * ≥100-generation ACYCLIC descent saturates there and trips the `capped`
     * data-quality signal; corrupt cyclic parentage degrades silently.
     *
     * A plain visited-set DFS is WRONG here: it marks a node at the first
     * (possibly shorter) depth it is reached and skips the deeper re-visit, so a
     * node reachable via two paths of UNEQUAL length (asymmetric pedigree
     * collapse) is under-counted whenever the shorter path is explored first —
     * an iteration-order-dependent defect. The post-order memoisation records
     * each node's depth exactly once and order-independently (GH-161).
     *
     * @param array<array-key, int>          $cache      In/out cache, mutated on every call; every reachable node is memoised
     * @param callable(string): list<string> $neighbours Next ids to walk from a given node; an empty list ends the branch
     */
    private static function walkDeepestDistance(string $id, array &$cache, callable $neighbours): void
    {
        if (isset($cache[$id])) {
            return;
        }

        // Stack frames are [node, leaving, children]: a node is first popped to
        // be ENTERED (its neighbour list resolved once and its children
        // scheduled), then popped again to LEAVE (its depth computed from the
        // now-resolved children). The neighbour list is carried in the LEAVE
        // frame so the closure is resolved exactly once per node, not twice.
        /** @var list<array{0: string, 1: bool, 2: list<string>|null}> $stack */
        $stack  = [[$id, false, null]];
        $onPath = [];

        while ($stack !== []) {
            [$node, $leaving, $children] = array_pop($stack);

            if ($leaving) {
                $deepest = 0;

                foreach ($children ?? [] as $child) {
                    if (isset($cache[$child])) {
                        $deepest = max($deepest, $cache[$child] + 1);
                    }
                }

                $cache[$node] = min($deepest, self::MAX_DEPTH);
                unset($onPath[$node]);

                continue;
            }

            // A node already memoised (reached through another branch) or
            // already on the current path (a second parent scheduled it before
            // it was entered) needs no second ENTER.
            if (isset($cache[$node])) {
                continue;
            }

            if (isset($onPath[$node])) {
                continue;
            }

            $onPath[$node] = true;
            $children      = $neighbours($node);
            $stack[]       = [$node, true, $children];

            foreach ($children as $child) {
                // Skip resolved children and back-edges to the current path
                // (the cycle guard); both are read back during the LEAVE pass.
                if (isset($cache[$child])) {
                    continue;
                }

                if (isset($onPath[$child])) {
                    continue;
                }

                $stack[] = [$child, false, null];
            }
        }
    }

    /**
     * Walk upward from a leaf-descendant by following its parents, yielding the
     * eldest-first chain that ends at `$leafId`. At every parent step the parent
     * with the DEEPEST recorded upward distance is preferred; ties break by
     * PHP's default `sort()` ordering (numeric for digit-only XREFs, lexical
     * otherwise). Returns null when no chain of length `$maxDepth` can be
     * reconstructed from the leaf — usually because the leaf is not actually at
     * the bottom of a max-depth chain.
     *
     * Following the deepest parent — rather than the one whose distance is
     * exactly `remaining - 1` — is the upward mirror of {@see walkDeepestDistance()}
     * and is what keeps the walk on the longest line through the over-cap region
     * of a >`MAX_DEPTH`-generation ancestry: there every node is clamped to the
     * same `MAX_DEPTH`, so no parent carries `remaining - 1` and an exact-decrement
     * step would dead-end at the leaf (issue #167). Below the cap the deepest
     * parent always carries exactly `remaining - 1`, so this is identical to the
     * exact-decrement walk for every tree that never trips `capped`.
     *
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     * @param array<array-key, int>                                   $upDistance
     *
     * @return list<string>|null
     */
    public static function walkUpFromLeaf(array $parentOf, array $upDistance, string $leafId, int $maxDepth): ?array
    {
        if (($upDistance[$leafId] ?? -1) !== $maxDepth) {
            return null;
        }

        $chainReversed = [$leafId];
        $current       = $leafId;
        $remainingUp   = $maxDepth;

        while (($remainingUp > 0) && isset($parentOf[$current])) {
            [$father, $mother] = $parentOf[$current];
            $parents           = [];

            if ($father !== null) {
                $parents[] = $father;
            }

            if ($mother !== null) {
                $parents[] = $mother;
            }

            if ($parents === []) {
                break;
            }

            sort($parents);
            $next     = null;
            $bestDist = -1;

            foreach ($parents as $parentId) {
                $parentDist = $upDistance[$parentId] ?? -1;

                // Strictly greater keeps the FIRST parent at the maximum
                // distance; parents are pre-sorted, so ties resolve to the
                // lexically (or numerically) smallest XREF — the documented
                // tie-break.
                if ($parentDist > $bestDist) {
                    $bestDist = $parentDist;
                    $next     = $parentId;
                }
            }

            if ($next === null) {
                break;
            }

            $chainReversed[] = $next;
            $current         = $next;
            --$remainingUp;
        }

        if ($remainingUp !== 0) {
            return null;
        }

        return array_reverse($chainReversed);
    }

    /**
     * Compute the longest upward (= ancestor-side) chain length per individual
     * id — analogous to {@see depthCache()} but pointed in the other direction.
     * Used by callers that need to start the chain reconstruction at a known
     * leaf descendant rather than at a known root ancestor.
     *
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     *
     * @return array<array-key, int>
     */
    public static function upDistanceCache(array $parentOf): array
    {
        $cache = [];

        foreach (self::collectAllIds($parentOf) as $id) {
            self::deepestAncestorDistance($parentOf, $id, $cache);
        }

        return $cache;
    }

    /**
     * Flatten the `parentOf` map into every individual the graph mentions —
     * children PLUS any non-null parent on the right side of an entry. Returned
     * as a list of XREFs in insertion order; callers iterate it to seed both
     * `depthCache()` and `upDistanceCache()` so a single tree-wide DFS sweep
     * covers every node, including individuals that appear only as a parent
     * (i.e. have no recorded parents of their own).
     *
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     *
     * @return list<string>
     */
    private static function collectAllIds(array $parentOf): array
    {
        $allIds = [];

        foreach ($parentOf as $childId => $parents) {
            $allIds[$childId] = true;

            [$father, $mother] = $parents;

            if ($father !== null) {
                $allIds[$father] = true;
            }

            if ($mother !== null) {
                $allIds[$mother] = true;
            }
        }

        // array_keys() returns int for numeric-only XREFs ("54"),
        // which PHP coerced when they indexed $allIds. Cast each back
        // to string so the depth/up-distance walks — and ultimately
        // Registry::make() — receive the string type they expect.
        return array_map(static fn (int|string $id): string => (string) $id, array_keys($allIds));
    }

    /**
     * Mirror of {@see deepestDescendantDistance()} that walks parents instead
     * of children. Both delegate to the shared {@see walkDeepestDistance()}
     * skeleton, supplying the neighbour resolver for their half of the
     * parentage graph.
     *
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     * @param array<array-key, int>                                   $cache    In/out cache, mutated on every call
     */
    private static function deepestAncestorDistance(array $parentOf, string $id, array &$cache): void
    {
        self::walkDeepestDistance(
            $id,
            $cache,
            static function (string $current) use ($parentOf): array {
                if (!isset($parentOf[$current])) {
                    return [];
                }

                [$father, $mother] = $parentOf[$current];
                $parents           = [];

                if ($father !== null) {
                    $parents[] = $father;
                }

                if ($mother !== null) {
                    $parents[] = $mother;
                }

                return $parents;
            },
        );
    }
}
