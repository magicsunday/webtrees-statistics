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
 * Backed enum of every accent colour the chart cards use. The case
 * value is the CSS `var(--...)` literal the Heritage palette pins
 * via `:root` custom properties in `statistics.css`. Card builders
 * accept the enum directly (`->withAccent(Accent::Wine)`) and the
 * builder serialises it to the CSS literal at render time.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
enum Accent: string
{
    case Wine     = 'var(--wine)';
    case Slate    = 'var(--slate)';
    case Sage     = 'var(--sage)';
    case Ochre    = 'var(--ochre)';
    case Rose     = 'var(--rose)';
    case Deceased = 'var(--deceased)';
}
