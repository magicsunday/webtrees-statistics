<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Locale;

use Fisharebest\Webtrees\I18N;

/**
 * Pure helper for the localised decade labels every per-decade
 * widget renders. Mirrors {@see CenturyName} so the family-tab
 * stacked-bar, the divorce-cohort line, the parenthood trend, and
 * any future per-decade aggregate share one source of truth for
 * the two display forms — the short suffix label used on the X
 * axis ("1900s" / "1900er") and the long-form range label used as
 * the chart-tooltip header ("Period: 1900–1909" / "Zeitraum:
 * 1900–1909"). Year digits go through `(string)` so neither form
 * picks up the locale's thousands separator (raw `1900`, not
 * "1.900").
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DecadeName
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Short decade-suffix label used on chart X-axes and as the
     * primary category label across every per-decade widget.
     * Reuses the existing core PO translation `'%ss'`, so no new
     * translation strings are introduced.
     */
    public static function for(int $decadeStart): string
    {
        return I18N::translate('%ss', (string) $decadeStart);
    }

    /**
     * Long-form range label used as the chart-tooltip header so
     * the hover reads "Period: 1900–1909" / "Zeitraum: 1900–1909"
     * instead of the short "1900s" / "1900er" the X-axis already
     * shows. Carries the leading "Period:" caption so the same
     * helper drops straight into any chart-lib widget that
     * consumes `tooltipLabels`.
     *
     * Pass `$decadeCount > 1` for multi-decade bins (e.g. 5-decade
     * adaptive binning in life-span.phtml). With `$decadeCount =
     * 5`, `longLabel(1900, 5)` reads "Period: 1900–1949" — the
     * span covers the five decades 1900s, 1910s, 1920s, 1930s,
     * 1940s, i.e. years 1900 through 1949 inclusive.
     */
    public static function longLabel(int $decadeStart, int $decadeCount = 1): string
    {
        $lastYear = $decadeStart + ($decadeCount * 10) - 1;

        return I18N::translate(
            'Period: %1$s–%2$s',
            (string) $decadeStart,
            (string) $lastYear,
        );
    }
}
