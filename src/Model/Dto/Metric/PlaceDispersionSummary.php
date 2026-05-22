<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Dto\Metric;

use JsonSerializable;

/**
 * Distinct-PLAC-per-individual dispersion summary for the Places
 * tab. `average` is the mean count of distinct places per
 * individual that carries at least one PLAC value; `sampled` is
 * the count of contributing individuals; `distribution` is the
 * `count → frequency` map shown next to the average.
 *
 * Serialises to `{average: float, sampled: int, distribution: array<int|string, int>}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class PlaceDispersionSummary implements JsonSerializable
{
    /**
     * @param array<array-key, int> $distribution Mapping `distinct-place count → frequency`
     */
    public function __construct(
        public float $average,
        public int $sampled,
        public array $distribution,
    ) {
    }

    /**
     * @return array{average: float, sampled: int, distribution: array<array-key, int>}
     */
    public function jsonSerialize(): array
    {
        return [
            'average'      => $this->average,
            'sampled'      => $this->sampled,
            'distribution' => $this->distribution,
        ];
    }
}
