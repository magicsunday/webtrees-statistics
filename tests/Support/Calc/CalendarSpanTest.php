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
use MagicSunday\Webtrees\Statistic\Support\Calc\CalendarSpan;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the calendar-aware day-span helper. The helper counts elapsed
 * anniversaries between two Julian days, never a `days / divisor` quotient, so a
 * span that falls a few days short of the anniversary reads as the lower year
 * and one landing exactly on it reads as the full year — no divisor rounding.
 *
 * The discriminator: a span ending the day BEFORE the 25th birthday (1 Jan 1900
 * to 1 Jan 1925 minus a day) is 24 whole years here, where the former
 * `intdiv($days, 365)` over-counts it to 25; the leap-day rows pin the same gap.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(CalendarSpan::class)]
final class CalendarSpanTest extends TestCase
{
    /**
     * Resolve a Gregorian year/month/day to its Julian day number.
     */
    private function jd(int $year, int $month, int $day): int
    {
        return (new GregorianCalendar())->ymdToJd($year, $month, $day);
    }

    /**
     * @return array<string, array{array{int, int, int}, array{int, int, int}, int}>
     */
    public static function wholeYearsProvider(): array
    {
        return [
            // name => [[fromY, fromM, fromD], [toY, toM, toD], expectedYears]
            'same day is zero years'                => [[1900, 1, 1], [1900, 1, 1], 0],
            'the day before the birthday is short'  => [[1900, 1, 2], [1925, 1, 1], 24],
            'months short of the anniversary'       => [[1900, 6, 15], [1925, 3, 1], 24],
            'exactly on the 25th anniversary is 25' => [[1900, 1, 1], [1925, 1, 1], 25],
            'the day after the birthday counts'     => [[1900, 1, 1], [1925, 1, 2], 25],
            'leap-day birthday before 29 Feb'       => [[2000, 2, 29], [2025, 2, 28], 24],
            'leap-day birthday rolled to 1 March'   => [[2000, 2, 29], [2025, 3, 1], 25],
            'order does not matter (magnitude)'     => [[1925, 1, 1], [1900, 1, 1], 25],
            'a single full year'                    => [[1880, 6, 15], [1881, 6, 15], 1],
        ];
    }

    /**
     * Counts whole elapsed year-anniversaries between two Julian days,
     * independent of the argument order.
     *
     * @param array{int, int, int} $from
     * @param array{int, int, int} $to
     */
    #[Test]
    #[DataProvider('wholeYearsProvider')]
    public function wholeYearsCountsElapsedAnniversaries(array $from, array $to, int $expectedYears): void
    {
        self::assertSame(
            $expectedYears,
            CalendarSpan::wholeYears($this->jd(...$from), $this->jd(...$to)),
        );
    }

    /**
     * @return array<string, array{array{int, int, int}, array{int, int, int}, int}>
     */
    public static function wholeMonthsProvider(): array
    {
        return [
            // name => [[fromY, fromM, fromD], [toY, toM, toD], expectedMonths]
            'same day is zero months'           => [[1900, 1, 1], [1900, 1, 1], 0],
            'a fortnight is zero months'        => [[1900, 1, 1], [1900, 1, 15], 0],
            'exactly one month'                 => [[1900, 1, 10], [1900, 2, 10], 1],
            'the day before completes no month' => [[1900, 1, 10], [1900, 2, 9], 0],
            'a full year is twelve months'      => [[1900, 1, 1], [1901, 1, 1], 12],
            'fourteen months across a year'     => [[1900, 1, 10], [1901, 3, 10], 14],
        ];
    }

    /**
     * Counts whole elapsed monthly anniversaries between two Julian days,
     * independent of the argument order.
     *
     * @param array{int, int, int} $from
     * @param array{int, int, int} $to
     */
    #[Test]
    #[DataProvider('wholeMonthsProvider')]
    public function wholeMonthsCountsElapsedMonths(array $from, array $to, int $expectedMonths): void
    {
        self::assertSame(
            $expectedMonths,
            CalendarSpan::wholeMonths($this->jd(...$from), $this->jd(...$to)),
        );
    }
}
