<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic;

use Fisharebest\Localization\Translation;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Module\ModuleChartInterface;
use Fisharebest\Webtrees\Module\ModuleChartTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\StatisticsChartModule;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use MagicSunday\Webtrees\ModuleBase\Contract\ModuleAssetUrlInterface;
use Override;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

use function is_file;
use function realpath;

/**
 * Statistics chart module.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class Module extends StatisticsChartModule implements ModuleAssetUrlInterface, ModuleCustomInterface
{
    use ModuleChartTrait;
    use ModuleCustomTrait;

    private const string GITHUB_REPO = 'magicsunday/webtrees-statistics';

    public const string CUSTOM_AUTHOR = 'Rico Sonntag';

    public const string CUSTOM_VERSION = '1.0.0-dev';

    public const string CUSTOM_SUPPORT_URL = 'https://github.com/' . self::GITHUB_REPO . '/issues';

    public const string CUSTOM_LATEST_VERSION = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

    /**
     * Initialization.
     */
    public function boot(): void
    {
        View::registerNamespace(
            $this->name(),
            realpath($this->resourcesFolder() . 'views/') . '/'
        );
    }

    /**
     * How should this module be identified in the control panel, etc.?
     *
     * @return string
     */
    #[Override]
    public function title(): string
    {
        return I18N::translate('Statistics');
    }

    /**
     * A sentence describing what this module does.
     *
     * @return string
     */
    #[Override]
    public function description(): string
    {
        return I18N::translate('Various statistics charts.');
    }

    /**
     * Where does this module store its resources?
     *
     * @return string
     */
    #[Override]
    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }

    /**
     * CSS class for the chart-menu entry. The {@see ModuleChartTrait}
     * we `use` resets this to the empty string and would otherwise
     * leave our menu item without the icon every other Statistics
     * chart in the Charts dropdown carries; re-apply core's value
     * so the icon renders consistently.
     */
    #[Override]
    public function chartMenuClass(): string
    {
        return 'menu-chart-statistics';
    }

    /**
     * Load the compiled gettext catalogue for the requested locale
     * so I18N::translate() returns the module's own translations
     * rather than falling back to the English msgid. Returns an
     * empty array when no MO file ships for the language (webtrees
     * core then keeps the English baseline).
     *
     * @return array<string, string>
     */
    #[Override]
    public function customTranslations(string $language): array
    {
        $catalogue = $this->resourcesFolder() . 'lang/' . $language . '/messages.mo';

        if (!is_file($catalogue)) {
            return [];
        }

        /** @var array<string, string> $translations */
        $translations = (new Translation($catalogue))->asArray();

        return $translations;
    }

    /**
     * Renders the top-level page with the six-tab navigation skeleton.
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

        return $this->viewResponse(
            $this->name() . '::modules/statistics-chart/page',
            [
                'module'     => $this->name(),
                'tabs'       => $tabs,
                'title'      => $this->title(),
                'tree'       => $tree,
                'javascript' => $this->assetUrl('js/webtrees-statistics.min.js'),
                'stylesheet' => $this->assetUrl('css/statistics.css'),
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
        return $this->renderTab('Overview');
    }

    /**
     * Render the Names tab — common surnames and given-name tag clouds.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    public function getNamesAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTab('Names');
    }

    /**
     * Render the Tree Health tab — data-quality metrics.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    public function getTreeHealthAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTab('TreeHealth');
    }

    /**
     * Render the Life Span tab — births, deaths and lifespan distributions.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    public function getLifeSpanAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTab('LifeSpan');
    }

    /**
     * Render the Family tab — marriage, divorce, children and kinship widgets.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    public function getFamilyAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTab('Family');
    }

    /**
     * Render the Places tab — country-of-birth / country-of-death maps.
     *
     * @param ServerRequestInterface $request Incoming HTTP request
     */
    public function getPlacesAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->renderTab('Places');
    }

    /**
     * Returns the action → translated-label map that drives the tab
     * navigation in {@see getChartAction()}. Order here is the order the
     * tabs appear on screen.
     *
     * @return array<string, string>
     */
    private function tabCatalog(): array
    {
        return [
            'Overview'   => I18N::translate('Overview'),
            'Names'      => I18N::translate('Names'),
            'TreeHealth' => I18N::translate('Tree health'),
            'LifeSpan'   => I18N::translate('Life span'),
            'Family'     => I18N::translate('Family'),
            'Places'     => I18N::translate('Places'),
        ];
    }

    /**
     * Shared renderer for every tab action. The template name matches the
     * action key one-to-one so adding a tab is a two-line change (catalog
     * entry + action method).
     *
     * @param string $template Template file name under Templates/ without extension
     */
    private function renderTab(string $template): ResponseInterface
    {
        $this->layout = 'layouts/ajax';

        return $this->viewResponse(
            $this->name() . '::modules/statistics-chart/Templates/' . $template,
            [
                'module'    => $this->name(),
                'statistic' => Registry::container()->get(Statistic::class),
            ]
        );
    }
}
