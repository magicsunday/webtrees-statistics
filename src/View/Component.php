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
 * Fluent immutable builder for the non-widget server-rendered HTML fragments
 * under `resources/views/modules/statistics-chart/components/<name>.phtml`.
 * These are plain PHP-rendered building blocks (scalar bignum, podium ranking
 * list, progress-list, hall-of-fame grid, …) — no `data-widget` JSON marker, no
 * JS dispatcher pickup.
 *
 * Mirrors the `Widget` builder shape: one factory method per component partial,
 * generic `with(string $key, mixed $value)` for the partial-specific variables.
 * The partial's `@var` header still documents the exact contract.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class Component
{
    /**
     * @param string               $module  Module slug for the view-namespace prefix
     * @param string               $partial Component partial name (kebab-case)
     * @param array<string, mixed> $vars    Variables forwarded into the partial as named PHP vars
     */
    private function __construct(
        private string $module,
        private string $partial,
        private array $vars,
    ) {
    }

    /**
     * Start a new heat-strip component (gaps display in the tree-health tab).
     */
    public static function heatStrip(string $module): self
    {
        return new self(
            $module,
            'heat-strip',
            []
        );
    }

    /**
     * Start a new live-dead-card component (overview tab).
     */
    public static function liveDeadCard(string $module): self
    {
        return new self(
            $module,
            'live-dead-card',
            []
        );
    }

    /**
     * Start a new marital-card component (overview tab).
     */
    public static function maritalCard(string $module): self
    {
        return new self(
            $module,
            'marital-card',
            []
        );
    }

    /**
     * Start a new places-panel component (places tab — wraps progress-list +
     * geo-map for each birth/residence/death view).
     */
    public static function placesPanel(string $module): self
    {
        return new self(
            $module,
            'places-panel',
            ['module' => $module]
        );
    }

    /**
     * Start a new podium component (top-N ranking list with medal decoration).
     */
    public static function podium(string $module): self
    {
        return new self(
            $module,
            'podium',
            []
        );
    }

    /**
     * Start a new progress-list component (labelled bar list).
     */
    public static function progressList(string $module): self
    {
        return new self(
            $module,
            'progress-list',
            []
        );
    }

    /**
     * Start a new records-grid component (tree-records hall-of-fame grid on the
     * overview tab).
     */
    public static function recordsGrid(string $module): self
    {
        return new self(
            $module,
            'records-grid',
            []
        );
    }

    /**
     * Start a new scalar component (bignum value + caption + optional scale
     * strip).
     */
    public static function scalar(string $module): self
    {
        return new self(
            $module,
            'scalar',
            []
        );
    }

    /**
     * Start a new marriage-extremes component (shortest / longest marriages on
     * the family tab).
     */
    public static function marriageExtremes(string $module): self
    {
        return new self(
            $module,
            'marriage-extremes',
            []
        );
    }

    /**
     * Start a new mortality-anomalies component (years with an above-baseline
     * death count on the life-span tab).
     */
    public static function mortalityAnomalies(string $module): self
    {
        return new self(
            $module,
            'mortality-anomalies',
            []
        );
    }

    /**
     * Start a new sex-ratio component (families with an extreme son / daughter
     * skew on the family tab).
     */
    public static function sexRatio(string $module): self
    {
        return new self(
            $module,
            'sex-ratio',
            []
        );
    }

    /**
     * Set the payload data. Shape depends on the component partial (`@var
     * header). $data`.
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
     * Component-specific option escape hatch — sets an arbitrary key on the
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
     * Render the component to an HTML string by delegating to the underlying
     * view partial.
     */
    public function render(): string
    {
        return view(
            $this->module . '::modules/statistics-chart/components/' . $this->partial,
            $this->vars
        );
    }
}
