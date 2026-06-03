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
 * Central lookup table that maps a legacy `progress-*` CSS class key to the
 * {@see Accent} enum case the `progress-list.phtml` and `podium.phtml` partials
 * use for both the bar fill and the gradient end stop. Adding a new Top-N
 * surface = add one entry here; the partials themselves stay colour-agnostic.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ProgressBarAccent
{
    /**
     * Fallback accent applied when the caller's class key is not registered
     * below.
     */
    private const Accent FALLBACK = Accent::Wine;

    /**
     * @var array<string, Accent>
     */
    private const array MAP = [
        'progress-occupations'        => Accent::Wine,
        'progress-religions'          => Accent::Slate,
        'progress-death-causes'       => Accent::Deceased,
        'progress-oldest-deceased'    => Accent::Wine,
        'progress-oldest-living'      => Accent::Sage,
        'progress-largest-families'   => Accent::Wine,
        'progress-births-country'     => Accent::Sage,
        'progress-residences-country' => Accent::Slate,
        'progress-deaths-country'     => Accent::Wine,
        'progress-top-ancestors'      => Accent::Ochre,
        'progress-migration-distance' => Accent::Wine,
    ];

    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Return the Accent enum case for the given class key. Falls through to
     * {@see Accent::Wine} when the key is unknown.
     */
    public static function for(string $class): Accent
    {
        return self::MAP[$class] ?? self::FALLBACK;
    }
}
