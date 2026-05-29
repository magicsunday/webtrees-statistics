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
 * Tree-wide under-5 child mortality summary: total children with both BIRT +
 * DEAT dates, count of those who died before age 5, and the fraction `died /
 * total`. Consumed by the Life-Span tab.
 *
 * Serialises to `{total: int, died: int, rate: float}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ChildMortalitySummary implements JsonSerializable
{
    public function __construct(
        public int $total,
        public int $died,
        public float $rate,
    ) {
    }

    /**
     * @return array{total: int, died: int, rate: float}
     */
    public function jsonSerialize(): array
    {
        return [
            'total' => $this->total,
            'died'  => $this->died,
            'rate'  => $this->rate,
        ];
    }
}
