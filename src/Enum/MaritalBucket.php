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
 * One of four mutually-exclusive marital states that every living individual
 * is sorted into. Cases are listed in highest-precedence-first order so the
 * marital classifier can walk them top-to-bottom and keep the first match
 * that fits a row.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
enum MaritalBucket: string
{
    case Current  = 'current';
    case Divorced = 'divorced';
    case Widowed  = 'widowed';
    case Single   = 'single';
}
