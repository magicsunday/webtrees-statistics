<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\View;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\View;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyLink;
use MagicSunday\Webtrees\Statistic\Payload\SankeyFlowsPayload;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function html_entity_decode;
use function json_decode;
use function preg_match;
use function realpath;
use function view;

use const ENT_QUOTES;
use const JSON_THROW_ON_ERROR;

/**
 * Verifies the options that the sankey flow partial hands to the chart widget.
 * The partial is a plain template, so the test registers the module's view
 * namespace and renders it the way a tab does.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class SankeyFlowPartialTest extends TestCase
{
    /**
     * The partial renders through the translator and the module's view namespace.
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);
        View::registerNamespace('m', realpath(__DIR__ . '/../../resources/views/') . '/');
    }

    /**
     * The widget draws its node labels inside the drawing area, so the partial
     * leaves only a small margin around it. The wide default margin of the widget
     * would leave a narrow screen with a drawing a fraction of its width.
     */
    #[Test]
    public function optionsKeepASmallMarginAroundTheDrawing(): void
    {
        $html = view(
            'm::modules/statistics-chart/widgets/sankey-flow',
            [
                'identifier' => 'flow',
                'data'       => new SankeyFlowsPayload(['A', 'B'], [new SankeyLink(0, 1, 3, [])]),
            ]
        );

        self::assertSame(1, preg_match('/data-options="([^"]*)"/', $html, $match));

        /** @var array{margin: array{top: int, right: int, bottom: int, left: int}} $options */
        $options = json_decode(html_entity_decode($match[1], ENT_QUOTES), true, 512, JSON_THROW_ON_ERROR);

        self::assertSame(['top' => 8, 'right' => 12, 'bottom' => 8, 'left' => 12], $options['margin']);
    }
}
