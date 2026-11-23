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
use DOMDocument;
use DOMElement;
use DOMXPath;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Module\ModuleThemeInterface;
use Fisharebest\Webtrees\Module\WebtreesTheme;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\ModuleService;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\View;
use Illuminate\Support\Collection;
use MagicSunday\Webtrees\Statistic\Module;
use MagicSunday\Webtrees\Statistic\Normalization\Contract\OccupationNormalizerInterface;
use MagicSunday\Webtrees\Statistic\Test\Support\Normalization\StubOccupationNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ServerRequestInterface;

use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function realpath;
use function trim;

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
     * The chart root carries the world-map URL exactly as the module resolves it
     * through its asset route, so a renamed attribute in the view or a dropped or
     * altered payload key in the action leaves the front-end on its fallback route
     * unnoticed. The root is selected by its id, which the tab navigation relies
     * on as well.
     */
    #[Test]
    public function chartRootCarriesTheWorldMapAssetUrl(): void
    {
        $module = new Module();
        $module->setName('webtrees-statistics');

        $document  = $this->renderChartDocument($module);
        $chartRoot = $document->getElementById('statistics-tabs');

        self::assertInstanceOf(DOMElement::class, $chartRoot);
        self::assertSame(
            $module->assetUrl('js/world-map.geojson'),
            $chartRoot->getAttribute('data-geojson-url'),
        );
    }

    /**
     * The tab navigation is a second navigation landmark next to the primary navigation
     * of the host theme. Assistive technology tells landmarks of one role apart by their
     * accessible name, so the tab navigation has to carry one.
     */
    #[Test]
    public function tabNavigationLandmarkHasAnAccessibleName(): void
    {
        $module = new Module();
        $module->setName('webtrees-statistics');

        $document    = $this->renderChartDocument($module);
        $navigations = (new DOMXPath($document))->query('//nav[contains(concat(" ", normalize-space(@class), " "), " wt-stat-nav ")]');

        self::assertNotFalse($navigations);
        self::assertCount(1, $navigations);

        $navigation = $navigations->item(0);

        self::assertInstanceOf(DOMElement::class, $navigation);
        self::assertNotSame('', trim($navigation->getAttribute('aria-label')));
    }

    /**
     * Render the chart page and parse its markup into a document, keeping the
     * libxml error handling of the caller untouched.
     *
     * @param Module $module The module whose chart action renders the page
     *
     * @return DOMDocument The parsed page
     */
    private function renderChartDocument(Module $module): DOMDocument
    {
        $html     = $this->renderChartPage($module);
        $document = new DOMDocument();

        $previousErrorHandling = libxml_use_internal_errors(true);
        $document->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorHandling);

        return $document;
    }

    /**
     * Render the page the way the chart route does: bind the tree and the user
     * into the request, register the view namespace of the module and let the
     * action build the response.
     *
     * @param Module $module The module whose chart action renders the page
     *
     * @return string The markup of the page
     */
    private function renderChartPage(Module $module): string
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
        Registry::container()->set(OccupationNormalizerInterface::class, new StubOccupationNormalizer([]));

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

        return (string) $module->getChartAction($request)->getBody();
    }
}
