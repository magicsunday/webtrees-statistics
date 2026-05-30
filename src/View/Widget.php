<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\View;

use function view;

/**
 * Fluent immutable builder for the thin chart-lib widget hosts under
 * `resources/views/modules/statistics-chart/widgets/<name>.phtml`. Each widget
 * partial emits a `<div data-widget="…" data-payload="…" data-options="…">`
 * shell consumed by the JS dispatcher, and every `view()` call needs the same
 * boilerplate (module-prefixed view name, identifier, payload, optional aria
 * label, …).
 *
 * The builder centralises that boilerplate behind one factory method per widget
 * type (`Widget::barChart`, `Widget::lineChart`, …) so the tab templates read
 * as a single fluent chain instead of an opaque `view('…/widgets/bar-chart',
 * ['identifier' => …, 'data' => …, …])` array.
 *
 * Typed setters cover the options shared by every widget (`data`, `ariaLabel`,
 * `height`, `accent`). Widget-specific options (`orientation`, `brush`,
 * `xLabelEvery`, …) flow through the generic `with(string $key, mixed $value)`
 * escape hatch — the underlying widget partial's `@var` headers still document
 * the exact contract.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class Widget
{
    /**
     * @param string               $module  Module slug for the view-namespace prefix
     * @param string               $partial Widget partial name (kebab-case, matches `widgets/<partial>.phtml`)
     * @param array<string, mixed> $vars    Variables forwarded into the partial as named PHP vars
     */
    private function __construct(
        private string $module,
        private string $partial,
        private array $vars,
    ) {
    }

    /**
     * Start a new bar-chart widget host.
     */
    public static function barChart(string $module, string $identifier): self
    {
        return new self(
            $module,
            'bar-chart',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Start a new chord-diagram widget host.
     */
    public static function chordDiagram(string $module, string $identifier): self
    {
        return new self(
            $module,
            'chord-diagram',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Start a new diverging-bar widget host.
     */
    public static function divergingBar(string $module, string $identifier): self
    {
        return new self(
            $module,
            'diverging-bar',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Start a new box-plot widget host. The widget computes quartiles +
     * whiskers internally — callers ship the raw sample arrays per category via
     * `->withData([['category' => …, 'values' => [int, …]], …])`.
     */
    public static function boxPlot(string $module, string $identifier): self
    {
        return new self(
            $module,
            'box-plot',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Start a new donut-chart widget host.
     */
    public static function donutChart(string $module, string $identifier): self
    {
        return new self(
            $module,
            'donut-chart',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Start a new gauge-arc widget host. Gauge-arc has no identifier — the
     * partial renders inline SVG directly.
     */
    public static function gaugeArc(string $module): self
    {
        return new self(
            $module,
            'gauge-arc',
            []
        );
    }

    /**
     * Start a new geo-map widget host. The partial emits a
     * `data-widget="world-map"` shell that the JS dispatcher hands off to the
     * chart-lib `WorldMap` widget.
     */
    public static function geoMap(string $module, string $identifier): self
    {
        return new self(
            $module,
            'geo-map',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Start a new heatmap widget host. The partial emits a
     * `data-widget="heatmap"` shell carrying the `{rows, cols, values}` payload;
     * the accent hue and value caption are passed via `withAccent(...)` /
     * `with('valueLabel', …)`.
     */
    public static function heatmap(string $module, string $identifier): self
    {
        return new self(
            $module,
            'heatmap',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Start a new line-chart widget host.
     */
    public static function lineChart(string $module, string $identifier): self
    {
        return new self(
            $module,
            'line-chart',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Start a new mirror-histogram widget host. The mirror-histogram partial
     * takes `top` + `bottom` row lists and the two axis captions — wire them
     * via `withTop(...)`, `withBottom(...)`, `withTopLabel(...)`,
     * `withBottomLabel(...)`.
     */
    public static function mirrorHistogram(string $module): self
    {
        return new self(
            $module,
            'mirror-histogram',
            []
        );
    }

    /**
     * Start a new month-radial widget host. Pass the 12-slice data map via
     * `withData(...)` and the colour via `withAccent(...)`.
     */
    public static function monthRadial(string $module): self
    {
        return new self(
            $module,
            'month-radial',
            []
        );
    }

    /**
     * Start a new name-bubbles widget host.
     */
    public static function nameBubbles(string $module): self
    {
        return new self(
            $module,
            'name-bubbles',
            []
        );
    }

    /**
     * Start a new population-pyramid widget host. Pass the
     * `{centuries, bands, data}` payload via `withData(...)`; the male/female
     * captions and crossfilter source flow through `with(...)`.
     */
    public static function populationPyramid(string $module, string $identifier): self
    {
        return new self(
            $module,
            'population-pyramid',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Start a new sankey-flow widget host.
     */
    public static function sankeyFlow(string $module, string $identifier): self
    {
        return new self(
            $module,
            'sankey-flow',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Start a new stacked-bar widget host.
     */
    public static function stackedBar(string $module, string $identifier): self
    {
        return new self(
            $module,
            'stacked-bar',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Start a new stream-graph widget host.
     */
    public static function streamGraph(string $module, string $identifier): self
    {
        return new self(
            $module,
            'stream-graph',
            [
                'identifier' => $identifier,
            ]
        );
    }

    /**
     * Attach the payload data. Shape depends on the widget partial (`@var
     * header) $data` — bar-chart accepts an array of rows, line-chart accepts a
     * `LineChartPayload` or its array form, sankey-flow accepts a
     * `SankeyFlowsPayload`, etc.
     */
    public function withData(mixed $data): self
    {
        return new self(
            $this->module,
            $this->partial,
            [
                ...$this->vars,
                'data' => $data,
            ]
        );
    }

    /**
     * Accessible label rendered on the host SVG. Pass the
     * `I18N::translate(...)` string at the call site.
     */
    public function withAriaLabel(?string $label): self
    {
        return new self(
            $this->module,
            $this->partial,
            [
                ...$this->vars,
                'ariaLabel' => $label,
            ]
        );
    }

    /**
     * Optional viewport height in pixels. Each widget partial has its own
     * default (200 / 240 / 280 / 320 / 360 / 600) so leaving this unset is
     * normal.
     */
    public function withHeight(?int $height): self
    {
        return new self(
            $this->module,
            $this->partial,
            [
                ...$this->vars,
                'height' => $height,
            ]
        );
    }

    /**
     * Accent colour from the Heritage palette for widgets that expose it —
     * month-radial slice fill, name-bubbles bubble base, gauge-arc filled arc.
     * The enum value (CSS `var(--...)` literal) is what the widget partial
     * reads.
     */
    public function withAccent(?Accent $accent): self
    {
        return new self(
            $this->module,
            $this->partial,
            [
                ...$this->vars,
                'accent' => $accent?->value,
            ]
        );
    }

    /**
     * Legend placement for the donut-chart widget. The enum value (`"right"` /
     * `"bottom"`) is what the widget partial reads.
     */
    public function withLegendPosition(LegendPosition $position): self
    {
        return new self(
            $this->module,
            $this->partial,
            [
                ...$this->vars,
                'legendPosition' => $position->value,
            ]
        );
    }

    /**
     * Widget-specific option escape hatch — sets an arbitrary key on the
     * partial's variable bag. The underlying partial's `@var` header documents
     * the exact contract.
     */
    public function with(string $key, mixed $value): self
    {
        return new self(
            $this->module,
            $this->partial,
            [
                ...$this->vars,
                $key => $value,
            ]
        );
    }

    /**
     * Render the widget host to an HTML string by delegating to the underlying
     * view partial.
     */
    public function render(): string
    {
        return view(
            $this->module . '::modules/statistics-chart/widgets/' . $this->partial,
            $this->vars
        );
    }
}
