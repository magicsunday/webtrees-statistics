<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Calc;

use Fisharebest\ExtCalendar\GregorianCalendar;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the calendar-aware period-key derivation. A statistics card
 * that buckets events by decade, century or month needs one consistent
 * reference scale; the helper supplies it from a `dates` row.
 *
 * The discriminator pair:
 *
 * - A Gregorian or Julian event keeps its stored native `d_year` / `d_mon` /
 *   `d_day` untouched — those values are already on the Gregorian scale and
 *   equal what webtrees core stores and counts. The Julian B.C. row is the
 *   load-bearing case: its native `d_year` (-50) deliberately wins over the
 *   proleptic-Gregorian conversion of the same julian day (-51), so the module
 *   stays byte-identical to core's `MIN(d_year)` for the calendars core itself
 *   buckets.
 * - A non-Gregorian/Julian event (French Republican, Hebrew, …) carries native
 *   fields that are meaningless on the Gregorian timeline (An XII, year 5784,
 *   month "Vendémiaire"/"Tishri"). The helper converts its lower-bound julian
 *   day to the Gregorian year/month/day instead, so the event lands in the
 *   decade/century/month it actually occurred in rather than being excluded or
 *   mis-placed.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(GregorianDate::class)]
final class GregorianDateTest extends TestCase
{
    /**
     * Resolve a Gregorian year/month/day to its Julian day number.
     */
    private static function jd(int $year, int $month, int $day): int
    {
        return (new GregorianCalendar())->ymdToJd($year, $month, $day);
    }

    /**
     * @return array<string, array{string, int, int, int, int, array{int, int, int}}>
     */
    public static function eventRowProvider(): array
    {
        return [
            // name => [calendar, nativeYear, nativeMonth, nativeDay, lowerBoundJulianDay, [expectedY, expectedM, expectedD]]

            // Gregorian / Julian: native fields are already Gregorian-scale and
            // are returned verbatim — the julian day is intentionally ignored.
            'gregorian returns its native Y/M/D' => ['@#DGREGORIAN@', 1803, 3, 15, self::jd(1750, 1, 1), [1803, 3, 15]],
            'julian AD returns its native Y/M/D' => ['@#DJULIAN@', 1700, 6, 2, self::jd(1700, 1, 11), [1700, 6, 2]],

            // Julian B.C.: the stored native d_year (-50) MUST win over the
            // proleptic-Gregorian conversion of julian day 1703161 (1 Jan 50
            // B.C.), which is year -51.
            'julian B.C. keeps native d_year, not the JD' => ['@#DJULIAN@', -50, 1, 1, 1703161, [-50, 1, 1]],

            // Non-Gregorian/Julian: convert the lower-bound julian day. The
            // native fields (An XII = 12 / Vendémiaire = 1, Hebrew 5661 / Tishri
            // = 1) are meaningless on the Gregorian scale and are discarded.
            'french republican An XII converts via the JD' => ['@#DFRENCH R@', 12, 1, 1, self::jd(1803, 9, 24), [1803, 9, 24]],
            'hebrew 5661 converts via the JD'              => ['@#DHEBREW@', 5661, 1, 1, self::jd(1900, 9, 24), [1900, 9, 24]],
        ];
    }

    /**
     * The Gregorian (year, month, day) of an event row: native fields for
     * Gregorian/Julian, the lower-bound julian day converted for every other
     * calendar.
     *
     * @param array{int, int, int} $expected
     */
    #[Test]
    #[DataProvider('eventRowProvider')]
    public function fromEventRowDerivesTheGregorianDate(
        string $calendar,
        int $nativeYear,
        int $nativeMonth,
        int $nativeDay,
        int $lowerBoundJulianDay,
        array $expected,
    ): void {
        self::assertSame(
            $expected,
            GregorianDate::fromEventRow($calendar, $nativeYear, $nativeMonth, $nativeDay, $lowerBoundJulianDay),
        );
    }

    /**
     * The year-only convenience returns just the Gregorian year component, so
     * the century/decade cards that never touch month or day read cleanly.
     *
     * @param array{int, int, int} $expected
     */
    #[Test]
    #[DataProvider('eventRowProvider')]
    public function yearReturnsTheGregorianYearComponent(
        string $calendar,
        int $nativeYear,
        int $nativeMonth,
        int $nativeDay,
        int $lowerBoundJulianDay,
        array $expected,
    ): void {
        self::assertSame(
            $expected[0],
            GregorianDate::year($calendar, $nativeYear, $lowerBoundJulianDay),
        );
    }
}
