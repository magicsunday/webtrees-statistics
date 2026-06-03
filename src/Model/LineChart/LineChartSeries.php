<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\LineChart;

use JsonSerializable;

/**
 * One series in a {@see LineChartPayload}. `values` is the numeric sequence the
 * line traces, aligned positionally with the parent payload's `categories`
 * list; a `null` entry marks a suppressed point that the line widget renders as
 * a gap rather than a zero. `tooltips` and `tooltipLabels` are optional
 * per-point overrides that the renderer surfaces on hover (`tooltipLabels` for
 * the bold header, `tooltips` for the body line); both default to the empty list
 * when the consumer leaves tooltips to chart-lib's autoformatting.
 *
 * Optional `class` token attaches a CSS class to the line + legend swatch —
 * useful for sex-coloured pairs (`male` / `female`) or any rate-vs-baseline
 * split where the styling needs to stay predictable.
 *
 * Serialises to `{name, values, tooltips, tooltipLabels[, class]}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class LineChartSeries implements JsonSerializable
{
    /**
     * @param string               $name          Series label shown in the legend
     * @param list<int|float|null> $values        Numeric values aligned positionally with categories (`null` = suppressed point, rendered as a gap)
     * @param list<string>         $tooltips      Per-point tooltip body strings (empty list = chart-lib default)
     * @param list<string>         $tooltipLabels Per-point tooltip header strings (empty list = chart-lib default = category label)
     * @param string|null          $class         Optional CSS class hook (`null` = no class attribute)
     */
    public function __construct(
        public string $name,
        public array $values,
        public array $tooltips = [],
        public array $tooltipLabels = [],
        public ?string $class = null,
    ) {
    }

    /**
     * @return array{name: string, values: list<int|float|null>, tooltips: list<string>, tooltipLabels: list<string>, class?: string}
     */
    public function jsonSerialize(): array
    {
        $out = [
            'name'          => $this->name,
            'values'        => $this->values,
            'tooltips'      => $this->tooltips,
            'tooltipLabels' => $this->tooltipLabels,
        ];

        if ($this->class !== null) {
            $out['class'] = $this->class;
        }

        return $out;
    }
}
