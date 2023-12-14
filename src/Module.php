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
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Pedigree chart module class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class Module extends StatisticsChartModule implements ModuleCustomInterface
{
    use ModuleCustomTrait;
    use ModuleChartTrait;

    private const ROUTE_DEFAULT     = 'webtrees-statistics';
    private const ROUTE_DEFAULT_URL = '/tree/{tree}/webtrees-statistics/{xref}';

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

        $tabs = [
            I18N::translate('Overview') => route(
                'module',
                [
                    'module' => $this->name(),
                    'action' => 'Overview',
                    'tree'   => $tree->name(),
                ]
            ),
            I18N::translate('Relationships') => route(
                'module',
                [
                    'module' => $this->name(),
                    'action' => 'Relationships',
                    'tree'   => $tree->name(),
                ]
            ),
            I18N::translate('Places') => route(
                'module',
                [
                    'module' => $this->name(),
                    'action' => 'Places',
                    'tree'   => $tree->name(),
                ]
            ),
            I18N::translate('Age') => route(
                'module',
                [
                    'module' => $this->name(),
                    'action' => 'Age',
                    'tree'   => $tree->name(),
                ]
            ),
            I18N::translate('Births') => route(
                'module',
                [
                    'module' => $this->name(),
                    'action' => 'Births',
                    'tree'   => $tree->name(),
                ]
            ),
            I18N::translate('Deaths') => route(
                'module',
                [
                    'module' => $this->name(),
                    'action' => 'Deaths',
                    'tree'   => $tree->name(),
                ]
            ),
            I18N::translate('Weddings') => route(
                'module',
                [
                    'module' => $this->name(),
                    'action' => 'Weddings',
                    'tree'   => $tree->name(),
                ]
            ),
            I18N::translate('Divorces') => route(
                'module',
                [
                    'module' => $this->name(),
                    'action' => 'Divorces',
                    'tree'   => $tree->name(),
                ]
            ),
            I18N::translate('Children') => route(
                'module',
                [
                    'module' => $this->name(),
                    'action' => 'Children',
                    'tree'   => $tree->name(),
                ]
            ),
        ];

        return $this->viewResponse(
            $this->name() . '::modules/statistics-chart/page',
            [
                'module'     => $this->name(),
                'tabs'       => $tabs,
                'title'      => $this->title(),
                'tree'       => $tree,
                'javascript' => $this->assetUrl('js/webtrees-statistics.js'),
//                'javascript' => $this->assetUrl('js/webtrees-statistics.min.js'),
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
}
