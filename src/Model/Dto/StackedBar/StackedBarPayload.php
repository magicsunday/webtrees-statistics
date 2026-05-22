<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Dto\StackedBar;

use JsonSerializable;

/**
 * Wire-format payload for the chart-lib stacked-bar widget.
 * `categories` defines the x-axis labels; `tooltipLabels`
 * supplies the bold header shown when hovering a bar (often a
 * longer human-readable form of the category label, e.g.
 * "18th century" vs. category "18."); `series` carries the
 * stacked segments — each segment's `data` aligns positionally
 * with `categories`.
 *
 * Serialises to `{categories: list<string>, tooltipLabels: list<string>, series: list<StackedBarSeries>}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class StackedBarPayload implements JsonSerializable
{
    /**
     * @param list<string>           $categories    X-axis labels in display order
     * @param list<string>           $tooltipLabels Tooltip header per category (positional with `$categories`)
     * @param list<StackedBarSeries> $series        Stacked segments; each segment's `data` aligns with `$categories`
     */
    public function __construct(
        public array $categories,
        public array $tooltipLabels,
        public array $series,
    ) {
    }

    /**
     * @return array{categories: list<string>, tooltipLabels: list<string>, series: list<StackedBarSeries>}
     */
    public function jsonSerialize(): array
    {
        return [
            'categories'    => $this->categories,
            'tooltipLabels' => $this->tooltipLabels,
            'series'        => $this->series,
        ];
    }
}
