<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Dto\LineChart;

use JsonSerializable;

/**
 * Wire-format payload for the chart-lib line-chart widget.
 * `categories` defines the x-axis labels (typically century or
 * decade strings); `series` carries one or more named lines.
 * Every series's `values` array must align positionally with the
 * `categories` list.
 *
 * Serialises to `{categories: list<string>, series: list<LineChartSeries>}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class LineChartPayload implements JsonSerializable
{
    /**
     * @param list<string>          $categories X-axis labels in display order
     * @param list<LineChartSeries> $series     One or more named lines whose `values` align with `$categories`
     */
    public function __construct(
        public array $categories,
        public array $series,
    ) {
    }

    /**
     * @return array{categories: list<string>, series: list<LineChartSeries>}
     */
    public function jsonSerialize(): array
    {
        return [
            'categories' => $this->categories,
            'series'     => $this->series,
        ];
    }
}
