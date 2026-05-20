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
use Fisharebest\Webtrees\Module\ModuleChartTrait;
use Fisharebest\Webtrees\Module\ModuleCustomInterface;
use Fisharebest\Webtrees\Module\ModuleCustomTrait;
use Fisharebest\Webtrees\Module\StatisticsChartModule;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Validator;
use Fisharebest\Webtrees\View;
use MagicSunday\Webtrees\ModuleBase\Contract\ModuleAssetUrlInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

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

    /**
     * @var string
     */
    private const GITHUB_REPO = 'magicsunday/webtrees-statistics';

    /**
     * @var string
     */
    public const CUSTOM_AUTHOR = 'Rico Sonntag';

    /**
     * @var string
     */
    public const CUSTOM_VERSION = '1.0.0-dev';

    /**
     * @var string
     */
    public const CUSTOM_SUPPORT_URL = 'https://github.com/' . self::GITHUB_REPO . '/issues';

    /**
     * @var string
     */
    public const CUSTOM_LATEST_VERSION = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';

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
    public function title(): string
    {
        return I18N::translate('Statistics');
    }

    /**
     * A sentence describing what this module does.
     *
     * @return string
     */
    public function description(): string
    {
        return I18N::translate('Various statistics charts.');
    }

    /**
     * Where does this module store its resources?
     *
     * @return string
     */
    public function resourcesFolder(): string
    {
        return __DIR__ . '/../resources/';
    }

    /**
     * A form to request the chart parameters.
     *
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
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
            ]
        );
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getOverviewAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/ajax';

        return $this->viewResponse(
            $this->name() . '::modules/statistics-chart/Templates/Overview',
            [
                'module' => $this->name(),
                //                'tree'      => Validator::attributes($request)->tree(),
                'statistic' => Registry::container()->get(Statistic::class),
            ]
        );
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getPlacesAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/ajax';

        return $this->viewResponse(
            $this->name() . '::modules/statistics-chart/Templates/Places',
            [
                'module' => $this->name(),
                //                'tree'      => Validator::attributes($request)->tree(),
                'statistic' => Registry::container()->get(Statistic::class),
            ]
        );
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getBirthsAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/ajax';

        return $this->viewResponse(
            $this->name() . '::modules/statistics-chart/Templates/Births',
            [
                'module'    => $this->name(),
                'statistic' => Registry::container()->get(Statistic::class),
            ]
        );
    }

    /**
     * @param ServerRequestInterface $request
     *
     * @return ResponseInterface
     */
    public function getDeathsAction(ServerRequestInterface $request): ResponseInterface
    {
        $this->layout = 'layouts/ajax';

        return $this->viewResponse(
            $this->name() . '::modules/statistics-chart/Templates/Deaths',
            [
                'module'    => $this->name(),
                'statistic' => Registry::container()->get(Statistic::class),
            ]
        );
    }

    /**
     * Renders the placeholder for the not-yet-implemented Relationships tab.
     */
    public function getRelationshipsAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stubResponse('Relationships');
    }

    /**
     * Renders the placeholder for the not-yet-implemented Age tab.
     */
    public function getAgeAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stubResponse('Age');
    }

    /**
     * Renders the placeholder for the not-yet-implemented Weddings tab.
     */
    public function getWeddingsAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stubResponse('Weddings');
    }

    /**
     * Renders the placeholder for the not-yet-implemented Divorces tab.
     */
    public function getDivorcesAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stubResponse('Divorces');
    }

    /**
     * Renders the placeholder for the not-yet-implemented Children tab.
     */
    public function getChildrenAction(ServerRequestInterface $request): ResponseInterface
    {
        return $this->stubResponse('Children');
    }

    /**
     * Returns the action → translated-label map that drives both the tab navigation
     * in getChartAction() and the heading shown by stub placeholders. Keeping the
     * catalog in one place removes the lockstep coupling between the two surfaces
     * and avoids any per-tab label drift.
     *
     * @return array<string, string>
     */
    private function tabCatalog(): array
    {
        return [
            'Overview'      => I18N::translate('Overview'),
            'Relationships' => I18N::translate('Relationships'),
            'Places'        => I18N::translate('Places'),
            'Age'           => I18N::translate('Age'),
            'Births'        => I18N::translate('Births'),
            'Deaths'        => I18N::translate('Deaths'),
            'Weddings'      => I18N::translate('Weddings'),
            'Divorces'      => I18N::translate('Divorces'),
            'Children'      => I18N::translate('Children'),
        ];
    }

    /**
     * Render the shared "Coming soon" placeholder for tabs that are planned but
     * not yet implemented. The label is looked up from the tab catalog so any
     * rename only happens in one place.
     *
     * @param string $action Tab action key (e.g. "Relationships")
     */
    private function stubResponse(string $action): ResponseInterface
    {
        $this->layout = 'layouts/ajax';

        return $this->viewResponse(
            $this->name() . '::modules/statistics-chart/Templates/ComingSoon',
            [
                'tabLabel' => $this->tabCatalog()[$action],
            ],
        );
    }
}
