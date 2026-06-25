<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Aggregator;

use MagicSunday\Webtrees\Statistic\Support\Aggregator\HistogramBucketAggregator;
use MagicSunday\Webtrees\Statistic\Test\Support\Narrowing\PayloadNarrowing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_keys;
use function array_sum;
use function array_values;
use function count;

/**
 * Verifies the auto-compression histogram-bucket aggregator used by the
 * TreeHealth tab's generation-depth distribution when the source series exceeds
 * the card's readable bar count.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(HistogramBucketAggregator::class)]
final class HistogramBucketAggregatorTest extends TestCase
{
    /**
     * Empty input short-circuits to an empty array regardless of the cap — the
     * calling template renders the unified placeholder instead.
     */
    #[Test]
    public function emptyInputReturnsEmptyArray(): void
    {
        self::assertSame(
            [],
            HistogramBucketAggregator::compressByFives([], 24),
        );
    }

    /**
     * When the series already fits under the cap, the smallest ladder candidate
     * (1) wins and every bucket survives unchanged. Keys carry the positional
     * index as a string.
     */
    #[Test]
    public function fitsUnderCapWithoutCompression(): void
    {
        $series = [3, 5, 9, 1];

        $result = HistogramBucketAggregator::compressByFives($series, 24);

        self::assertSame([3, 5, 9, 1], array_values($result));
        self::assertCount(4, $result);
    }

    /**
     * A `$maxBars` value below 1 is clamped to 1 so the helper never returns
     * more bars than the cap — protects callers passing a misconfigured
     * constant.
     */
    #[Test]
    public function maxBarsBelowOneClampsToOne(): void
    {
        $series = [1, 2, 3, 4, 5];

        $result = HistogramBucketAggregator::compressByFives($series, 0);

        self::assertCount(1, $result);
        self::assertSame(1 + 2 + 3 + 4 + 5, array_sum($result));
    }

    /**
     * Boundary at the cap: a series of exactly `$maxBars` entries still fits at
     * chunkSize 1 and stays uncompressed.
     */
    #[Test]
    public function exactlyAtCapFitsUncompressed(): void
    {
        $series = array_fill(0, 24, 1);

        $result = HistogramBucketAggregator::compressByFives($series, 24);

        self::assertCount(24, $result);
    }

    /**
     * Boundary one above the cap: 25 entries at maxBars 24 forces the ladder up
     * to chunkSize 5, yielding five bands of size 5 — labels use the en-dash
     * range form.
     */
    #[Test]
    public function exactlyOverCapJumpsToFives(): void
    {
        $series = array_fill(0, 25, 1);

        $result = HistogramBucketAggregator::compressByFives($series, 24);

        self::assertSame(
            ['0–4', '5–9', '10–14', '15–19', '20–24'],
            array_keys($result),
        );
        self::assertSame([5, 5, 5, 5, 5], array_values($result));
    }

    /**
     * 77 buckets (royal-92 generation-depth scenario) with cap 24 forces the
     * ladder to chunkSize 5 — yielding 16 bands which comfortably fits the card
     * width.
     */
    #[Test]
    public function royalScenarioCompressesToFives(): void
    {
        $series = array_fill(0, 77, 2);

        $result = HistogramBucketAggregator::compressByFives($series, 24);

        self::assertCount(16, $result);
        self::assertSame(77 * 2, array_sum($result));
    }

    /**
     * A series long enough to exhaust every ladder candidate (1, 5, 10, 25, 50,
     * 100) triggers the ceil fallback so the bar count still respects the cap.
     */
    #[Test]
    public function oversizeInputUsesCeilFallback(): void
    {
        $series = array_fill(0, 2500, 1);

        $result = HistogramBucketAggregator::compressByFives($series, 24);

        self::assertLessThanOrEqual(24, count($result));
        self::assertGreaterThan(0, count($result));
        self::assertSame(2500, array_sum($result));
    }

    /**
     * The last chunk picks up the trailing remainder when the source length is
     * not a clean multiple of the chunk size — its label reflects the smaller
     * range.
     */
    #[Test]
    public function trailingRemainderShrinksLastBandLabel(): void
    {
        $series = array_fill(0, 27, 1);

        $result = HistogramBucketAggregator::compressByFives($series, 24);

        self::assertSame(
            ['0–4', '5–9', '10–14', '15–19', '20–24', '25–26'],
            array_keys($result),
        );
        self::assertSame([5, 5, 5, 5, 5, 2], array_values($result));
    }

    /**
     * A single-element trailing chunk collapses its label to the bare index
     * rather than a degenerate `n–n` range.
     */
    #[Test]
    public function singleElementChunkUsesBareLabel(): void
    {
        $series = array_fill(0, 26, 1);

        $result = HistogramBucketAggregator::compressByFives($series, 24);

        self::assertArrayHasKey('25', $result);
        PayloadNarrowing::assertValueAt(1, $result, '25');
    }
}
