<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\StatisticsChartModule;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use MagicSunday\Webtrees\ModuleBase\Contract\ModuleAssetUrlInterface;
use MagicSunday\Webtrees\Statistic\Traits\ModuleChartTrait;
use MagicSunday\Webtrees\Statistic\Traits\ModuleCustomTrait;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function assert;
use function realpath;

/**
 * Entry point for the webtrees Statistics chart module. Renders the six-tab
 * navigation page (Overview / Names / LifeSpan / Family / Places / Tree health)
 * over the {@see StatisticsChartModule} chart route. Each tab is served as a
 * separate AJAX action and renders its own template under `tabs/`; the
 * top-level page wires the AJAX URLs, hero stats, stylesheet, JavaScript bundle
 * and the web-font asset URLs that the front-end consumes.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class Module extends StatisticsChartModule implements ModuleAssetUrlInterface, ModuleCustomInterface
{
    use ModuleChartTrait;
    use ModuleCustomTrait;

    private const string GITHUB_REPO = 'magicsunday/webtrees-statistics';

    public const string CUSTOM_AUTHOR = 'Rico Sonntag';

    public const string CUSTOM_VERSION = '1.8.4-dev';

    /**
     * Webtrees renders this URL as the "For more information, see …" link
     * inside the "An upgrade is available" notice on the admin home and on the
     * Modules admin pages. Pointed at the GitHub `/releases/latest` page so
     * admins who notice an available update land directly on the release notes
     * — including the "Manual / FTP installation" banner and the install-ready
     * asset zip.
     */
    public const string CUSTOM_SUPPORT_URL = 'https://github.com/' . self::GITHUB_REPO . '/releases/latest';

    public const string CUSTOM_LATEST_VERSION = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

    /**
     * Module-specific MIME-type entries that supplement the core {@see
     * \Fisharebest\Webtrees\Mime::TYPES} map. Lookup order in {@see
     * ModuleCustomTrait::getAssetAction()} is class-level → core → default — so
     * this table can both add missing types (WOFF / WOFF2 are absent from core,
     * which would otherwise serve web fonts as `application/octet-stream`) and
     * override the core defaults if ever needed.
     *
     * Placed on the class instead of on the trait so a future webtrees core
     * release that adds an identically-named constant to its own
     * `ModuleCustomTrait` cannot trigger a fatal trait-constant composition
     * conflict at class-load.
     *
     * @var array<string, string>
     */
    public const array ASSET_MIME_TYPES = [
        'WOFF'  => 'font/woff',
        'WOFF2' => 'font/woff2',
    ];

    /**
     * Registers the module's view namespace so AJAX-loaded tab templates can be
     * resolved by their fully-qualified `<module>::path` names.
     */
    public function boot(): void
    {
        View::registerNamespace(
            $this->name(),
            realpath($this->resourcesFolder() . 'views/') . '/'
        );
    }

    /**
     * Returns the module title shown in the control panel and the chart menu
     * entry.
     *
     * @return string
     */
    #[Override]
    public function title(): string
    {
        return I18N::translate('Statistics');
    }

    /**
     * Returns a short description shown in the module list in the control
     * panel.
     *
     * @return string
     */
    #[Override]
    public function description(): string
    {
        return I18N::translate('Various statistics charts.');
    }

    /**
     * Returns the absolute path to this module's resources directory.
     *
     * @return string
     */
    #[Override]
    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }

    /**
     * Renders the top-level page that hosts the six-tab navigation skeleton.
     * Builds the action → AJAX-URL map for the nav strip, fetches the hero
     * aggregate (tree-wide headline numbers), and exposes the bundle /
     * stylesheet / web-font asset URLs the front-end consumes. The tabs
     * themselves are lazy-loaded by the JavaScript bundle once the user
     * activates them.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     *
     * @return ResponseInterface
     */
    #[Override]
    public function getChartAction(ServerRequestInterface $request): ResponseInterface
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);

        // One entry per tab — translated label → AJAX URL the front-end
        // calls when the user activates the tab. Keeping the catalog
        // server-side keeps the page shell static and lets the
        // ModuleTest lock the routing surface in place.
        $tabs = [];

        foreach ($this->tabCatalog() as $action => $label) {
            $tabs[$label] = route(
                'module',
                [
                    'module' => $this->name(),
                    'action' => $action,
                    'tree'   => $tree->name(),
                ],
            );
        }

        $statistic = Registry::container()->get(Statistic::class);
        assert($statistic instanceof Statistic);

        return $this->viewResponse(
            $this->name() . '::modules/statistics-chart/page',
            [
                'module'     => $this->name(),
                'tabs'       => $tabs,
                'title'      => $this->title(),
                'tree'       => $tree,
                'hero'       => $statistic->getHeroStats(),
                'javascript' => $this->assetUrl('js/statistics-' . self::CUSTOM_VERSION . '.min.js'),
                'stylesheet' => $this->assetUrl('css/statistics.css'),
                // Pre-resolved asset URLs for the two web-font families
                // (latin + latin-ext subsets each). The `<link rel="preload">`
                // tags in `page.phtml` need the final URLs at server-render
                // time, so we expose them in the view payload rather than
                // letting the front-end compute them.
                'fontUrls' => [
                    'instrumentSerifRegularLatin'    => $this->assetUrl('fonts/InstrumentSerif-Regular-latin.woff2'),
                    'instrumentSerifRegularLatinExt' => $this->assetUrl('fonts/InstrumentSerif-Regular-latin-ext.woff2'),
                    'instrumentSerifItalicLatin'     => $this->assetUrl('fonts/InstrumentSerif-Italic-latin.woff2'),
                    'instrumentSerifItalicLatinExt'  => $this->assetUrl('fonts/InstrumentSerif-Italic-latin-ext.woff2'),
                    'geistLatin'                     => $this->assetUrl('fonts/Geist-latin.woff2'),
                    'geistLatinExt'                  => $this->assetUrl('fonts/Geist-latin-ext.woff2'),
                ],
            ]
        );
    }

    /**
     * Render the Overview tab — sex / living-deceased / marital-status donuts.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    public function getOverviewAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTab($request, 'overview');
    }

    /**
     * Render the Names tab — common surnames and given-name tag clouds.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    public function getNamesAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTab($request, 'names');
    }

    /**
     * Render the Tree Health tab — data-quality metrics.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    public function getTreeHealthAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTab($request, 'tree-health');
    }

    /**
     * Render the Life Span tab — births, deaths and lifespan distributions.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    public function getLifeSpanAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTab($request, 'life-span');
    }

    /**
     * Render the Family tab — marriage, divorce, children and kinship widgets.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    public function getFamilyAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTab($request, 'family');
    }

    /**
     * Render the Places tab — country-of-birth / country-of-death maps.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    public function getPlacesAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTab($request, 'places');
    }

    /**
     * Gate the chart actions inherited from {@see StatisticsChartModule}. This
     * module replaces the chart page with its own six-tab layout, but the parent
     * still contributes the `Individuals` / `Families` / `Other` / `Custom`
     * AJAX actions, none of which run {@see Auth::checkComponentAccess()}. They
     * remain reachable under this module's name, so override each to enforce the
     * component gate before delegating to the parent — closing the same
     * access-bypass the tab actions were fixed for.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    #[Override]
    public function getIndividualsAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->checkChartAccess($request);

        return parent::getIndividualsAction($request);
    }

    /**
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    #[Override]
    public function getFamiliesAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->checkChartAccess($request);

        return parent::getFamiliesAction($request);
    }

    /**
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    #[Override]
    public function getOtherAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->checkChartAccess($request);

        return parent::getOtherAction($request);
    }

    /**
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    #[Override]
    public function getCustomAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->checkChartAccess($request);

        return parent::getCustomAction($request);
    }

    /**
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    #[Override]
    public function postCustomChartAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->checkChartAccess($request);

        return parent::postCustomChartAction($request);
    }

    /**
     * Returns the action → translated-label map that drives the tab navigation
     * in {@see getChartAction()}. Order here is the order the tabs appear on
     * screen.
     *
     * @return array<string, string>
     */
    private function tabCatalog(): array
    {
        return [
            'Overview'   => I18N::translate('Overview'),
            'Names'      => I18N::translate('Names'),
            'LifeSpan'   => I18N::translate('Life span'),
            'Family'     => I18N::translate('Family'),
            'Places'     => I18N::translate('Places'),
            'TreeHealth' => I18N::translate('Tree health'),
        ];
    }

    /**
     * Enforce the chart component's access level. The webtrees `ModuleAction`
     * dispatcher only checks that the module is enabled (and that `Admin…`
     * actions need admin) and explicitly delegates per-component access to the
     * module, so EVERY data-bearing action this module exposes must run this
     * gate itself — not just the {@see getChartAction()} page shell. That
     * includes the custom tab actions AND the chart actions inherited from
     * {@see StatisticsChartModule} (`Individuals` / `Families` / `Other` /
     * `Custom`), which would otherwise be reachable by their action name and
     * serve statistics that bypass a restriction the admin set on the chart
     * component. Throws {@see \Fisharebest\Webtrees\Http\Exceptions\HttpAccessDeniedException}
     * for an unauthorised viewer.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    private function checkChartAccess(ServerRequestInterface $request): void
    {
        $tree = Validator::attributes($request)->tree();
        $user = Validator::attributes($request)->user();

        Auth::checkComponentAccess($this, ModuleChartInterface::class, $tree, $user);
    }

    /**
     * Shared renderer for every tab action. The template name matches the
     * action key one-to-one so adding a tab is a three-place change (catalog
     * entry + action method + the `tabs/<kebab-name>.phtml` body).
     *
     * @param ServerRequestInterface $request  Incoming HTTP request
     * @param string                 $template Template file name under tabs/ without extension (kebab-case)
     */
    private function renderTab(ServerRequestInterface $request, string $template): ResponseInterface
    {
        $this->checkChartAccess($request);

        $this->layout = 'layouts/ajax';

        return $this->viewResponse(
            $this->name() . '::modules/statistics-chart/tabs/' . $template,
            [
                'module'    => $this->name(),
                'statistic' => Registry::container()->get(Statistic::class),
            ]
        );
    }
}
