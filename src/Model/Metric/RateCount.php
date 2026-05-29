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
 * Generic rate counter — `value` over `total`. Feeds the source- citation
 * coverage metric on the Tree-Health tab; consumers derive both the percentage
 * and the absolute counts from the same DTO.
 *
 * Serialises to `{value: int, total: int}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class RateCount implements JsonSerializable
{
    public function __construct(
        public int $value,
        public int $total,
    ) {
    }

    /**
     * @return array{value: int, total: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'value' => $this->value,
            'total' => $this->total,
        ];
    }
}
