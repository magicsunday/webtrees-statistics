<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Aura\Router\Route;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Module\ModuleThemeInterface;
use Fisharebest\Webtrees\Module\WebtreesTheme;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use Illuminate\Support\Collection;
use MagicSunday\Webtrees\Statistic\Module;
use MagicSunday\Webtrees\Statistic\Normalization\Contract\OccupationNormalizerInterface;
use MagicSunday\Webtrees\Statistic\Normalization\RawOccupationNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

use function realpath;

/**
 * Render-level test of the chart page that hosts the tab navigation. The page
 * hands the front-end the URL of the world-map GeoJSON through the
 * `data-geojson-url` attribute of its root element, and the script reads that
 * attribute with a hard-coded route as the fallback. The two sides meet only in
 * that attribute name, so this test renders the page through the real
 * {@see Module::getChartAction()} and asserts that the attribute reaches the
 * markup with the URL of the world-map asset.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(Module::class)]
final class ModuleChartPageTest extends AbstractIntegrationTestCase
{
    /**
     * The chart root carries the world-map URL, resolved through the asset route
     * of the module, so a renamed attribute in the view or a dropped payload key
     * in the action leaves the front-end on its fallback route unnoticed.
     */
    #[Test]
    public function chartRootCarriesTheWorldMapAssetUrl(): void
    {
        $html = $this->renderChartPage();

        self::assertMatchesRegularExpression(
            '/id="statistics-tabs"[^>]*data-geojson-url="[^"]*world-map\.geojson[^"]*"/',
            $html,
        );
    }

    /**
     * Render the page the way the chart route does: bind the tree and the user
     * into the request, register the view namespace of the module and let the
     * action build the response.
     */
    private function renderChartPage(): string
    {
        $tree = $this->importFixtureTree('oldest-ties.ged');

        Registry::container()->set(Tree::class, $tree);
        Registry::container()->set(ModuleThemeInterface::class, new WebtreesTheme());

        // The core layout lists every installed module in its menus and head
        // section. The page under test needs none of them, so the service
        // reports an empty module set.
        $moduleService = self::createStub(ModuleService::class);
        $moduleService->method('findByInterface')->willReturn(new Collection());
        $moduleService->method('findByComponent')->willReturn(new Collection());
        Registry::container()->set(ModuleService::class, $moduleService);
        Registry::container()->set(OccupationNormalizerInterface::class, new RawOccupationNormalizer());

        View::registerNamespace(
            'webtrees-statistics',
            realpath(__DIR__ . '/../../resources/views/') . '/'
        );

        $request = Registry::container()->get(ServerRequestInterface::class);
        self::assertInstanceOf(ServerRequestInterface::class, $request);

        $request = $request
            ->withAttribute('tree', $tree)
            ->withAttribute('user', Auth::user())
            ->withAttribute('route', (new Route())->name('module'));

        // The layout reads the request again from the container.
        Registry::container()->set(ServerRequestInterface::class, $request);

        $module = new Module();
        $module->setName('webtrees-statistics');

        self::assertSame($tree, Validator::attributes($request)->tree());

        return (string) $module->getChartAction($request)->getBody();
    }
}
