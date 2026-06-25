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

use function in_array;

/**
 * Derives the single Gregorian reference date a date-bucketing card needs, so
 * an event lands in the decade / century / month it actually occurred in
 * regardless of the calendar its GEDCOM date was written in.
 *
 * webtrees stores a `dates` row's `d_year` / `d_mon` / `d_day` in the date's
 * OWN calendar: a French Republican birth is year 12 (An XII), month 1
 * (Vendémiaire); a Hebrew one year 5784, month 1 (Tishri). Bucketing those
 * native numbers puts An XII in "century 0" and 5784 in "century 57" — so
 * webtrees core, and the module until now, simply EXCLUDE every non-Gregorian /
 * non-Julian calendar from its date statistics. That silently drops those
 * individuals from the charts. This helper converts them instead, via the
 * calendar-neutral julian day the import already computed.
 *
 * Gregorian and Julian dates keep their stored fields verbatim: those values
 * are already on the Gregorian scale and are byte-identical to what core stores
 * and counts (`MIN(d_year)`). Crucially this includes Julian B.C. — its native
 * `d_year` (e.g. -50 for `50 B.C.`) is returned as-is rather than the
 * proleptic-Gregorian conversion of the same julian day (-51), so the module
 * does not diverge from core for the calendars core itself buckets.
 *
 * Every other calendar is converted from its lower-bound julian day — the same
 * representative point ({@see \MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates})
 * the module already uses for an imprecise Gregorian date — so a French
 * Republican range and a Gregorian range bucket by the same rule.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class GregorianDate
{
    /**
     * Calendar `d_type` values whose stored fields are already on the Gregorian
     * scale, so the native year/month/day are the correct bucket keys and the
     * julian day is not consulted.
     */
    private const array GREGORIAN_SCALE_TYPES = ['@#DGREGORIAN@', '@#DJULIAN@'];

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * The Gregorian `[year, month, day]` of a `dates` row.
     *
     * @param string $calendar            The row's `d_type` calendar escape (e.g. `@#DGREGORIAN@`)
     * @param int    $nativeYear          The row's stored `d_year` in its own calendar
     * @param int    $nativeMonth         The row's stored `d_mon` in its own calendar (0 when absent)
     * @param int    $nativeDay           The row's stored `d_day` in its own calendar (0 when absent)
     * @param int    $lowerBoundJulianDay The row's `d_julianday1` (the imprecise date's lower bound)
     *
     * @return array{0: int, 1: int, 2: int} The Gregorian `[year, month, day]` to bucket the event by
     */
    public static function fromEventRow(
        string $calendar,
        int $nativeYear,
        int $nativeMonth,
        int $nativeDay,
        int $lowerBoundJulianDay,
    ): array {
        if (in_array($calendar, self::GREGORIAN_SCALE_TYPES, true)) {
            return [
                $nativeYear,
                $nativeMonth,
                $nativeDay,
            ];
        }

        $ymd = (new GregorianCalendar())->jdToYmd($lowerBoundJulianDay);

        return [
            $ymd[0] ?? 0,
            $ymd[1] ?? 0,
            $ymd[2] ?? 0,
        ];
    }

    /**
     * The Gregorian year of a `dates` row — the convenience the century / decade
     * cards use when they never touch month or day.
     *
     * @param string $calendar            The row's `d_type` calendar escape
     * @param int    $nativeYear          The row's stored `d_year` in its own calendar
     * @param int    $lowerBoundJulianDay The row's `d_julianday1`
     *
     * @return int The Gregorian year to bucket the event by
     */
    public static function year(string $calendar, int $nativeYear, int $lowerBoundJulianDay): int
    {
        return self::fromEventRow(
            $calendar,
            $nativeYear,
            0,
            0,
            $lowerBoundJulianDay
        )[0];
    }
}
