<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Calc;

use function asin;
use function cos;
use function deg2rad;
use function min;
use function sin;
use function sqrt;

/**
 * Great-circle distance between two latitude/longitude points via the haversine
 * formula. One place to fix the Earth-radius constant and the numerical guard
 * against floating-point overshoot at antipodal points.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class Haversine
{
    /**
     * Mean Earth radius in kilometres (IUGG). Distances are reported in
     * kilometres throughout the module.
     */
    private const float EARTH_RADIUS_KM = 6371.0;

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Great-circle distance in kilometres between two points given in decimal
     * degrees (north and east positive). The `min(1.0, …)` guard keeps the
     * `asin` argument inside its domain when rounding pushes a near-antipodal
     * pair slightly above 1.
     *
     * @param float $latitude1  Latitude of the first point, in decimal degrees
     * @param float $longitude1 Longitude of the first point, in decimal degrees
     * @param float $latitude2  Latitude of the second point, in decimal degrees
     * @param float $longitude2 Longitude of the second point, in decimal degrees
     *
     * @return float Distance in kilometres
     */
    public static function distanceKm(
        float $latitude1,
        float $longitude1,
        float $latitude2,
        float $longitude2,
    ): float {
        $lat1      = deg2rad($latitude1);
        $lat2      = deg2rad($latitude2);
        $deltaLat  = deg2rad($latitude2 - $latitude1);
        $deltaLong = deg2rad($longitude2 - $longitude1);

        $a = (sin($deltaLat / 2) ** 2)
            + (cos($lat1) * cos($lat2) * (sin($deltaLong / 2) ** 2));

        return self::EARTH_RADIUS_KM * 2 * asin(min(1.0, sqrt($a)));
    }
}
