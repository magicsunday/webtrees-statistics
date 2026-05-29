<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Calc;

use function array_intersect_key;

/**
 * Pure helper that decides whether two individuals share a common ancestor
 * within a bounded number of generations. Used by the endogamy metric to flag
 * cousin marriages and pedigree collapse — frequent in rural pre-industrial
 * trees, rare in well-mixed urban ones.
 *
 * The walk is bounded by `$depth` (typical: 4 = great-great- grandparents). At
 * depth 4 each side has at most 2 + 4 + 8 + 16 = 30 ancestors, so a per-couple
 * intersection runs in O(60) — fast enough to scan an entire tree's families in
 * one pass.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class Endogamy
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Walk `$id` upward through the parent-of map for at most `$depth`
     * generations, returning the set of distinct ancestor ids as an associative
     * `[id => true]` map (so intersection via `array_intersect_key` is
     * O(min(|A|, |B|)) instead of O(|A|·|B|)).
     *
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     *
     * @return array<array-key, bool>
     */
    public static function ancestorSet(array $parentOf, string $id, int $depth): array
    {
        if ($depth <= 0) {
            return [];
        }

        // BFS by generation: each iteration of the outer loop drains
        // one generation's worth of frontier nodes and queues their
        // parents for the next. Visiting in level order guarantees
        // every reachable ancestor is recorded at its minimum depth;
        // a DFS variant here would lock an ancestor to whichever
        // longer path it was reached by first and silently truncate
        // its own parent expansion below the depth budget.
        $ancestors = [];
        $frontier  = [$id];

        for ($d = 0; $d < $depth; ++$d) {
            $next = [];

            foreach ($frontier as $node) {
                if (!isset($parentOf[$node])) {
                    continue;
                }

                [$father, $mother] = $parentOf[$node];

                if (($father !== null) && !isset($ancestors[$father])) {
                    $ancestors[$father] = true;
                    $next[]             = $father;
                }

                if (($mother !== null) && !isset($ancestors[$mother])) {
                    $ancestors[$mother] = true;
                    $next[]             = $mother;
                }
            }

            if ($next === []) {
                break;
            }

            $frontier = $next;
        }

        return $ancestors;
    }

    /**
     * Return the intersection of the two individuals' ancestor sets within
     * `$depth` generations. Empty intersection means no common-ancestor
     * evidence within that depth.
     *
     * @param array<array-key, array{0: string|null, 1: string|null}> $parentOf
     *
     * @return array<array-key, bool>
     */
    public static function sharedAncestors(array $parentOf, string $a, string $b, int $depth): array
    {
        return array_intersect_key(
            self::ancestorSet($parentOf, $a, $depth),
            self::ancestorSet($parentOf, $b, $depth),
        );
    }
}
