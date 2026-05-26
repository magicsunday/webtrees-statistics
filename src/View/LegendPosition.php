<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\View;

/**
 * Backed enum for the donut-chart legend placement. The case value
 * is the literal token the `widgets/donut-chart.phtml` partial
 * dispatches on when picking between the right-aligned default
 * (`donut-chart`) and the stacked variant (`donut-chart-v`).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
enum LegendPosition: string
{
    case Right  = 'right';
    case Bottom = 'bottom';
}
