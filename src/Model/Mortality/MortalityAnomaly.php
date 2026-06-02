<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Mortality;

/**
 * One year whose recorded death count stands out against the local baseline.
 * Carries the year, its absolute death count, the rolling-window median that
 * serves as the expected baseline, the deaths-over-baseline multiplier, the
 * standard score the year was ranked by, and the labels of any historical
 * events the year coincides with. A plain value object rendered by the server-side
 * anomaly-list component, not a chart-lib JSON payload.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MortalityAnomaly
{
    /**
     * @param int          $year       The anomaly year
     * @param int          $deaths     The recorded death count in that year
     * @param int          $baseline   The rolling-window median (expected death count)
     * @param float        $multiplier The deaths-over-baseline ratio
     * @param float        $zScore     The standard score the year was ranked by
     * @param list<string> $events     Labels (citation form) of the historical events the year coincides with (may be empty)
     */
    public function __construct(
        public int $year,
        public int $deaths,
        public int $baseline,
        public float $multiplier,
        public float $zScore,
        public array $events = [],
    ) {
    }
}
