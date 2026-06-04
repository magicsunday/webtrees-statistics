<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Calc;

use Fisharebest\ExtCalendar\GregorianCalendar;

use function max;
use function min;

/**
 * Whole years or months between two Julian days, counted as elapsed
 * anniversaries rather than as `days / mean-year`. A span ending exactly on the
 * anniversary therefore reads as the full year, where any 365-ish divisor
 * floors it to one less. This mirrors webtrees core's calendar-aware
 * {@see \Fisharebest\Webtrees\Date\AbstractCalendarDate::ageDifference()} (its
 * year and month components) on the Gregorian calendar, which is the right
 * frame for the module's Julian-day aggregates: the originating GEDCOM calendar
 * is no longer recoverable from a stored Julian day, so every span is read in
 * one consistent calendar — the same choice core makes for its bulk statistics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class CalendarSpan
{
    /**
     * Months in a Gregorian year, used to total the year and month components.
     */
    private const int MONTHS_PER_YEAR = 12;

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Whole years elapsed between two Julian days, i.e. how many anniversaries
     * of the earlier date the later date has reached. The arguments may be in
     * either order; the result is the non-negative magnitude of the span.
     *
     * @param int $fromJulianDay One endpoint of the span, as a Julian day
     * @param int $toJulianDay   The other endpoint of the span, as a Julian day
     *
     * @return int Whole years between the two endpoints
     */
    public static function wholeYears(int $fromJulianDay, int $toJulianDay): int
    {
        $calendar = new GregorianCalendar();

        [$year1, $month1, $day1] = $calendar->jdToYmd(min($fromJulianDay, $toJulianDay));
        [$year2, $month2, $day2] = $calendar->jdToYmd(max($fromJulianDay, $toJulianDay));

        $years = $year2 - $year1;

        // Drop the final year when the later date has not yet reached the
        // anniversary (an earlier month, or the same month before the day).
        if (($month2 < $month1) || (($month2 === $month1) && ($day2 < $day1))) {
            --$years;
        }

        return $years;
    }

    /**
     * Whole months elapsed between two Julian days, i.e. how many monthly
     * anniversaries of the earlier date the later date has reached. The
     * arguments may be in either order; the result is the non-negative
     * magnitude of the span.
     *
     * @param int $fromJulianDay One endpoint of the span, as a Julian day
     * @param int $toJulianDay   The other endpoint of the span, as a Julian day
     *
     * @return int Whole months between the two endpoints
     */
    public static function wholeMonths(int $fromJulianDay, int $toJulianDay): int
    {
        $calendar = new GregorianCalendar();

        [$year1, $month1, $day1] = $calendar->jdToYmd(min($fromJulianDay, $toJulianDay));
        [$year2, $month2, $day2] = $calendar->jdToYmd(max($fromJulianDay, $toJulianDay));

        $months = (($year2 - $year1) * self::MONTHS_PER_YEAR) + ($month2 - $month1);

        // Drop the final month when the later date has not yet reached the
        // monthly anniversary (the day of month has not come round).
        if ($day2 < $day1) {
            --$months;
        }

        return $months;
    }
}
