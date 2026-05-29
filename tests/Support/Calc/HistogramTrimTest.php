<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Calc;

use MagicSunday\Webtrees\Statistic\Support\Calc\HistogramTrim;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_keys;
use function array_values;
use function count;

/**
 * Verifies the co-zero trim used by the age-at-marriage and age-at-divorce
 * side-by-side histograms in the Family tab.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class HistogramTrimTest extends TestCase
{
    /**
     * Leading buckets that are 0 in both M and F drop; the first bucket where
     * at least one sex has a count wins as the new lower bound.
     */
    #[Test]
    public function dropsLeadingCoZeroBuckets(): void
    {
        $a = ['0–4' => 0, '5–9' => 0, '10–14' => 0, '15–19' => 3, '20–24' => 5];
        $b = ['0–4' => 0, '5–9' => 0, '10–14' => 0, '15–19' => 0, '20–24' => 2];

        [$trimA, $trimB] = HistogramTrim::dropCoZeroEnds($a, $b);

        self::assertSame(['15–19', '20–24'], array_keys($trimA));
        self::assertSame(['15–19', '20–24'], array_keys($trimB));
    }

    /**
     * Trailing buckets that are 0 in both M and F drop; the last bucket where
     * at least one sex has a count wins as the new upper bound.
     */
    #[Test]
    public function dropsTrailingCoZeroBuckets(): void
    {
        $a = ['20–24' => 4, '25–29' => 6, '30–34' => 0, '35–39' => 0];
        $b = ['20–24' => 2, '25–29' => 3, '30–34' => 0, '35–39' => 0];

        [$trimA, $trimB] = HistogramTrim::dropCoZeroEnds($a, $b);

        self::assertSame(['20–24', '25–29'], array_keys($trimA));
        self::assertSame(['20–24', '25–29'], array_keys($trimB));
    }

    /**
     * A bucket that is 0 in one sex but non-zero in the other MUST be kept. The
     * trim is co-zero, not single-sex-zero.
     */
    #[Test]
    public function keepsBucketsWithCountInEitherSex(): void
    {
        $a = ['10–14' => 0, '15–19' => 1, '20–24' => 0, '25–29' => 4];
        $b = ['10–14' => 0, '15–19' => 0, '20–24' => 2, '25–29' => 5];

        [$trimA, $trimB] = HistogramTrim::dropCoZeroEnds($a, $b);

        self::assertSame(['15–19', '20–24', '25–29'], array_keys($trimA));
        self::assertSame(['15–19', '20–24', '25–29'], array_keys($trimB));
        self::assertSame([1, 0, 4], array_values($trimA));
        self::assertSame([0, 2, 5], array_values($trimB));
    }

    /**
     * Co-zero buckets in the middle are kept — only the outer ends collapse. A
     * tree with marriages at 18 and 50 but nothing in between MUST surface the
     * empty 30s/40s so the gap is visible.
     */
    #[Test]
    public function keepsCoZeroBucketsBetweenNonZeroEnds(): void
    {
        $a = ['10–14' => 0, '15–19' => 2, '20–24' => 0, '25–29' => 0, '30–34' => 1];
        $b = ['10–14' => 0, '15–19' => 1, '20–24' => 0, '25–29' => 0, '30–34' => 2];

        [$trimA, $trimB] = HistogramTrim::dropCoZeroEnds($a, $b);

        self::assertSame(['15–19', '20–24', '25–29', '30–34'], array_keys($trimA));
        self::assertSame(['15–19', '20–24', '25–29', '30–34'], array_keys($trimB));
    }

    /**
     * If both arrays are completely empty (no marriages / no divorces at all in
     * the tree), the originals are returned unchanged so the view layer can
     * decide to hide the card rather than render a phantom-trimmed empty
     * histogram.
     */
    #[Test]
    public function returnsUnchangedWhenBothSidesEmpty(): void
    {
        $a = ['0–4' => 0, '5–9' => 0, '10–14' => 0];
        $b = ['0–4' => 0, '5–9' => 0, '10–14' => 0];

        [$trimA, $trimB] = HistogramTrim::dropCoZeroEnds($a, $b);

        self::assertSame($a, $trimA);
        self::assertSame($b, $trimB);
    }

    /**
     * Single-series trim drops leading and trailing zero buckets but keeps
     * inner zeros so gaps between active periods stay visible.
     */
    #[Test]
    public function dropZeroEndsTrimsBothBoundsAndKeepsInnerZero(): void
    {
        $series = [
            '1700s' => 0,
            '1710s' => 0,
            '1800s' => 12,
            '1810s' => 0,
            '1820s' => 18,
            '1830s' => 0,
            '1840s' => 0,
        ];

        self::assertSame(
            ['1800s' => 12, '1810s' => 0, '1820s' => 18],
            HistogramTrim::dropZeroEnds($series),
        );
    }

    /**
     * A series with values only at the boundaries returns unchanged (no leading
     * / trailing zero to trim).
     */
    #[Test]
    public function dropZeroEndsReturnsUnchangedWhenBothEndsAreNonZero(): void
    {
        $series = ['a' => 1, 'b' => 0, 'c' => 0, 'd' => 5];

        self::assertSame($series, HistogramTrim::dropZeroEnds($series));
    }

    /**
     * An entirely-zero series is returned unchanged so the view can decide
     * whether to hide the card or render the all-zero placeholder.
     */
    #[Test]
    public function dropZeroEndsReturnsUnchangedWhenEverythingZero(): void
    {
        $series = ['a' => 0, 'b' => 0, 'c' => 0];

        self::assertSame($series, HistogramTrim::dropZeroEnds($series));
    }

    /**
     * `dropLowOutlierEnds()` drops leading and trailing buckets below `$ratio *
     * max`. Inner low-value buckets between two above-threshold edges survive
     * so a sub-peak gap stays visible.
     */
    #[Test]
    public function dropLowOutlierEndsKeepsInnerSubThresholdBuckets(): void
    {
        $series = ['a' => 5, 'b' => 60, 'c' => 8, 'd' => 100, 'e' => 4];

        $result = HistogramTrim::dropLowOutlierEnds($series, 0.10);

        self::assertSame(['b' => 60, 'c' => 8, 'd' => 100], $result);
    }

    /**
     * `$ratio = 0` falls back to {@see HistogramTrim::dropZeroEnds()} semantics
     * — only literal zero edges are stripped.
     */
    #[Test]
    public function dropLowOutlierEndsWithZeroRatioStripsZeroEnds(): void
    {
        $series = ['a' => 0, 'b' => 1, 'c' => 5, 'd' => 0];

        self::assertSame(['b' => 1, 'c' => 5], HistogramTrim::dropLowOutlierEnds($series, 0.0));
    }

    /**
     * Empty input passes through unchanged.
     */
    #[Test]
    public function dropLowOutlierEndsHandlesEmptyInput(): void
    {
        self::assertSame([], HistogramTrim::dropLowOutlierEnds([], 0.5));
    }

    /**
     * `capByOutlierTrim()` escalates the trim ratio until the bar count fits
     * the cap. A series with one peak and 19 tail-noise entries collapses to
     * the peak once the 2 %-of-max ratio kicks in (tail values 1 < 100 * 0.02 =
     * 2).
     */
    #[Test]
    public function capByOutlierTrimEscalatesRatioUntilFits(): void
    {
        $series = [];

        for ($i = 0; $i < 20; ++$i) {
            $series[$i] = ($i === 10) ? 100 : 1;
        }

        $result = HistogramTrim::capByOutlierTrim($series, 5);

        self::assertCount(1, $result);
        self::assertSame(100, $result[10]);
    }

    /**
     * A series already at or under the cap passes through under the gentlest 1
     * % rung — the cap is a ceiling, not a floor.
     */
    #[Test]
    public function capByOutlierTrimNoOpForShortSeries(): void
    {
        $series = ['16th' => 25, '17th' => 67, '18th' => 401, '19th' => 537, '20th' => 301];

        self::assertSame($series, HistogramTrim::capByOutlierTrim($series, 14));
    }

    /**
     * Flat-plateau input (every bucket equals max) — no ratio rung up to 20 %
     * can drop a single bucket since `$value < $value * 0.20` is always false.
     * The helper falls through and returns the unchanged series even when the
     * cap is exceeded; the documented soft-ceiling contract permits this.
     */
    #[Test]
    public function capByOutlierTrimReturnsUnchangedWhenLadderCannotConverge(): void
    {
        $series = array_fill(0, 10, 100);

        $result = HistogramTrim::capByOutlierTrim($series, 3);

        self::assertSame($series, $result);
        self::assertGreaterThan(3, count($result), 'soft ceiling: identical-value plateau exceeds cap');
    }
}
