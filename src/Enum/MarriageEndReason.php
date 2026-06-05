<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Enum;

/**
 * How a marriage ended — the earliest terminating event after the wedding.
 * A divorce only when the DIV day is the earliest terminating day; otherwise a
 * spouse death.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
enum MarriageEndReason: string
{
    case Death = 'death';

    case Divorce = 'divorce';
}
