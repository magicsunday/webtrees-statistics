<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Aggregator;

use MagicSunday\Webtrees\Statistic\Support\Aggregator\DecadeBinCollapser;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill_keys;
use function array_keys;
use function array_sum;
use function array_values;
use function count;
use function range;

/**
 * Verifies the equal-width decade-binning helper that both Overview (cumulative
 * tree growth) and Life-span (births by decade) lean on to keep the line-chart
 * readable for long birth windows.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class DecadeBinCollapserTest extends TestCase
{
    /**
     * Inputs already under the cap pass through untouched — the collapser must
     * be a no-op for the common per-tree case where the birth window fits the
     * readability budget.
     */
    #[Test]
    public function shortInputPassesThroughUnchanged(): void
    {
        $series = [1700 => 1, 1710 => 2, 1720 => 3];

        self::assertSame($series, DecadeBinCollapser::collapseCounts($series));
        self::assertSame($series, DecadeBinCollapser::collapseCumulative($series));
    }

    /**
     * `pickBinSize()` reports `1` for inputs already under the cap (no collapse
     * will happen), and snaps to the smallest ladder rung that meets the cap
     * for longer series.
     */
    #[Test]
    public function pickBinSizeWalksTheLadder(): void
    {
        self::assertSame(1, DecadeBinCollapser::pickBinSize(3));
        self::assertSame(1, DecadeBinCollapser::pickBinSize(80));
        // 81 / 80 → raw 2, smallest ladder rung that meets the cap is 2.
        self::assertSame(2, DecadeBinCollapser::pickBinSize(81));
        // 160 decades → raw 2, stays at 2.
        self::assertSame(2, DecadeBinCollapser::pickBinSize(160));
        // 161 decades → raw 3, smallest rung ≥ 3 is 5.
        self::assertSame(5, DecadeBinCollapser::pickBinSize(161));
        // 593 decades (the royal-tree case) → raw 8, smallest rung ≥ 8 is 10 (century).
        self::assertSame(10, DecadeBinCollapser::pickBinSize(593));
        // 4000 decades → raw 50, ladder gives 50.
        self::assertSame(50, DecadeBinCollapser::pickBinSize(4000));
    }

    /**
     * `collapseCounts()` preserves `array_sum`: total count across the input
     * equals total count across the output. The key of each bin is the earliest
     * decade-start it contains.
     */
    #[Test]
    public function collapseCountsPreservesTotalAndBinsByEarliestKey(): void
    {
        // 100 decades each with count = 1 → sum 100.
        $decades = range(1000, 1990, 10);
        $series  = array_fill_keys($decades, 1);

        $collapsed = DecadeBinCollapser::collapseCounts($series, 10);

        // 100 decades capped at 10 points → 10-decade bins.
        self::assertCount(10, $collapsed);
        self::assertSame(100, array_sum($collapsed));

        // First bin keyed by earliest decade-start (1000),
        // last bin keyed by 1900 (the 1900-1990 decade block).
        $keys = array_keys($collapsed);
        self::assertSame(1000, $keys[0]);
        self::assertSame(1900, $keys[9]);

        // Each bin contains 10 source decades * 1 = 10.
        foreach (array_values($collapsed) as $value) {
            self::assertSame(10, $value);
        }
    }

    /**
     * `collapseCumulative()` preserves the running-total invariant by taking
     * the LAST value per bin: the bin's representative is the running total at
     * the latest decade it contains.
     */
    #[Test]
    public function collapseCumulativeTakesLastValuePerBin(): void
    {
        // Cumulative sequence 1..100, one per decade.
        $decades = range(1000, 1990, 10);
        $series  = [];

        foreach ($decades as $index => $decade) {
            $series[$decade] = $index + 1;
        }

        $collapsed = DecadeBinCollapser::collapseCumulative($series, 10);

        // 10 bins.
        self::assertCount(10, $collapsed);
        self::assertSame(1000, array_keys($collapsed)[0]);

        // First bin holds decades 1000..1090 → last running total = 10.
        self::assertSame(10, $collapsed[1000]);
        // Last bin holds decades 1900..1990 → last running total = 100.
        self::assertSame(100, $collapsed[1900]);

        // Monotonically non-decreasing.
        $previous = 0;

        foreach ($collapsed as $value) {
            self::assertGreaterThanOrEqual($previous, $value);
            $previous = $value;
        }
    }

    /**
     * Empty input returns empty, regardless of mode — the calling card renders
     * the unified empty-state placeholder.
     */
    #[Test]
    public function emptyInputReturnsEmpty(): void
    {
        self::assertSame([], DecadeBinCollapser::collapseCounts([]));
        self::assertSame([], DecadeBinCollapser::collapseCumulative([]));
        self::assertSame(1, DecadeBinCollapser::pickBinSize(0));
    }

    /**
     * Non-positive `$maxPoints` clamps to 1 — the helper never returns more
     * bins than the caller asked for, and the public `pickBinSize()`
     * entry-point must not raise DivisionByZeroError for the documented
     * `maxPoints = 0` defensive case.
     */
    #[Test]
    public function nonPositiveMaxPointsClampsToOneBin(): void
    {
        $series = [1900 => 1, 1910 => 2, 1920 => 3];

        self::assertCount(1, DecadeBinCollapser::collapseCounts($series, 0));
        self::assertCount(1, DecadeBinCollapser::collapseCounts($series, -5));

        // pickBinSize must not divide by zero on the bare public API.
        self::assertGreaterThan(0, DecadeBinCollapser::pickBinSize(3, 0));
        self::assertGreaterThan(0, DecadeBinCollapser::pickBinSize(3, -5));
    }

    /**
     * Unsorted decade keys still produce an ascending output — the helper
     * ksorts internally before binning so caller order does not bleed into the
     * chart's x-axis. Triggered with a tight cap so the binning branch (which
     * is what owns the ksort call) is actually exercised.
     */
    #[Test]
    public function unsortedInputIsKsortedBeforeBinning(): void
    {
        $series = [1990 => 5, 1000 => 1, 1500 => 3];

        $result = DecadeBinCollapser::collapseCounts($series, 2);

        // 3 entries, cap 2, raw bin = 2, ladder snaps to 2. First
        // bin holds the two earliest sorted decades; second bin the
        // last. Keys are the earliest decade per bin.
        self::assertSame([1000, 1990], array_keys($result));
        self::assertSame([1 + 3, 5], array_values($result));
    }

    /**
     * The largest ladder rung (100 decades) caps at one millennium. For inputs
     * that exceed even that ratio the collapser falls back to a derived bin
     * size so the cap is still respected.
     */
    #[Test]
    public function fallsBackToDerivedBinSizeForExtremeInputs(): void
    {
        // 20 000 decades against a cap of 80 → raw 250, exceeds
        // every ladder rung; expect the derived raw value back.
        self::assertSame(250, DecadeBinCollapser::pickBinSize(20000));

        $series    = array_fill_keys(range(0, 19990, 10), 1);
        $collapsed = DecadeBinCollapser::collapseCounts($series, 80);

        // Bin count fits the cap; remainder may produce up to one
        // extra bin.
        self::assertLessThanOrEqual(80, count($collapsed));
    }
}
