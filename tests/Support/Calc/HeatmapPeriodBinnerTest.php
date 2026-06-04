<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Calc;

use MagicSunday\Webtrees\Statistic\Support\Calc\HeatmapPeriodBinner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Pins the adaptive period-width selection that keeps the event-heatmap row
 * count readable: a tight span stays at the 25-year base, a wide CE span widens
 * up the ladder, and a BCE→CE span widens far enough that the antiquity rows do
 * not balloon the matrix. Also pins the `periodStart` floor — CE down, BCE
 * toward negative infinity so a BCE year never shares the CE-side period 0.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(HeatmapPeriodBinner::class)]
final class HeatmapPeriodBinnerTest extends TestCase
{
    /**
     * Each row pins the chosen width for a (minYear, maxYear) span under the
     * default 20-row cap, walking the ladder from the base width to a
     * BCE-straddling span that forces a century-wide period.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function periodWidthProvider(): array
    {
        return [
            // span 50 years → 3 rows at width 25 → stays at base.
            'tight CE span stays at 25' => [1900, 1950, 25],
            // span exactly fills the 20-row cap at width 25 → stays at base.
            'CE span filling the cap stays 25' => [1525, 2000, 25],
            // one period over the cap at width 25 → widen to 50.
            'CE span over the cap widens to 50' => [1500, 2000, 50],
            // BCE-only tight span stays at base.
            'BCE-only tight span stays at 25' => [-99, -50, 25],
            // BCE outlier + CE bulk → ~2000-year span → quarter-millennium period.
            'BCE-to-CE span widens to 250' => [-99, 1925, 250],
            // A corrupt span beyond the ladder keeps doubling the widest rung
            // (5000 → 10000 → 20000 → 40000) until the row count is capped.
            'corrupt span beyond the ladder hard-caps by doubling' => [-400000, 2026, 40000],
        ];
    }

    /**
     * The period width climbs the ladder just far enough to keep the dense
     * period-row fill under the 20-row cap.
     */
    #[Test]
    #[DataProvider('periodWidthProvider')]
    public function pickPeriodYearsWidensToKeepRowsUnderTheCap(int $minYear, int $maxYear, int $expected): void
    {
        self::assertSame($expected, HeatmapPeriodBinner::pickPeriodYears($minYear, $maxYear));
    }

    /**
     * CE floor-down and exact boundary, BCE floor-toward-negative-infinity (so a
     * year near zero keeps a negative period rather than collapsing into CE
     * period 0), and the BCE exact boundary.
     *
     * @return array<string, array{int, int, int}>
     */
    public static function periodStartProvider(): array
    {
        return [
            'CE start floors down'         => [1924, 25, 1900],
            'CE exact boundary'            => [1925, 25, 1925],
            'BCE floors toward -infinity'  => [-90, 25, -100],
            'BCE exact boundary'           => [-75, 25, -75],
            'near-zero BCE stays negative' => [-1, 25, -25],
            'BCE at century width'         => [-99, 100, -100],
        ];
    }

    /**
     * The period start floors CE years down and BCE years toward negative
     * infinity, so a BCE year never shares CE period 0.
     */
    #[Test]
    #[DataProvider('periodStartProvider')]
    public function periodStartFloorsBceTowardNegativeInfinity(int $year, int $width, int $expected): void
    {
        self::assertSame($expected, HeatmapPeriodBinner::periodStart($year, $width));
    }
}
