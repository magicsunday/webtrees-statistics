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
use function sort;

/**
 * Pure helper that computes per-individual generation depth from a parent-of
 * map. "Depth" is the number of generations recorded BELOW an individual: a
 * leaf (no descendants in the tree) has depth 0, an individual whose only
 * descendants are direct children has depth 1, and so on. The tree-wide maximum
 * is the longest recorded vertical descent.
 *
 * The walk is bounded by `MAX_DEPTH` so an accidentally-cyclic GEDCOM (rare but
 * possible — self-referential FAMC + FAMS edits) cannot loop forever. Within a
 * single individual's DFS, a visited-set protects against the more mundane case
 * of pedigree-collapse where the same descendant could be re-walked through
 * different lines.
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
     * @return array{maxDepth: int, distribution: array<int, int>, capped: bool, deepestChain: list<string>, deepestChainCandidates: list<string>}
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
            'maxDepth'               => $maxDepth,
            'distribution'           => $distribution,
            'capped'                 => $capped,
            'deepestChain'           => self::pickDeepestChain($childrenOf, $depthCache, $maxDepth),
            'deepestChainCandidates' => self::collectMaxDepthRoots($depthCache, $maxDepth),
        ];
    }

    /**
     * Return every individual that sits at the tree-wide maximum depth — i.e.
     * every eldest ancestor whose deepest verified descendant lies maxDepth
     * generations below. The repository uses these as the candidate set for
     * picking a preferred chain (e.g. by the leaf's birth-year), so the helper
     * stays DB-free and the preference is a separate concern.
     *
     * @param array<array-key, int> $depthCache
     *
     * @return list<string>
     */
    private static function collectMaxDepthRoots(array $depthCache, int $maxDepth): array
    {
        if ($maxDepth === 0) {
            return [];
        }

        $roots = [];

        foreach ($depthCache as $id => $depth) {
            if ($depth === $maxDepth) {
                // Numeric-only XREFs ("54") surface here as integer
                // array keys; cast back to string so the chain stays
                // string-typed all the way to Registry::make().
                $roots[] = (string) $id;
            }
        }

        sort($roots);

        return $roots;
    }

    /**
     * Walk down from `$rootId` through children whose recorded
     * deepest-descendant distance equals exactly `remaining-1`, yielding the
     * eldest-first chain of length `$maxDepth + 1`. Ties at any hop are broken
     * by PHP's default `sort()` ordering (numeric for digit-only XREFs, lexical
     * otherwise) so the result is reproducible.
     *
     * @param array<array-key, list<string>> $childrenOf
     * @param array<array-key, int>          $depthCache
     *
     * @return list<string>
     */
    public static function walkDownFrom(array $childrenOf, array $depthCache, string $rootId, int $maxDepth): array
    {
        $chain         = [$rootId];
        $current       = $rootId;
        $remainingHops = $maxDepth;

        while (($remainingHops > 0) && isset($childrenOf[$current])) {
            $nextHopDepth = $remainingHops - 1;
            $children     = $childrenOf[$current];
            sort($children);
            $next = null;

            foreach ($children as $childId) {
                if (($depthCache[$childId] ?? -1) === $nextHopDepth) {
                    $next = $childId;

                    break;
                }
            }

            if ($next === null) {
                break;
            }

            $chain[]       = $next;
            $current       = $next;
            $remainingHops = $nextHopDepth;
        }

        return $chain;
    }

    /**
     * Build the children-of map from a parent-of map. Public so the repository
     * can reuse the same inverted view when it walks a non-default candidate
     * root.
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
     * Re-run the per-individual deepest-descendant walk that {@see compute()}
     * already performs, exposed publicly so the repository can fold a preferred
     * root into a full chain without duplicating the cache.
     *
     * @param array<array-key, list<string>>                          $childrenOf
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     *
     * @return array<array-key, int>
     */
    public static function depthCache(array $childrenOf, array $parentOf): array
    {
        $depthCache = [];

        foreach (self::collectAllIds($parentOf) as $id) {
            self::deepestDescendantDistance($childrenOf, $id, $depthCache);
        }

        return $depthCache;
    }

    /**
     * Pick one concrete deepest chain so the widget can name actual
     * individuals: starts at the eldest ancestor (one whose deepest-descendant
     * distance equals the tree-wide maximum) and walks down through children
     * that themselves carry exactly the remaining depth budget. Ties are broken
     * by PHP's default `sort()` ordering (numeric for digit-only XREFs, lexical
     * otherwise) so the result is stable across runs even when several chains
     * share the same maximum length.
     *
     * @param array<array-key, list<string>> $childrenOf
     * @param array<array-key, int>          $depthCache
     *
     * @return list<string>
     */
    private static function pickDeepestChain(array $childrenOf, array $depthCache, int $maxDepth): array
    {
        if ($maxDepth === 0) {
            return [];
        }

        $candidates = self::collectMaxDepthRoots($depthCache, $maxDepth);

        if ($candidates === []) {
            return [];
        }

        return self::walkDownFrom($childrenOf, $depthCache, $candidates[0], $maxDepth);
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
        if (isset($depthCache[$id])) {
            return;
        }

        // DFS with an explicit stack so deep chains don't blow PHP's
        // recursion budget. The "visited on this walk" set kills any
        // cycle the moment it would loop back on itself.
        /** @var list<array{0: string, 1: int}> $stack */
        $stack   = [[$id, 0]];
        $visited = [];
        $deepest = 0;

        while ($stack !== []) {
            [$current, $depth] = array_pop($stack);

            if (isset($visited[$current])) {
                continue;
            }

            if ($depth > self::MAX_DEPTH) {
                continue;
            }

            $visited[$current] = true;
            $deepest           = max($deepest, $depth);

            if (!isset($childrenOf[$current])) {
                continue;
            }

            foreach ($childrenOf[$current] as $childId) {
                $stack[] = [$childId, $depth + 1];
            }
        }

        $depthCache[$id] = $deepest;
    }

    /**
     * Walk upward from a leaf-descendant by following its parents, yielding the
     * eldest-first chain that ends at `$leafId`. At every parent step the
     * parent with the larger remaining upward distance is preferred; ties break
     * by PHP's default `sort()` ordering (numeric for digit-only XREFs, lexical
     * otherwise). Returns null when no chain of length `$maxDepth` can be
     * reconstructed from the leaf — usually because the leaf is not actually at
     * the bottom of a max-depth chain.
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
            $needed            = $remainingUp - 1;
            $candidates        = [];

            if (($father !== null) && (($upDistance[$father] ?? -1) === $needed)) {
                $candidates[] = $father;
            }

            if (($mother !== null) && (($upDistance[$mother] ?? -1) === $needed)) {
                $candidates[] = $mother;
            }

            if ($candidates === []) {
                return null;
            }

            sort($candidates);
            $next            = $candidates[0];
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
     * of children. The two functions share the same iterative-DFS skeleton but
     * operate on opposite halves of the parentage graph.
     *
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     * @param array<array-key, int>                                   $cache    In/out cache, mutated on every call
     */
    private static function deepestAncestorDistance(array $parentOf, string $id, array &$cache): void
    {
        if (isset($cache[$id])) {
            return;
        }

        /** @var list<array{0: string, 1: int}> $stack */
        $stack   = [[$id, 0]];
        $visited = [];
        $deepest = 0;

        while ($stack !== []) {
            [$current, $depth] = array_pop($stack);

            if (isset($visited[$current])) {
                continue;
            }

            if ($depth > self::MAX_DEPTH) {
                continue;
            }

            $visited[$current] = true;
            $deepest           = max($deepest, $depth);

            if (!isset($parentOf[$current])) {
                continue;
            }

            [$father, $mother] = $parentOf[$current];

            if ($father !== null) {
                $stack[] = [$father, $depth + 1];
            }

            if ($mother !== null) {
                $stack[] = [$mother, $depth + 1];
            }
        }

        $cache[$id] = $deepest;
    }
}
