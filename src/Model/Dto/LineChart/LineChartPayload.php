<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Dto\LineChart;

use Closure;
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
     * Build a single-series payload by folding a `{categoryKey => count}`
     * map into the parallel `categories` / `values` / `tooltips` /
     * `tooltipLabels` arrays. The three view-side callbacks turn
     * each map entry into:
     *   - `$categoryLabel`: short axis label (used as the category key and as the default tooltip header)
     *   - `$tooltipBody`:   tooltip body string (pluralised count text)
     *   - `$tooltipLabel`:  tooltip header override (longer-form category label).
     *
     * Used by every Templates/*.phtml LineChart card that turns a
     * `array<int|string, int>` repository return into a chart-lib
     * line-chart payload — births / deaths / weddings / divorces /
     * mortality / decade growth all share the same fold.
     *
     * @param string                                                                                                  $seriesName       Legend label for the series (e.g. "Births", "Deaths")
     * @param array<array-key, int|float>                                                                             $countsByCategory Map keyed by the short category label
     * @param Closure(int|string, int|float): array{categoryLabel: string, tooltipBody: string, tooltipLabel: string} $project          Per-entry projector
     */
    public static function singleSeries(string $seriesName, array $countsByCategory, Closure $project): self
    {
        $categories    = [];
        $values        = [];
        $tooltips      = [];
        $tooltipLabels = [];

        foreach ($countsByCategory as $category => $count) {
            $row             = $project($category, $count);
            $categories[]    = $row['categoryLabel'];
            $values[]        = $count;
            $tooltips[]      = $row['tooltipBody'];
            $tooltipLabels[] = $row['tooltipLabel'];
        }

        return new self(
            categories: $categories,
            series: [
                new LineChartSeries(
                    name: $seriesName,
                    values: $values,
                    tooltips: $tooltips,
                    tooltipLabels: $tooltipLabels,
                ),
            ],
        );
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
