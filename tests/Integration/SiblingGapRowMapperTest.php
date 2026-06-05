<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\SiblingGapRowMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Verifies the bucket-label-to-LineChart-payload conversion behind the
 * Family-tab sibling-age-gap line chart. The mapper has to survive any
 * `SIBLING_GAP_MAX` value the repository ships, so the tests deliberately mix
 * labels with different overflow caps.
 *
 * Lives under tests/Integration/ because the mapper calls I18N helpers —
 * IntegrationTestCase boots webtrees' container so the translation static
 * resolves.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(SiblingGapRowMapper::class)]
#[UsesClass(LineChartPayload::class)]
#[UsesClass(LineChartSeries::class)]
final class SiblingGapRowMapperTest extends IntegrationTestCase
{
    /**
     * Empty input returns the empty-payload shape — the LineChart partial's
     * empty-state path picks up the absence and renders the "no data"
     * placeholder via the chart-lib widget.
     */
    #[Test]
    public function toLineChartPayloadReturnsEmptyShapeForEmptyHistogram(): void
    {
        $payload = SiblingGapRowMapper::toLineChartPayload([]);

        self::assertSame([], $payload->categories);
        self::assertSame([], $payload->series[0]->values);
    }

    /**
     * Regular "Ny" labels become bare numeric category strings (the unit lives
     * in the chart's x-axis caption, not on every tick) and the parallel
     * `values` array carries the counts at the same indices.
     */
    #[Test]
    public function toLineChartPayloadCarriesParallelCategoriesAndValues(): void
    {
        $payload = SiblingGapRowMapper::toLineChartPayload([
            '0y' => 5,
            '1y' => 12,
            '2y' => 7,
        ]);

        self::assertSame(['0', '1', '2'], $payload->categories);
        self::assertSame([5, 12, 7], $payload->series[0]->values);
    }

    /**
     * The overflow label ("Ny+") is identified by the trailing "+" so the
     * mapper stays decoupled from the repository's SIBLING_GAP_MAX constant.
     * The category keeps the bare-number-plus form ("10+"); the overflow
     * tooltip header reads "N or more years" rather than "N-year gap".
     */
    #[Test]
    public function toLineChartPayloadMarksOverflowBucketByTrailingPlus(): void
    {
        $payload = SiblingGapRowMapper::toLineChartPayload([
            '9y'   => 3,
            '10y+' => 8,
        ]);

        self::assertSame(['9', '10+'], $payload->categories);
        self::assertStringContainsString('9-year', $payload->series[0]->tooltipLabels[0]);
        self::assertStringContainsString('10', $payload->series[0]->tooltipLabels[1]);
        self::assertStringContainsString('more', $payload->series[0]->tooltipLabels[1]);
    }

    /**
     * A future bump of `SIBLING_GAP_MAX` to (say) 15 must surface the same
     * widget shape — the parser locks onto the trailing "+" sentinel rather
     * than the literal "10y+" key.
     */
    #[Test]
    public function toLineChartPayloadSurvivesADifferentOverflowCap(): void
    {
        $payload = SiblingGapRowMapper::toLineChartPayload([
            '14y'  => 2,
            '15y+' => 11,
        ]);

        self::assertSame(['14', '15+'], $payload->categories);
        self::assertStringContainsString('15', $payload->series[0]->tooltipLabels[1]);
        self::assertStringContainsString('more', $payload->series[0]->tooltipLabels[1]);
    }

    /**
     * Tooltip body uses the plural form so single-pair buckets read "1 pair"
     * and multi-pair buckets read "N pairs".
     */
    #[Test]
    public function toLineChartPayloadPluralisesTooltipBody(): void
    {
        $payload = SiblingGapRowMapper::toLineChartPayload([
            '0y' => 1,
            '1y' => 4,
        ]);

        self::assertStringContainsString('1 pair', $payload->series[0]->tooltips[0]);
        self::assertStringContainsString('4 pairs', $payload->series[0]->tooltips[1]);
    }
}
