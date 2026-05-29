<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\View;

use function view;

/**
 * Backed enum of every illustration key the shared
 * `components/illustration.phtml` partial knows how to render. The case value
 * is the lookup key the partial keys its icon catalogue on; the `svg()` method
 * resolves the case to the rendered `<svg>` string. Tab templates pass the enum
 * case directly to the Card builder — `Card::for($module,
 * $title)->withIllustration( Illustration::People)->render()` — and the Card
 * resolves the SVG at render time via the bound module slug.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
enum Illustration: string
{
    case Bell        = 'bell';
    case Boat        = 'boat';
    case Book        = 'book';
    case BrokenHeart = 'brokenHeart';
    case Candle      = 'candle';
    case Chapel      = 'chapel';
    case Child       = 'child';
    case Craft       = 'craft';
    case Family      = 'family';
    case Globe       = 'globe';
    case Hourglass   = 'hourglass';
    case Knot        = 'knot';
    case Laurel      = 'laurel';
    case Magnifier   = 'magnifier';
    case Moon        = 'moon';
    case People      = 'people';
    case Rings       = 'rings';
    case Sunrise     = 'sunrise';
    case Tree        = 'tree';
    case Trophy      = 'trophy';
    case Zodiac      = 'zodiac';

    /**
     * Return the pre-rendered SVG markup for this illustration. The shared
     * illustration partial keys the icon catalogue on the enum case value.
     */
    public function svg(string $module): string
    {
        return view(
            $module . '::modules/statistics-chart/components/illustration',
            [
                'name' => $this->value,
            ]
        );
    }
}
