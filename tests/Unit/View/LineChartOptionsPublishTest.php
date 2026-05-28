<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\View;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function preg_match;

/**
 * Locks the publish contract for the two chart-lib LineChart options
 * `multiSeriesArea` and `yUnit`. The chart-lib bundle reads them from
 * the host element's `data-options` JSON; if the line-chart partial
 * stops emitting the keys, or if the names tab stops setting them on
 * the same-sex-passdown card, the chart silently regresses to single
 * area + bare numeric tooltip without a visible failure mode.
 *
 * Static file-content assertions only — the partial cannot be
 * rendered standalone because `view()` requires the full webtrees
 * runtime. The pattern mirrors `ProgressBarCssCoverageTest`, which
 * locks a similar CSS-to-template contract via static analysis.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class LineChartOptionsPublishTest extends TestCase
{
    /**
     * The line-chart widget partial must publish `multiSeriesArea`
     * into the rendered `data-options` JSON so the chart-lib
     * constructor picks it up. The key arrives via the `with()`
     * builder and lands in `$multiSeriesArea` inside the partial.
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
     * Same contract for the tooltip suffix option. Without it the
     * single-series and multi-series tooltip fallbacks render bare
     * numbers instead of "12 %" / "43 yr".
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
     * The same-sex name passdown card is the first real consumer of
     * the two new options and must wire both through the Widget
     * builder. A future cleanup that drops either `with()` call would
     * regress the chart silently.
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
     * Helper that loads the line-chart widget partial as a string for
     * static assertion. Fails fast if the path drifts.
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
}
