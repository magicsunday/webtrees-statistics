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
 * Backed enum for the three Hall-of-Fame record categories rendered
 * by `components/records-grid.phtml`. The case value is the CSS-
 * suffix class the partial appends to `.wt-stat-record-<value>` so
 * each category lights up in its own accent colour (life = sage,
 * marriage = wine, family = ochre).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
enum RecordCategory: string
{
    case Life     = 'life';
    case Marriage = 'marriage';
    case Family   = 'family';
}
