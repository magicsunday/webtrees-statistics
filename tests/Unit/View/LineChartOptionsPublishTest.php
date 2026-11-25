<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\View;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Payload\LineChartPayload;
use PhpToken;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;
use function html_entity_decode;
use function json_decode;
use function preg_match;
use function preg_quote;
use function view;

use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;
use const T_COMMENT;
use const T_DOC_COMMENT;

/**
 * Locks the publish contract of the chart-lib LineChart options and the way the
 * cards set them. The chart-lib bundle reads the options from the host
 * element's `data-options` JSON. If the line-chart partial stops emitting a
 * key, or a card stops setting it, the chart changes its look without a visible
 * failure.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class LineChartOptionsPublishTest extends TestCase
{
    /**
     * The partial renders through the translator and the module's view namespace.
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);
        View::registerNamespace('m', dirname(__DIR__, 3) . '/resources/views/');
    }

    /**
     * The area switch of a card reaches the `data-options` JSON of the rendered
     * partial. Without it a card that switches the area off still draws it.
     */
    #[Test]
    public function lineChartPartialPublishesShowAreaOption(): void
    {
        $html = view(
            'm::modules/statistics-chart/widgets/line-chart',
            [
                'identifier' => 'rate',
                'data'       => new LineChartPayload(['19th cent.'], [new LineChartSeries(name: 'Rate', values: [1])]),
                'showArea'   => false,
            ]
        );

        self::assertSame(1, preg_match('/data-options="([^"]*)"/', $html, $match));

        /** @var array{showArea: bool|null} $options */
        $options = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);

        self::assertFalse($options['showArea']);
    }

    /**
     * The line-chart partial must publish the tooltip suffix option into the
     * `data-options` JSON. Without it the single-series and multi-series
     * tooltip fallbacks render bare numbers instead of "12 %" / "43 yr".
     */
    #[Test]
    public function lineChartPartialPublishesYUnitOption(): void
    {
        $partial = $this->loadLineChartPartial();

        self::assertMatchesRegularExpression(
            "/'yUnit'\\s*=>\\s*\\\$yUnit\\s*\\?\\?\\s*null/",
            $partial,
            'line-chart.phtml must emit yUnit into the data-options array',
        );
    }

    /**
     * The same-sex name passdown card wires the suffix through the Widget
     * builder. A cleanup that drops the `with()` call would regress the chart
     * silently.
     */
    #[Test]
    public function sameSexPassdownCardSetsYUnit(): void
    {
        self::assertMatchesRegularExpression(
            "/->with\\('yUnit',\\s*' %'\\)/",
            $this->cardChain('names', 'same-sex-name-passdown-line'),
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

        self::assertMatchesRegularExpression(
            "/'perPointTooltip'\\s*=>\\s*\\\$perPointTooltip\\s*\\?\\?\\s*null/",
            $partial,
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

        self::assertMatchesRegularExpression(
            "/'xLabel'\\s*=>\\s*\\\$xLabel\\s*\\?\\?\\s*null/",
            $partial,
            'line-chart.phtml must emit xLabel into the data-options array',
        );

        self::assertMatchesRegularExpression(
            "/'yLabel'\\s*=>\\s*\\\$yLabel\\s*\\?\\?\\s*null/",
            $partial,
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

        self::assertMatchesRegularExpression(
            "/->with\\('perPointTooltip',\\s*true\\)/",
            $lifeSpan,
            'life-span.phtml must enable perPointTooltip on the survival-curve card',
        );

        self::assertMatchesRegularExpression(
            "/->with\\('xLabel',\\s*I18N::translate\\('Age'\\)\\)/",
            $lifeSpan,
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
     * The builder chain of one line-chart card without its comments, from its
     * widget call to the first render call after it. Fails when the tab holds no such card.
     */
    private function cardChain(string $tab, string $identifier): string
    {
        $path     = dirname(__DIR__, 3) . '/resources/views/modules/statistics-chart/tabs/' . $tab . '.phtml';
        $contents = file_get_contents($path);

        self::assertNotFalse($contents, $tab . '.phtml tab template must be readable');

        $code = '';

        foreach (PhpToken::tokenize($contents) as $token) {
            if (!$token->is([T_COMMENT, T_DOC_COMMENT])) {
                $code .= $token->text;
            }
        }

        self::assertSame(
            1,
            preg_match("/Widget::lineChart\\(\\\$module, '" . preg_quote($identifier, '/') . "'\\).*?->render\\(\\)/s", $code, $match),
            $tab . '.phtml must hold the card ' . $identifier,
        );

        return $match[0];
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
