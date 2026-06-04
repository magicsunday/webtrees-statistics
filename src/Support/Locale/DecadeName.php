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
 * Pure helper for the localised decade labels every per-decade widget renders.
 * Mirrors {@see CenturyName} so the family-tab stacked-bar, the divorce-cohort
 * line, the parenthood trend, and any future per-decade aggregate share one
 * source of truth for the two display forms — the short suffix label used on
 * the X axis ("1900s" / "1900er") and the long-form range label used as the
 * chart-tooltip header ("Period: 1900–1909" / "Zeitraum: 1900–1909"). Year
 * digits go through `(string)` so neither form picks up the locale's thousands
 * separator (raw `1900`, not "1.900").
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
     * Short decade-suffix label used on chart X-axes and as the primary
     * category label across every per-decade widget. Reuses the existing core
     * PO translation `'%ss'`, so no new translation strings are introduced.
     *
     * A negative decade-start key is a BCE decade grouped by magnitude — `-50`
     * is the "50s BCE" decade (years 50–59 BCE) — and the BCE era marker is
     * appended LAST so it reads "50s BCE" / "50er v. u. Z.", never "-50s". The
     * degenerate key `0` (years 1–9, where BCE and CE collide because there is
     * no year 0) renders as the plain "0s" decade.
     */
    public static function for(int $decadeStart): string
    {
        if ($decadeStart < 0) {
            return I18N::translate('%s BCE', I18N::translate('%ss', (string) (-$decadeStart)));
        }

        return I18N::translate('%ss', (string) $decadeStart);
    }

    /**
     * Long-form range label used as the chart-tooltip header so the hover reads
     * "Period: 1900–1909" / "Zeitraum: 1900–1909" instead of the short "1900s"
     * / "1900er" the X-axis already shows. Carries the leading "Period:"
     * caption so the same helper drops straight into any chart-lib widget that
     * consumes `tooltipLabels`.
     *
     * Pass `$decadeCount > 1` for multi-decade bins (e.g. 5-decade adaptive
     * binning in life-span.phtml). With `$decadeCount = 5`, `longLabel(1900,
     * 5)` reads "Period: 1900–1949" — the span covers the five decades 1900s,
     * 1910s, 1920s, 1930s, 1940s, i.e. years 1900 through 1949 inclusive.
     *
     * A negative `$decadeStart` is a BCE decade key; BCE years count DOWN, so
     * the range runs from the earliest (most-negative) year to the latest and
     * the era marker is appended LAST. `longLabel(-50)` reads "Period: 59–50
     * BCE" (the 50s-BCE decade, years 50–59 BCE); a five-decade bin keyed at
     * the most-negative `-90` reads "Period: 99–50 BCE".
     */
    public static function longLabel(int $decadeStart, int $decadeCount = 1): string
    {
        if ($decadeStart < 0) {
            $firstYear = -$decadeStart + 9;
            $lastYear  = -$decadeStart - (($decadeCount - 1) * 10);

            return I18N::translate(
                '%s BCE',
                I18N::translate('Period: %1$s–%2$s', (string) $firstYear, (string) $lastYear),
            );
        }

        $lastYear = $decadeStart + ($decadeCount * 10) - 1;

        return I18N::translate(
            'Period: %1$s–%2$s',
            (string) $decadeStart,
            (string) $lastYear,
        );
    }
}
