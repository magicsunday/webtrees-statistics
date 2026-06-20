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
use function count;
use function intdiv;
use function strcmp;
use function usort;

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
