<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\View;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;

/**
 * Locks the publish contract for the two chart-lib LineChart options
 * `multiSeriesArea` and `yUnit`. The chart-lib bundle reads them from the host
 * element's `data-options` JSON; if the line-chart partial stops emitting the
 * keys, or if the names tab stops setting them on the same-sex-passdown card,
 * the chart silently regresses to single area + bare numeric tooltip without a
 * visible failure mode.
 *
 * Static file-content assertions only — the partial cannot be rendered
 * standalone because `view()` requires the full webtrees runtime. The pattern
 * mirrors `ProgressBarCssCoverageTest`, which locks a similar CSS-to-template
 * contract via static analysis.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class LineChartOptionsPublishTest extends TestCase
{
    /**
     * The line-chart widget partial must publish `multiSeriesArea` into the
     * rendered `data-options` JSON so the chart-lib constructor picks it up.
     * The key arrives via the `with()` builder and lands in `$multiSeriesArea`
     * inside the partial.
     */
    #[Test]
    public function lineChartPartialPublishesMultiSeriesAreaOption(): void
    {
        $partial = $this->loadLineChartPartial();

        self::assertSame(
            1,
            preg_match(
                "/'multiSeriesArea'\\s*=>\\s*\\\$multiSeriesArea\\s*\\?\\?\\s*null/",
                $partial,
            ),
            'line-chart.phtml must emit multiSeriesArea into the data-options array',
        );
    }

    /**
     * Same contract for the tooltip suffix option. Without it the single-series
     * and multi-series tooltip fallbacks render bare numbers instead of "12 %"
     * / "43 yr".
     */
    #[Test]
    public function lineChartPartialPublishesYUnitOption(): void
    {
        $partial = $this->loadLineChartPartial();

        self::assertSame(
            1,
            preg_match(
                "/'yUnit'\\s*=>\\s*\\\$yUnit\\s*\\?\\?\\s*null/",
                $partial,
            ),
            'line-chart.phtml must emit yUnit into the data-options array',
        );
    }

    /**
     * The same-sex name passdown card is the first real consumer of the two new
     * options and must wire both through the Widget builder. A future cleanup
     * that drops either `with()` call would regress the chart silently.
     */
    #[Test]
    public function sameSexPassdownCardSetsMultiSeriesAreaAndYUnit(): void
    {
        $names = $this->loadNamesTab();

        self::assertSame(
            1,
            preg_match(
                "/->with\\('multiSeriesArea',\\s*true\\)/",
                $names,
            ),
            'names.phtml must enable multiSeriesArea on the same-sex passdown card',
        );

        self::assertSame(
            1,
            preg_match(
                "/->with\\('yUnit',\\s*' %'\\)/",
                $names,
            ),
            'names.phtml must set yUnit to " %" on the same-sex passdown card',
        );
    }

    /**
     * Per-point tooltip swaps the aggregated multi-row tooltip for a single-row
     * tooltip showing only the hovered series. Locking the publish path keeps
     * the option reaching chart-lib instead of being silently dropped from the
     * rendered data-options JSON.
     */
    #[Test]
    public function lineChartPartialPublishesPerPointTooltipOption(): void
    {
        $partial = $this->loadLineChartPartial();

        self::assertSame(
            1,
            preg_match(
                "/'perPointTooltip'\\s*=>\\s*\\\$perPointTooltip\\s*\\?\\?\\s*null/",
                $partial,
            ),
            'line-chart.phtml must emit perPointTooltip into the data-options array',
        );
    }

    /**
     * Optional x/y axis captions. The line-chart partial must emit both keys so
     * chart-lib's caption-band logic can render them in their own slots without
     * overlapping the legend.
     */
    #[Test]
    public function lineChartPartialPublishesAxisCaptionOptions(): void
    {
        $partial = $this->loadLineChartPartial();

        self::assertSame(
            1,
            preg_match(
                "/'xLabel'\\s*=>\\s*\\\$xLabel\\s*\\?\\?\\s*null/",
                $partial,
            ),
            'line-chart.phtml must emit xLabel into the data-options array',
        );

        self::assertSame(
            1,
            preg_match(
                "/'yLabel'\\s*=>\\s*\\\$yLabel\\s*\\?\\?\\s*null/",
                $partial,
            ),
            'line-chart.phtml must emit yLabel into the data-options array',
        );
    }

    /**
     * The survival-curve card on the Life-span tab is the first consumer of the
     * cohort-style tooltip + caption combo. Both `with()` calls must stay wired
     * through the Widget builder.
     */
    #[Test]
    public function survivalCurveCardSetsPerPointTooltipAndAxisCaption(): void
    {
        $lifeSpan = $this->loadLifeSpanTab();

        self::assertSame(
            1,
            preg_match(
                "/->with\\('perPointTooltip',\\s*true\\)/",
                $lifeSpan,
            ),
            'life-span.phtml must enable perPointTooltip on the survival-curve card',
        );

        self::assertSame(
            1,
            preg_match(
                "/->with\\('xLabel',\\s*I18N::translate\\('Age'\\)\\)/",
                $lifeSpan,
            ),
            "life-span.phtml must set xLabel to I18N::translate('Age') on the survival-curve card",
        );
    }

    /**
     * Helper that loads the line-chart widget partial as a string for static
     * assertion. Fails fast if the path drifts.
     */
    private function loadLineChartPartial(): string
    {
        $path = dirname(__DIR__, 3)
            . '/resources/views/modules/statistics-chart/widgets/line-chart.phtml';

        $contents = file_get_contents($path);

        self::assertNotFalse($contents, 'line-chart.phtml partial must be readable');

        return $contents;
    }

    /**
     * Helper that loads the names tab template as a string for static
     * assertion. Fails fast if the path drifts.
     */
    private function loadNamesTab(): string
    {
        $path = dirname(__DIR__, 3)
            . '/resources/views/modules/statistics-chart/tabs/names.phtml';

        $contents = file_get_contents($path);

        self::assertNotFalse($contents, 'names.phtml tab template must be readable');

        return $contents;
    }

    /**
     * Helper that loads the life-span tab template as a string for static
     * assertion. Fails fast if the path drifts.
     */
    private function loadLifeSpanTab(): string
    {
        $path = dirname(__DIR__, 3)
            . '/resources/views/modules/statistics-chart/tabs/life-span.phtml';

        $contents = file_get_contents($path);

        self::assertNotFalse($contents, 'life-span.phtml tab template must be readable');

        return $contents;
    }
}
