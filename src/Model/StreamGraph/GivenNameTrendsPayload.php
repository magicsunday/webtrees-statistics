<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\StreamGraph;

use JsonSerializable;

/**
 * Wire-format payload for the chart-lib stream-graph widget on the Names tab.
 * `$decades` is the dense x-axis (every 10-year step from the first decade with
 * any top-N name's birth to the last); `names` is the top-N given names in
 * display order; `series` is a `name → {decade → count}` map so the renderer
 * can build one band per name without re-aggregating.
 *
 * The decade axis is emitted under the chart-lib widget's neutral `steps` key,
 * so it serialises to `{steps: list<int>, names: list<string>, series:
 * array<string, array<int, int>>}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class GivenNameTrendsPayload implements JsonSerializable
{
    /**
     * @param list<int>                      $decades 10-year decade starts in display order (e.g. [1900, 1910, …])
     * @param list<string>                   $names   Top-N given names in display order (alphabetical or by total count, depending on caller)
     * @param array<string, array<int, int>> $series  Map `name → {decade → count}` covering every (name, decade) cell
     */
    public function __construct(
        public array $decades,
        public array $names,
        public array $series,
    ) {
    }

    /**
     * @return array{steps: list<int>, names: list<string>, series: array<string, array<int, int>>}
     */
    public function jsonSerialize(): array
    {
        return [
            // The chart-lib stream-graph widget reads the x-axis under the
            // neutral `steps` key; the producer's decade axis maps onto it.
            'steps'  => $this->decades,
            'names'  => $this->names,
            'series' => $this->series,
        ];
    }
}
