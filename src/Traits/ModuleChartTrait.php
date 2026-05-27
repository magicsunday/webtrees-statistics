<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Traits;

/**
 * Implements the webtrees ModuleChartInterface methods specific to the
 * Statistics chart module. Kept as a separate trait so future chart-menu,
 * chart-URL or chart-title overrides land in one predictable place rather
 * than scattered across {@see \MagicSunday\Webtrees\Statistic\Module}.
 *
 * Currently the only departure from the parent
 * {@see \Fisharebest\Webtrees\Module\ModuleChartTrait} is the menu CSS
 * class — the parent resets it to the empty string, which would strip
 * the Statistics-chart icon from the Charts dropdown.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
trait ModuleChartTrait
{
    use \Fisharebest\Webtrees\Module\ModuleChartTrait;

    /**
     * Returns the CSS class applied to the Statistics entry in the Charts
     * dropdown. Re-asserts core's icon class because the parent core trait
     * defaults to the empty string, which would render an iconless menu
     * item next to every other Statistics chart in the dropdown.
     */
    public function chartMenuClass(): string
    {
        return 'menu-chart-statistics';
    }
}
