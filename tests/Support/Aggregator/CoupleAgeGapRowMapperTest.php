<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Aggregator;

use MagicSunday\Webtrees\Statistic\Support\Aggregator\CoupleAgeGapRowMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the label-cleaning + sign-tagging contract that drives the
 * couple-age-gap diverging-bar widget on the Family tab. The
 * husband-older side comes in with negative-marker labels
 * (`'-5 to -10'`, `'<-30'`, bare `'-15'`); this mapper has to
 * normalise them into positive band labels and tag the row with
 * `sign = -1` so the chart mirrors them onto the left half.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class CoupleAgeGapRowMapperTest extends TestCase
{
    /**
     * Empty input short-circuits — the caller renders the empty
     * placeholder for the surrounding card.
     */
    #[Test]
    public function emptyInputReturnsEmptyList(): void
    {
        self::assertSame([], CoupleAgeGapRowMapper::toRows([]));
    }

    /**
     * Positive bands (wife-older side) pass through with the label
     * unchanged and `sign = +1`.
     */
    #[Test]
    public function positiveBandKeepsLabelAndTagsSignPositive(): void
    {
        $rows = CoupleAgeGapRowMapper::toRows(['5 to 10' => 12]);

        self::assertCount(1, $rows);
        self::assertSame('5 to 10', $rows[0]['label']);
        self::assertSame(12, $rows[0]['value']);
        self::assertSame(1, $rows[0]['sign']);
        self::assertStringContainsString('Wife', $rows[0]['tooltipLabel']);
        self::assertStringContainsString('5 to 10', $rows[0]['tooltipLabel']);
        self::assertStringContainsString('12', $rows[0]['tooltip']);
    }

    /**
     * Negative range bands (husband-older side, `-X to -Y` form)
     * normalise to a positive en-dash range with min/max ordering.
     */
    #[Test]
    public function negativeRangeBandCleansToPositiveEnDashRange(): void
    {
        $rows = CoupleAgeGapRowMapper::toRows(['-5 to -10' => 7]);

        self::assertCount(1, $rows);
        self::assertSame('5–10', $rows[0]['label']);
        self::assertSame(-1, $rows[0]['sign']);
        self::assertStringContainsString('Husband', $rows[0]['tooltipLabel']);
        self::assertStringContainsString('5–10', $rows[0]['tooltipLabel']);
    }

    /**
     * Open-ended low band (`<-30`) inverts to an open-ended high
     * positive label (`>30`).
     */
    #[Test]
    public function openEndedLowBandInvertsToOpenEndedHighLabel(): void
    {
        $rows = CoupleAgeGapRowMapper::toRows(['<-30' => 3]);

        self::assertCount(1, $rows);
        self::assertSame('>30', $rows[0]['label']);
        self::assertSame(-1, $rows[0]['sign']);
    }

    /**
     * Bare negative-int label (`-15`, no range) strips the leading
     * minus and stays a single band. PHP auto-casts the
     * numeric-string key to int at array-construction time, so the
     * mapper coerces the key back to string before inspection.
     */
    #[Test]
    public function bareNegativeIntStripsLeadingMinus(): void
    {
        $rows = CoupleAgeGapRowMapper::toRows([-15 => 1]);

        self::assertCount(1, $rows);
        self::assertSame('15', $rows[0]['label']);
        self::assertSame(-1, $rows[0]['sign']);
    }

    /**
     * Negative range with reversed min/max (`-10 to -5`) still
     * orders the cleaned label low→high so the chart's x-axis
     * stays monotonic.
     */
    #[Test]
    public function negativeRangeReversedInputStillOrdersLowToHigh(): void
    {
        $rows = CoupleAgeGapRowMapper::toRows(['-10 to -5' => 4]);

        self::assertCount(1, $rows);
        self::assertSame('5–10', $rows[0]['label']);
        self::assertSame(-1, $rows[0]['sign']);
    }
}
