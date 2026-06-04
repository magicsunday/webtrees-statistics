<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Calc;

use function intdiv;

/**
 * Picks the period width for the event heatmap so the dense period-row fill
 * stays within a readable row cap however far the tree's events span. The base
 * period is a quarter-century; a tree reaching from antiquity (BCE) to the
 * present would otherwise fill hundreds of mostly-empty 25-year rows, so the
 * width scales up the ladder (25 → 50 → 100 → 250 → … years) until the dense
 * fill fits {@see self::DEFAULT_MAX_ROWS}. The widening sums adjacent periods
 * rather than dropping them, so every event still lands in a row.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class HeatmapPeriodBinner
{
    /**
     * Base period width and the row ceiling the widening keeps the dense fill
     * under — sized for a heatmap card that stays legible at roughly twenty
     * rows before the cells shrink into an unreadable band.
     */
    public const int BASE_PERIOD_YEARS = 25;

    /**
     * Default ceiling on the rendered period-row count.
     */
    public const int DEFAULT_MAX_ROWS = 20;

    /**
     * Candidate period widths in years, aligned to familiar chronological units
     * (quarter-century, half-century, century, … up to five millennia).
     *
     * @var list<int>
     */
    private const array PERIOD_LADDER = [25, 50, 100, 250, 500, 1000, 2500, 5000];

    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Smallest period width whose dense fill from `$minYear` to `$maxYear` stays
     * within `$maxRows`. Walks the ladder first; if even five millennia per row
     * overshoots — only a corrupt far-back outlier reaches that, no real
     * genealogical span does — it keeps doubling so the row count is a HARD cap,
     * never a runaway matrix.
     */
    public static function pickPeriodYears(int $minYear, int $maxYear, int $maxRows = self::DEFAULT_MAX_ROWS): int
    {
        if ($maxRows < 1) {
            $maxRows = 1;
        }

        $width = self::BASE_PERIOD_YEARS;

        foreach (self::PERIOD_LADDER as $candidate) {
            $width = $candidate;

            if (self::rowCount($minYear, $maxYear, $candidate) <= $maxRows) {
                return $candidate;
            }
        }

        while (self::rowCount($minYear, $maxYear, $width) > $maxRows) {
            $width *= 2;
        }

        return $width;
    }

    /**
     * Dense period-row count from `$minYear` to `$maxYear` at the given width —
     * the inclusive count of width-aligned period starts the heatmap renders.
     */
    private static function rowCount(int $minYear, int $maxYear, int $width): int
    {
        return intdiv(self::periodStart($maxYear, $width) - self::periodStart($minYear, $width), $width) + 1;
    }

    /**
     * Period-start key for a year at the given width. CE years floor down to
     * their period start (`1924` at width 25 → `1900`). BCE (negative) years
     * floor toward negative infinity so they keep their era: `-1` (1 BCE) lands
     * in period `-25` (the 1–25 BCE band), `-26` in `-50` (26–50 BCE), and so on
     * — they never collapse into the CE-side period `0`. The label of a negative
     * key is the oldest year in its band (`-25` → "25 BCE"), mirroring the CE
     * convention where the label is the period's start year.
     */
    public static function periodStart(int $year, int $width): int
    {
        if ($year < 0) {
            return -(intdiv(-$year - 1, $width) + 1) * $width;
        }

        return intdiv($year, $width) * $width;
    }
}
