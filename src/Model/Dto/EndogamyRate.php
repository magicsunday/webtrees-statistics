<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Dto;

use JsonSerializable;

/**
 * Cousin-marriage / shared-ancestor rate within `depth` generations.
 * `endogamous` is the count of couples sharing at least one common
 * ancestor in that depth; `total` is the count of couples where both
 * spouses have at least one parent on record (the tractable
 * population); `rate` is `endogamous / total`.
 *
 * Serialises to `{total: int, endogamous: int, rate: float, depth: int}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class EndogamyRate implements JsonSerializable
{
    public function __construct(
        public int $total,
        public int $endogamous,
        public float $rate,
        public int $depth,
    ) {
    }

    /**
     * @return array{total: int, endogamous: int, rate: float, depth: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'total'      => $this->total,
            'endogamous' => $this->endogamous,
            'rate'       => $this->rate,
            'depth'      => $this->depth,
        ];
    }
}
