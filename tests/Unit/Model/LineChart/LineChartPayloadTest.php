<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Model\LineChart;

use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Test\Support\Narrowing\PayloadNarrowing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the {@see LineChartPayload::singleSeries()} factory used by every
 * tabs/*.phtml LineChart card. Each test pins one documented branch: empty-map
 * → empty series, integer-keyed map, string-keyed map, float counts, and the
 * positional alignment of the projector return keys against the four parallel
 * arrays.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(LineChartPayload::class)]
#[UsesClass(LineChartSeries::class)]
final class LineChartPayloadTest extends TestCase
{
    /**
     * Empty input map still produces a one-series payload with all four
     * parallel arrays empty — the chart-lib widget reads this as "no data" and
     * renders the empty-state placeholder.
     */
    #[Test]
    public function singleSeriesProducesEmptySeriesForEmptyInput(): void
    {
        $payload = LineChartPayload::singleSeries(
            seriesName: 'Births',
            countsByCategory: [],
            project: static fn (int|string $key, int|float $value): array => [
                'categoryLabel' => (string) $key,
                'tooltipBody'   => '',
                'tooltipLabel'  => '',
            ],
        );

        self::assertSame([], $payload->categories);
        self::assertCount(1, $payload->series, 'singleSeries always emits one series wrapper');

        $series = PayloadNarrowing::firstSeries($payload);
        self::assertSame('Births', $series->name);
        self::assertSame([], $series->values);
        self::assertSame([], $series->tooltips);
        self::assertSame([], $series->tooltipLabels);
    }

    /**
     * Integer-keyed map flows the integer key into the projector as
     * `int|string` and the value into the series's `values` array unchanged.
     */
    #[Test]
    public function singleSeriesForwardsIntegerKeysAndCountsUnchanged(): void
    {
        $payload = LineChartPayload::singleSeries(
            seriesName: 'Births',
            countsByCategory: [1900 => 42, 1910 => 7],
            project: static fn (int|string $century, int|float $count): array => [
                'categoryLabel' => (string) $century,
                'tooltipBody'   => $count . ' births',
                'tooltipLabel'  => 'Century ' . $century,
            ],
        );

        self::assertSame(['1900', '1910'], $payload->categories);

        $series = PayloadNarrowing::firstSeries($payload);
        self::assertSame([42, 7], $series->values);
        self::assertSame(['42 births', '7 births'], $series->tooltips);
        self::assertSame(['Century 1900', 'Century 1910'], $series->tooltipLabels);
    }

    /**
     * String-keyed map passes the string into the projector's first argument —
     * the sibling-gap and stream-graph consumers rely on this overload for
     * their bucket labels. All four output arrays are checked so a regression
     * in any one column fails at the branch it broke.
     */
    #[Test]
    public function singleSeriesHandlesStringKeyedInput(): void
    {
        $payload = LineChartPayload::singleSeries(
            seriesName: 'Gaps',
            countsByCategory: ['0y' => 5, '1y' => 12, '2y+' => 3],
            project: static fn (int|string $label, int|float $count): array => [
                'categoryLabel' => (string) $label,
                'tooltipBody'   => $count . ' pairs',
                'tooltipLabel'  => 'Gap ' . $label,
            ],
        );

        self::assertSame(['0y', '1y', '2y+'], $payload->categories);

        $series = PayloadNarrowing::firstSeries($payload);
        self::assertSame([5, 12, 3], $series->values);
        self::assertSame(['5 pairs', '12 pairs', '3 pairs'], $series->tooltips);
        self::assertSame(['Gap 0y', 'Gap 1y', 'Gap 2y+'], $series->tooltipLabels);
    }

    /**
     * Float counts (child-mortality rates) flow through unchanged into the
     * series `values` list and into the projector's `$count` parameter — the
     * LineChart widget renders them as decimals and the tooltip body
     * interpolates them with the original precision.
     */
    #[Test]
    public function singleSeriesPreservesFloatCounts(): void
    {
        $payload = LineChartPayload::singleSeries(
            seriesName: 'Mortality rate',
            countsByCategory: [18 => 23.4, 19 => 15.7, 20 => 2.1],
            project: static fn (int|string $century, int|float $rate): array => [
                'categoryLabel' => 'C' . $century,
                'tooltipBody'   => $rate . '%',
                'tooltipLabel'  => 'Century ' . $century,
            ],
        );

        self::assertSame(['C18', 'C19', 'C20'], $payload->categories);

        $series = PayloadNarrowing::firstSeries($payload);
        self::assertSame([23.4, 15.7, 2.1], $series->values);
        self::assertSame(['23.4%', '15.7%', '2.1%'], $series->tooltips);
        self::assertSame(['Century 18', 'Century 19', 'Century 20'], $series->tooltipLabels);
    }

    /**
     * The four projector return keys (`categoryLabel`, `tooltipBody`,
     * `tooltipLabel`) land in their three respective parallel arrays + the
     * count in `values` — all four arrays align positionally with the input
     * map's iteration order.
     */
    #[Test]
    public function singleSeriesPreservesIterationOrderAcrossAllArrays(): void
    {
        $payload = LineChartPayload::singleSeries(
            seriesName: 'Test',
            countsByCategory: ['A' => 10, 'B' => 20, 'C' => 30],
            project: static fn (int|string $label, int|float $count): array => [
                'categoryLabel' => 'cat-' . $label,
                'tooltipBody'   => 'body-' . $count,
                'tooltipLabel'  => 'header-' . $label,
            ],
        );

        $series = PayloadNarrowing::firstSeries($payload);

        // Position 0 in every parallel array refers to ('A', 10).
        PayloadNarrowing::assertValueAt('cat-A', $payload->categories, 0);
        PayloadNarrowing::assertValueAt(10, $series->values, 0);
        PayloadNarrowing::assertValueAt('body-10', $series->tooltips, 0);
        PayloadNarrowing::assertValueAt('header-A', $series->tooltipLabels, 0);

        // Position 2 refers to ('C', 30).
        PayloadNarrowing::assertValueAt('cat-C', $payload->categories, 2);
        PayloadNarrowing::assertValueAt(30, $series->values, 2);
        PayloadNarrowing::assertValueAt('body-30', $series->tooltips, 2);
        PayloadNarrowing::assertValueAt('header-C', $series->tooltipLabels, 2);
    }
}
