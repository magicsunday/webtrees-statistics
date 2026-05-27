<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Metric;

use JsonSerializable;

/**
 * Relative-density indicator for December–February deaths compared
 * with an even 12-month baseline. `score` >1.0 = winter-peaked,
 * <1.0 = winter-trough, ≈1.0 = no seasonality. `seasonCount` is
 * the count of Dec/Jan/Feb deaths; `total` is the count of all
 * deaths with a recorded month.
 *
 * Serialises to `{score: float, seasonCount: int, total: int}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class WinterPeakScore implements JsonSerializable
{
    public function __construct(
        public float $score,
        public int $seasonCount,
        public int $total,
    ) {
    }

    /**
     * @return array{score: float, seasonCount: int, total: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'score'       => $this->score,
            'seasonCount' => $this->seasonCount,
            'total'       => $this->total,
        ];
    }
}
