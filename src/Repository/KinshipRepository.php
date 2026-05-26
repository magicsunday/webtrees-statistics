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

use function array_fill_keys;
use function array_keys;
use function array_sum;
use function array_unique;
use function count;
use function intdiv;
use function round;

/**
 * Aggregate kinship-statistics for the Family tab.
 *
 * The two metrics this repository surfaces both lean on the same
 * in-memory parent-of map (built once per call from one bulk SQL
 * query) so the per-individual ancestor walk runs in linear time
 * even on trees with thousands of individuals:
 *
 *   * Ancestor-count distribution — histogram of "how many of an
 *     individual's direct ancestors (up to {@see ANCESTOR_DEPTH}
 *     generations) are recorded in the tree".
 *
 *   * Average pedigree-completeness index — Lacy 1989: the mean,
 *     across every individual, of `known_at_gen / 2^gen` summed
 *     over the {@see ANCESTOR_DEPTH} generations.
 *
 * The depth cap keeps the walk O(N · 2^DEPTH) and the histogram
 * bucket count bounded; deeper analyses belong in a chart module,
 * not an aggregate widget.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class KinshipRepository
{
    /**
     * How many generations of direct ancestors to walk. At depth=4
     * the maximum theoretical ancestor count is 2+4+8+16 = 30
     * (parents through great-great-grandparents), which is the
     * widget's histogram domain.
     */
    private const int ANCESTOR_DEPTH = 4;

    /**
     * Histogram-bucket width for the known-ancestor count. Buckets
     * are 0-2, 3-5, 6-8 … so the rendering stays legible.
     */
    private const int ANCESTOR_BUCKET = 3;

    /**
     * Per-instance memo for the per-generation ancestor walk. Both
     * `ancestorCountDistribution()` and `averagePedigreeCompleteness()`
     * iterate every individual and ask `countKnownPerGeneration` for
     * the same walk — caching keeps it at one BFS per individual
     * instead of two.
     *
     * @var array<string, array<int, int>>
     */
    private array $perGenCache = [];

    /**
     * @param Tree                $tree                The tree the statistics are computed for
     * @param ParentMapRepository $parentMapRepository Shared parent-of map provider (FAMC + FAM scan)
     */
    public function __construct(
        private readonly Tree $tree,
        private readonly ParentMapRepository $parentMapRepository,
    ) {
    }

    /**
     * Histogram of known-ancestor counts per individual, walked up
     * to {@see ANCESTOR_DEPTH} generations. Buckets are 3-wide so
     * a population of 1000 individuals doesn't stretch into 30
     * single-width bars.
     *
     * @return array<string, int>
     */
    public function ancestorCountDistribution(): array
    {
        $parentOf  = $this->parentMapRepository->build();
        $maxCount  = (2 ** (self::ANCESTOR_DEPTH + 1)) - 2;
        $bucketMin = 0;

        $buckets = [];

        while ($bucketMin <= $maxCount) {
            $buckets[$bucketMin . '–' . ($bucketMin + self::ANCESTOR_BUCKET - 1)] = 0;
            $bucketMin += self::ANCESTOR_BUCKET;
        }

        $individuals = TreeScope::table($this->tree, 'individuals')
            ->select(['i_id'])
            ->get();

        foreach ($individuals as $row) {
            $id = RowCast::string($row, 'i_id');

            if ($id === '') {
                continue;
            }

            $known = $this->countKnownAncestors($parentOf, $id);
            $label = $this->bucketLabel($known, $maxCount);

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Mean pedigree-completeness index across every individual in
     * the tree (Lacy 1989: sum over generations of
     * `known_at_gen / 2^gen`, averaged across the population).
     * Returns a fraction 0.0–1.0; 1.0 means every individual has
     * a fully-populated pedigree up to {@see ANCESTOR_DEPTH}
     * generations.
     */
    public function averagePedigreeCompleteness(): float
    {
        $parentOf    = $this->parentMapRepository->build();
        $individuals = TreeScope::table($this->tree, 'individuals')
            ->select(['i_id'])
            ->get();

        if (count($individuals) === 0) {
            return 0.0;
        }

        $perGenerationMax = [];

        for ($gen = 1; $gen <= self::ANCESTOR_DEPTH; ++$gen) {
            $perGenerationMax[$gen] = 2 ** $gen;
        }

        $total = 0.0;
        $count = 0;

        foreach ($individuals as $row) {
            $id = RowCast::string($row, 'i_id');

            if ($id === '') {
                continue;
            }

            $perGenKnown = $this->countKnownPerGeneration($parentOf, $id);
            $pc          = 0.0;

            foreach ($perGenerationMax as $gen => $max) {
                $known = $perGenKnown[$gen] ?? 0;
                $pc += $known / ($max * self::ANCESTOR_DEPTH);
            }

            $total += $pc;
            ++$count;
        }

        return round($total / $count, 4);
    }

    /**
     * Count the distinct known ancestors of `$id` up to
     * {@see ANCESTOR_DEPTH} generations, deduplicating by ID so a
     * tree with pedigree collapse (cousin marriage) doesn't
     * inflate the count.
     *
     * @param array<string, array{0: string|null, 1: string|null}> $parentOf
     */
    private function countKnownAncestors(array $parentOf, string $id): int
    {
        $perGen = $this->countKnownPerGeneration($parentOf, $id);

        return array_sum($perGen);
    }

    /**
     * Walk the ancestor tree breadth-first; return a
     * `[generation => count]` map for the requested depth.
     *
     * @param array<string, array{0: string|null, 1: string|null}> $parentOf
     *
     * @return array<int, int>
     */
    private function countKnownPerGeneration(array $parentOf, string $id): array
    {
        if (isset($this->perGenCache[$id])) {
            return $this->perGenCache[$id];
        }

        $perGen  = array_fill_keys(array_keys([1 => 0, 2 => 0, 3 => 0, 4 => 0]), 0);
        $current = [$id];

        for ($gen = 1; $gen <= self::ANCESTOR_DEPTH; ++$gen) {
            $next = [];

            foreach ($current as $personId) {
                if (!isset($parentOf[$personId])) {
                    continue;
                }

                [$father, $mother] = $parentOf[$personId];

                if ($father !== null) {
                    $next[] = $father;
                }

                if ($mother !== null) {
                    $next[] = $mother;
                }
            }

            $unique       = array_unique($next);
            $perGen[$gen] = count($unique);
            $current      = $unique;
        }

        $this->perGenCache[$id] = $perGen;

        return $perGen;
    }

    private function bucketLabel(int $value, int $maxCount): string
    {
        if ($value > $maxCount) {
            $value = $maxCount;
        }

        $lower = intdiv($value, self::ANCESTOR_BUCKET) * self::ANCESTOR_BUCKET;

        return $lower . '–' . ($lower + self::ANCESTOR_BUCKET - 1);
    }
}
