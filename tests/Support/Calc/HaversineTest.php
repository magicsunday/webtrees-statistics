<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Calc;

use MagicSunday\Webtrees\Statistic\Support\Calc\Haversine;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the great-circle distance helper against known city pairs.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(Haversine::class)]
final class HaversineTest extends TestCase
{
    /**
     * @return array<string, array{float, float, float, float, float, float}>
     */
    public static function distanceProvider(): array
    {
        return [
            // name => [lat1, lng1, lat2, lng2, expectedKm, deltaKm]
            'identical point is zero'     => [52.5200, 13.4050, 52.5200, 13.4050, 0.0, 0.001],
            'Berlin to Hamburg ~255 km'   => [52.5200, 13.4050, 53.5511, 9.9937, 255.0, 5.0],
            'London to Paris ~344 km'     => [51.5074, -0.1278, 48.8566, 2.3522, 344.0, 5.0],
            'London to New York ~5570 km' => [51.5074, -0.1278, 40.7128, -74.0060, 5570.0, 20.0],
            'equator half-circumference'  => [0.0, 0.0, 0.0, 180.0, 20015.0, 5.0],
        ];
    }

    #[Test]
    #[DataProvider('distanceProvider')]
    public function distanceKmMatchesKnownGreatCircleDistances(
        float $latitude1,
        float $longitude1,
        float $latitude2,
        float $longitude2,
        float $expectedKm,
        float $deltaKm,
    ): void {
        self::assertEqualsWithDelta(
            $expectedKm,
            Haversine::distanceKm($latitude1, $longitude1, $latitude2, $longitude2),
            $deltaKm,
        );
    }

    /**
     * The formula is symmetric: swapping the endpoints yields the same distance.
     */
    #[Test]
    public function distanceKmIsSymmetric(): void
    {
        $forward = Haversine::distanceKm(52.5200, 13.4050, 40.7128, -74.0060);
        $reverse = Haversine::distanceKm(40.7128, -74.0060, 52.5200, 13.4050);

        self::assertEqualsWithDelta($forward, $reverse, 0.001);
    }
}
