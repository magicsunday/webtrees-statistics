<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Support\SiblingGapRowMapper;
use PHPUnit\Framework\Attributes\Test;

/**
 * Verifies the bucket-label-to-AreaDensity-row conversion behind
 * the Family-tab sibling-age-gap density chart. The mapper has to
 * survive any `SIBLING_GAP_MAX` value the repository ships, so the
 * tests deliberately mix labels with different overflow caps.
 *
 * Lives under tests/Integration/ because the mapper calls I18N
 * helpers — IntegrationTestCase boots webtrees' container so the
 * translation static resolves.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class SiblingGapRowMapperTest extends IntegrationTestCase
{
    /**
     * Empty input → empty list. The widget renders its empty-state
     * placeholder when it sees zero rows.
     */
    #[Test]
    public function toRowsReturnsEmptyListForEmptyHistogram(): void
    {
        self::assertSame([], SiblingGapRowMapper::toRows([]));
    }

    /**
     * Regular "Ny" labels map onto integer x positions and the
     * pluralised tooltip header reflects the gap size.
     */
    #[Test]
    public function toRowsParsesRegularBuckets(): void
    {
        $rows = SiblingGapRowMapper::toRows([
            '0y' => 5,
            '1y' => 12,
            '2y' => 7,
        ]);

        self::assertCount(3, $rows);
        self::assertSame(0, $rows[0]['x']);
        self::assertSame(5, $rows[0]['y']);
        self::assertSame(1, $rows[1]['x']);
        self::assertSame(12, $rows[1]['y']);
        self::assertSame(2, $rows[2]['x']);
        self::assertSame(7, $rows[2]['y']);
    }

    /**
     * The overflow label ("Ny+") is identified by the trailing "+"
     * so the mapper stays decoupled from the repository's
     * SIBLING_GAP_MAX constant. Overflow rows land at the numeric
     * cap on the x-axis and carry the "N or more years" tooltip
     * header.
     */
    #[Test]
    public function toRowsMarksOverflowBucketByTrailingPlus(): void
    {
        $rows = SiblingGapRowMapper::toRows([
            '9y'   => 3,
            '10y+' => 8,
        ]);

        self::assertSame(9, $rows[0]['x'], 'regular bucket lands at numeric position');
        self::assertSame(10, $rows[1]['x'], 'overflow bucket lands at numeric cap');
        self::assertStringContainsString('10', $rows[1]['tooltipLabel']);
        self::assertStringContainsString('more', $rows[1]['tooltipLabel'], 'overflow uses the "N or more years" form');
    }

    /**
     * A future bump of `SIBLING_GAP_MAX` to (say) 15 must surface
     * the same widget shape — the parser locks onto the trailing
     * "+" sentinel rather than the literal "10y+" key.
     */
    #[Test]
    public function toRowsSurvivesADifferentOverflowCap(): void
    {
        $rows = SiblingGapRowMapper::toRows([
            '14y'  => 2,
            '15y+' => 11,
        ]);

        self::assertSame(14, $rows[0]['x']);
        self::assertSame(15, $rows[1]['x']);
        self::assertStringContainsString('15', $rows[1]['tooltipLabel']);
        self::assertStringContainsString('more', $rows[1]['tooltipLabel']);
    }

    /**
     * Tooltip body uses the plural form so single-pair buckets read
     * "1 pair" and multi-pair buckets read "N pairs".
     */
    #[Test]
    public function toRowsPluralisesTooltipBody(): void
    {
        $rows = SiblingGapRowMapper::toRows([
            '0y' => 1,
            '1y' => 4,
        ]);

        self::assertStringContainsString('1 pair', $rows[0]['tooltip']);
        self::assertStringContainsString('4 pairs', $rows[1]['tooltip']);
    }
}
