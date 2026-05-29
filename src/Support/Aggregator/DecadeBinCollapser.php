<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use Closure;

use function array_keys;
use function array_slice;
use function array_sum;
use function array_values;
use function ceil;
use function count;
use function ksort;
use function min;

/**
 * Collapses a `[decade => value]` time-series into a smaller number of
 * equal-width bins. Two folding strategies, selected by the caller depending on
 * the value semantics of the input series:
 *
 *  * {@see collapseCumulative()} — takes the LAST value per bin
 *    (preserves the running-total invariant of cumulative series).
 *  * {@see collapseCounts()} — takes the SUM of values per bin
 *    (preserves the total-count invariant of per-decade counts).
 *
 * The bin size is chosen from a fixed ladder (1, 2, 5, 10, 20, 50, 100 decades)
 * so the resulting axis aligns to round chronological units — 100 decades =
 * millennium, 10 = century, 5 = half-century.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DecadeBinCollapser
{
    /**
     * Default ceiling on the rendered point count. Sized for a 12-col grid card
     * hosting a line chart at ~700 px wide — beyond this density the per-decade
     * dots overlap into a solid band and the area-fill obscures the curve
     * underneath.
     */
    public const int DEFAULT_MAX_POINTS = 80;

    /**
     * Candidate bin widths in decades. Picked to align with familiar
     * chronological units: 2 decades = 20 years, 5 = half-century, 10 =
     * century, 50 = half-millennium, 100 = millennium.
     *
     * @var list<int>
     */
    private const array BIN_LADDER = [1, 2, 5, 10, 20, 50, 100];

    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Collapse a cumulative `[decade => runningTotal]` map into at most
     * `$maxPoints` bins. Each output entry is keyed by the earliest
     * decade-start in its bin and carries the cumulative total at the latest
     * decade in the bin — the cumulative invariant ("value at year X = total up
     * to and including X") is preserved, just sampled less densely.
     *
     * No-op for inputs of `<= $maxPoints` entries: the original series passes
     * through unchanged.
     *
     * @param array<int, int> $byDecade  Decade-start year → cumulative running total
     * @param int             $maxPoints Upper bound on the returned point count
     *
     * @return array<int, int> Decade-start year → cumulative running total
     */
    public static function collapseCumulative(array $byDecade, int $maxPoints = self::DEFAULT_MAX_POINTS): array
    {
        return self::collapse(
            $byDecade,
            $maxPoints,
            static fn (array $binValues): int => $binValues[count($binValues) - 1],
        );
    }

    /**
     * Collapse a per-decade `[decade => count]` map into at most `$maxPoints`
     * bins. Each output entry is keyed by the earliest decade-start in its bin
     * and carries the SUM of decade counts in the bin — the total-count
     * invariant (`array_sum($collapsed) === array_sum($original)`) is
     * preserved.
     *
     * No-op for inputs of `<= $maxPoints` entries.
     *
     * @param array<int, int> $byDecade  Decade-start year → count
     * @param int             $maxPoints Upper bound on the returned point count
     *
     * @return array<int, int> Decade-start year → summed count
     */
    public static function collapseCounts(array $byDecade, int $maxPoints = self::DEFAULT_MAX_POINTS): array
    {
        return self::collapse(
            $byDecade,
            $maxPoints,
            static fn (array $binValues): int => array_sum($binValues),
        );
    }

    /**
     * Generic bin-collapse driver. Picks the smallest bin width that meets the
     * cap, then folds each bin via `$reducer`.
     *
     * @param array<int, int>         $byDecade
     * @param int                     $maxPoints
     * @param Closure(list<int>): int $reducer   Picks the bin's representative value (last, sum, mean, …)
     *
     * @return array<int, int>
     */
    private static function collapse(array $byDecade, int $maxPoints, Closure $reducer): array
    {
        if ($maxPoints < 1) {
            $maxPoints = 1;
        }

        if (count($byDecade) <= $maxPoints) {
            return $byDecade;
        }

        // Keys come from the repository ksort'ed already, but a
        // belt-and-braces sort guards against callers that hand in
        // an unordered map.
        ksort($byDecade);

        $decadesPerBin = self::pickBinSize(count($byDecade), $maxPoints);
        $keys          = array_keys($byDecade);
        $values        = array_values($byDecade);
        $count         = count($keys);

        $collapsed = [];

        for ($i = 0; $i < $count; $i += $decadesPerBin) {
            $binSize              = min($decadesPerBin, $count - $i);
            $binValues            = array_slice($values, $i, $binSize);
            $collapsed[$keys[$i]] = $reducer($binValues);
        }

        return $collapsed;
    }

    /**
     * Smallest ladder entry that produces no more than `$maxPoints` bins for
     * `$totalDecades` data points. Falls back to the derived raw bin size when
     * even the largest ladder rung (100 decades) is too coarse, so the cap is
     * still respected for absurdly large inputs.
     *
     * Returns `1` (no collapse) when the input already fits the cap. Public so
     * callers can adapt their card titles / sub-headlines to the granularity
     * the collapser will actually use (e.g. a "Births by decade" card needs to
     * read "Births by century" once the series collapses 10:1).
     */
    public static function pickBinSize(int $totalDecades, int $maxPoints = self::DEFAULT_MAX_POINTS): int
    {
        if ($maxPoints < 1) {
            $maxPoints = 1;
        }

        if ($totalDecades <= $maxPoints) {
            return 1;
        }

        $rawBinSize = (int) ceil($totalDecades / $maxPoints);

        foreach (self::BIN_LADDER as $candidate) {
            if ($candidate >= $rawBinSize) {
                return $candidate;
            }
        }

        return $rawBinSize;
    }
}
