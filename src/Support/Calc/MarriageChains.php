<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Calc;

use function array_keys;
use function array_pop;
use function array_reverse;
use function array_shift;
use function count;
use function intdiv;
use function sort;
use function strcmp;
use function usort;

use const SORT_STRING;

/**
 * Pure helper that splits a tree-wide marriage-graph adjacency map — `xref →
 * [spouse-xref, …]`, as produced by {@see \MagicSunday\Webtrees\Statistic\Repository\MarriageMapRepository} —
 * into its connected components ("marriage chains" / "marriage webs") and
 * reports the largest of them.
 *
 * A component is the maximal set of people reachable from one another by
 * marriage edges; only components of THREE OR MORE people are reported, since a
 * lone couple is not a "chain". Each qualifying component is returned with its
 * members sorted in byte order, and the component list is sorted by member
 * count descending, ties broken by the byte-order-smallest member XREF — both
 * deterministic, so the output never depends on the adjacency map's insertion
 * order.
 *
 * The traversal is an iterative breadth-first sweep over an explicit queue (no
 * recursion-depth risk on a large web), and is order-independent: every node is
 * visited exactly once and assigned to exactly one component. The internal edge
 * count of a component is `(Σ member degree) / 2` — every undirected marriage
 * edge contributes one to each of its two endpoints' degrees, so summing the
 * degrees double-counts every edge exactly once.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MarriageChains
{
    /**
     * The smallest person count a component must reach to be reported. A lone
     * couple (two people) is excluded — only a genuine chain or web of three or
     * more married people counts.
     */
    public const int MIN_GROUP_SIZE = 3;

    /**
     * Cap on the length of a reconstructed chain. Set deliberately high — the
     * only real purpose is a safety bound so a pathological graph cannot return
     * an unbounded sequence; a genuine marriage chain never approaches it.
     * Mirrors {@see GenerationDepth::MAX_DEPTH}.
     */
    public const int MAX_CHAIN = 100;

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Split the marriage-graph adjacency map into its connected components,
     * keeping only those with at least {@see MIN_GROUP_SIZE} people. Each
     * component's members are sorted in byte order; the components are sorted by
     * member count descending, ties broken by the byte-order-smallest member
     * XREF.
     *
     * @param array<array-key, list<string>> $adjacency Symmetric `xref → [spouse-xref, …]` map
     *
     * @return list<list<string>>
     */
    public static function components(array $adjacency): array
    {
        $visited    = [];
        $components = [];

        foreach (array_keys($adjacency) as $start) {
            // A digit-only XREF ("54") was coerced to int when it indexed the
            // map; cast it back so the component members stay string-typed.
            $startId = (string) $start;

            if (isset($visited[$startId])) {
                continue;
            }

            $members = self::collectComponent($adjacency, $startId, $visited);

            if (count($members) < self::MIN_GROUP_SIZE) {
                continue;
            }

            self::sortMembers($members);

            $components[] = $members;
        }

        self::sortComponents($components);

        return $components;
    }

    /**
     * Return the largest qualifying component's members together with its
     * internal edge count, or `null` when no component reaches
     * {@see MIN_GROUP_SIZE}. The members are byte-order sorted; the edge count is
     * `(Σ member degree) / 2`.
     *
     * @param array<array-key, list<string>> $adjacency Symmetric `xref → [spouse-xref, …]` map
     *
     * @return array{members: list<string>, edges: int}|null
     */
    public static function largestGroup(array $adjacency): ?array
    {
        $components = self::components($adjacency);

        if ($components === []) {
            return null;
        }

        // components() already sorts largest-first with a deterministic
        // tie-break, so the first entry is the largest group.
        $members = $components[0];

        return [
            'members' => $members,
            'edges'   => self::countEdges($adjacency, $members),
        ];
    }

    /**
     * Return the longest marriage chain in the graph as an ordered person
     * sequence — the longest SIMPLE PATH (the graph diameter) across every
     * qualifying component — or `[]` when no component reaches
     * {@see MIN_GROUP_SIZE} people.
     *
     * Why the diameter and not a proband-rooted walk: a chain measured outward
     * from one fixed person (that person's eccentricity) under-reports, because
     * the longest line through the graph often runs leaf-to-leaf across a branch
     * hub the fixed start never crosses. A tree-wide statistic must be
     * start-point independent, so the longest simple path anywhere in the
     * component is the right measure.
     *
     * Each component is diameter-computed by DOUBLE BFS: a breadth-first sweep
     * from the component's smallest-xref node reaches a farthest node `u`; a
     * second sweep from `u` (recording a parent map) reaches a farthest node
     * `v`; the path `u … v` reconstructed through the parent map is a longest
     * simple path. For a cycle-free component — the normal case for a real
     * marriage graph — double BFS is the exact diameter. For a component that
     * contains a cycle (edge count ≥ node count) the longest simple path is
     * NP-hard; the same double BFS is then a DETERMINISTIC lower bound rather
     * than a proven optimum. Real trees do not cycle, so this never bites in
     * practice.
     *
     * Every choice is made deterministic by a byte-order xref tie-break — the
     * same discipline {@see GenerationDepth::walkUpFromLeaf()} uses — so the
     * result is byte-for-byte identical regardless of the insertion order of the
     * adjacency map or of any neighbour list: the BFS starts from the
     * smallest-xref node, a farthest-distance tie picks the smallest-xref
     * candidate, neighbours are visited in sorted order so the parent map is
     * reproducible, within a component the byte-order-smallest oriented diameter
     * path is selected, and across components of equal longest length the
     * lexicographically smallest path sequence wins. The reconstructed path is
     * capped at {@see MAX_CHAIN} as a safety bound.
     *
     * @param array<array-key, list<string>> $adjacency Symmetric `xref → [spouse-xref, …]` map
     *
     * @return list<string>
     */
    public static function longestChain(array $adjacency): array
    {
        $longest = [];

        foreach (self::components($adjacency) as $members) {
            $candidate = self::componentDiameterPath($adjacency, $members);

            if (self::isLongerOrSmaller($candidate, $longest)) {
                $longest = $candidate;
            }
        }

        return $longest;
    }

    /**
     * Compute a longest-simple-path through one connected component via double
     * BFS, deterministically. The component's members are already byte-order
     * sorted, so element `0` is its smallest-xref node and seeds the first sweep
     * deterministically.
     *
     * First BFS from the smallest-xref node finds a farthest endpoint `u`;
     * second BFS from `u` finds the diameter length and the parent map. Double
     * BFS alone would return only ONE of possibly several equal-length diameter
     * paths sharing the endpoint `u` — whichever the BFS parent-routing lands on
     * — so the choice among them is made deterministic: every node at the maximum
     * distance from `u` is the far end of a longest path through `u`, so each
     * such path is reconstructed, oriented (so a path and its mirror cannot
     * compete), and the byte-order-smallest sequence kept. The result is
     * therefore byte-for-byte reproducible regardless of the adjacency map's
     * insertion order. (It is the lexicographically-smallest longest path AMONG
     * those that pass through the first-found endpoint `u`; a different pair of
     * equally-long diameter endpoints that does not include `u` is not
     * enumerated, so the choice is canonical-through-`u`, not globally minimal —
     * which is all a deterministic display statistic needs.)
     *
     * @param array<array-key, list<string>> $adjacency Symmetric `xref → [spouse-xref, …]` map
     * @param list<string>                   $members   The component's people, byte-order sorted
     *
     * @return list<string>
     */
    private static function componentDiameterPath(array $adjacency, array $members): array
    {
        $firstEnd = self::bfsDistances($adjacency, $members[0])['farthest'];

        $sweep    = self::bfsDistances($adjacency, $firstEnd);
        $parent   = $sweep['parent'];
        $maxDepth = $sweep['maxDistance'];

        // Every node at the maximum distance from $firstEnd is the far end of a
        // longest path through it; reconstruct each, orient it, and keep the
        // byte-order-smallest so the within-component result is deterministic
        // (the smallest oriented diameter path through $firstEnd), not whichever
        // single path the BFS parent-routing happened to land on.
        $best = [];

        foreach ($sweep['distance'] as $node => $depth) {
            if ($depth !== $maxDepth) {
                continue;
            }

            // A digit-only XREF key ("54") was coerced to int by PHP when it
            // indexed the distance map; cast it back so the path stays
            // string-typed.
            $candidate = self::orientPath(
                self::reconstructPath($parent, $firstEnd, (string) $node),
            );

            if (self::isLongerOrSmaller($candidate, $best)) {
                $best = $candidate;
            }
        }

        return $best;
    }

    /**
     * Orient a path so it reads in the lexicographically smaller direction:
     * the same simple path can be reconstructed from either endpoint, so the
     * reported sequence is normalised by comparing it against its reverse and
     * keeping the smaller. This makes the result endpoint-order independent — a
     * second discipline on top of the byte-order tie-break, so a path and its
     * mirror never compete as two different answers.
     *
     * @param list<string> $path The reconstructed xref sequence
     *
     * @return list<string>
     */
    private static function orientPath(array $path): array
    {
        $reversed = array_reverse($path);

        if (self::comparePaths($reversed, $path) < 0) {
            return $reversed;
        }

        return $path;
    }

    /**
     * Breadth-first sweep from `$startId` over its connected component. Returns
     * the per-node distance map, the parent map that reconstructs a shortest path
     * back to the start, the maximum distance reached, and the
     * byte-order-smallest node at that maximum distance. Neighbours are enqueued
     * in sorted byte order so the parent map — and therefore every reconstructed
     * path — is independent of the adjacency map's insertion order. The
     * `farthest` endpoint is selected explicitly as the byte-order-smallest node
     * among all those at the maximum distance, so a distance tie never depends on
     * BFS-level enqueue order (which is only sorted within each parent's
     * neighbour list, not globally across a level).
     *
     * @param array<array-key, list<string>> $adjacency Symmetric `xref → [spouse-xref, …]` map
     *
     * @return array{distance: array<array-key, int>, parent: array<array-key, string>, maxDistance: int, farthest: string}
     */
    private static function bfsDistances(array $adjacency, string $startId): array
    {
        $distance = [$startId => 0];
        $parent   = [];
        $queue    = [$startId];

        $farthest    = $startId;
        $maxDistance = 0;

        while ($queue !== []) {
            $node      = array_shift($queue);
            $nodeDepth = $distance[$node];

            $neighbours = $adjacency[$node] ?? [];

            // SORT_STRING (not the default SORT_REGULAR): a digit-only XREF
            // ("54") must order in BYTE order to match the strcmp tie-breaks
            // used everywhere else in this class. SORT_REGULAR would compare
            // "7" and "54" numerically (7 > 54 is false), so the parent map —
            // and therefore the reconstructed interior of the chain — would
            // disagree with the byte-order endpoint and component tie-breaks.
            sort($neighbours, SORT_STRING);

            foreach ($neighbours as $neighbour) {
                if (isset($distance[$neighbour])) {
                    continue;
                }

                $distance[$neighbour] = $nodeDepth + 1;
                $parent[$neighbour]   = $node;
                $queue[]              = $neighbour;

                // A strictly greater distance always replaces the endpoint; an
                // equal distance keeps the byte-order-smaller xref. This makes
                // the farthest pick independent of the order nodes are dequeued
                // at the same level.
                if (
                    ($distance[$neighbour] > $maxDistance)
                    || (($distance[$neighbour] === $maxDistance) && (strcmp($neighbour, $farthest) < 0))
                ) {
                    $maxDistance = $distance[$neighbour];
                    $farthest    = $neighbour;
                }
            }
        }

        return [
            'distance'    => $distance,
            'parent'      => $parent,
            'maxDistance' => $maxDistance,
            'farthest'    => $farthest,
        ];
    }

    /**
     * Reconstruct the path from `$startId` to `$endId` by walking the BFS parent
     * map backwards from the end, then reversing. The walk is bounded by
     * {@see MAX_CHAIN} as a safety stop so a corrupt parent map cannot loop.
     *
     * @param array<array-key, string> $parent  BFS parent map, `child → parent`
     * @param string                   $startId The sweep's start node
     * @param string                   $endId   The farthest node to walk back from
     *
     * @return list<string>
     */
    private static function reconstructPath(array $parent, string $startId, string $endId): array
    {
        $reversed = [$endId];
        $current  = $endId;

        while (($current !== $startId) && isset($parent[$current]) && (count($reversed) < self::MAX_CHAIN)) {
            $current    = $parent[$current];
            $reversed[] = $current;
        }

        return array_reverse($reversed);
    }

    /**
     * Decide whether `$candidate` should replace `$current` as the longest
     * chain: it wins when it is strictly longer, or — across components of equal
     * longest length — when its xref sequence is lexicographically smaller. This
     * is the cross-component tie-break that keeps the result deterministic.
     *
     * @param list<string> $candidate The newly computed component path
     * @param list<string> $current   The best path found so far
     */
    private static function isLongerOrSmaller(array $candidate, array $current): bool
    {
        $byLength = count($candidate) <=> count($current);

        if ($byLength !== 0) {
            return $byLength > 0;
        }

        // Equal length (including the initial empty $current vs an empty
        // candidate, which never happens for a qualifying component): prefer the
        // lexicographically smaller xref sequence.
        return self::comparePaths($candidate, $current) < 0;
    }

    /**
     * Compare two equal-length xref sequences element-by-element in byte order,
     * returning a negative, zero, or positive integer like {@see strcmp()}.
     *
     * @param list<string> $left  The first xref sequence
     * @param list<string> $right The second xref sequence
     */
    private static function comparePaths(array $left, array $right): int
    {
        foreach ($left as $index => $value) {
            $comparison = strcmp($value, $right[$index] ?? '');

            if ($comparison !== 0) {
                return $comparison;
            }
        }

        return 0;
    }

    /**
     * Breadth-first sweep collecting every person reachable from `$startId`
     * along marriage edges, marking each as visited. The walk uses an explicit
     * queue so a large connected web cannot overflow PHP's recursion budget.
     *
     * @param array<array-key, list<string>> $adjacency Symmetric `xref → [spouse-xref, …]` map
     * @param array<string, true>            $visited   In/out visited set, mutated on every call
     *
     * @return list<string>
     */
    private static function collectComponent(array $adjacency, string $startId, array &$visited): array
    {
        $members           = [];
        $queue             = [$startId];
        $visited[$startId] = true;

        while ($queue !== []) {
            $node      = array_pop($queue);
            $members[] = $node;

            foreach ($adjacency[$node] ?? [] as $neighbour) {
                if (isset($visited[$neighbour])) {
                    continue;
                }

                $visited[$neighbour] = true;
                $queue[]             = $neighbour;
            }
        }

        return $members;
    }

    /**
     * Count the marriage edges internal to a component: every undirected edge
     * appears once in each endpoint's neighbour list, so the summed degree of
     * the members double-counts each internal edge exactly once.
     *
     * @param array<array-key, list<string>> $adjacency Symmetric `xref → [spouse-xref, …]` map
     * @param list<string>                   $members   The component's people
     */
    private static function countEdges(array $adjacency, array $members): int
    {
        $degreeSum = 0;

        foreach ($members as $member) {
            $degreeSum += count($adjacency[$member] ?? []);
        }

        return intdiv($degreeSum, 2);
    }

    /**
     * Sort a component's members in ascending byte order, in place.
     *
     * @param list<string> $members The component's people, sorted in place
     */
    private static function sortMembers(array &$members): void
    {
        usort($members, strcmp(...));
    }

    /**
     * Sort the component list by member count descending, ties broken by the
     * byte-order-smallest member XREF ascending, in place. Each component's
     * members are already byte-order sorted, so element `0` is its smallest.
     *
     * @param list<list<string>> $components The qualifying components, sorted in place
     */
    private static function sortComponents(array &$components): void
    {
        usort(
            $components,
            static function (array $left, array $right): int {
                $bySize = count($right) <=> count($left);

                if ($bySize !== 0) {
                    return $bySize;
                }

                return strcmp($left[0], $right[0]);
            },
        );
    }
}
