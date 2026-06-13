<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support;

use MagicSunday\Webtrees\Statistic\Support\ZodiacSigns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_values;
use function count;

/**
 * Locks the single source of truth for the zodiac boundaries. Both the SQL
 * birth-by-sign tally and the printed period label read {@see ZodiacSigns}, so
 * the gap-free contiguity asserted here is what guarantees every dated birth
 * lands in exactly one sign and no day prints two periods.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ZodiacSigns::class)]
final class ZodiacSignsTest extends TestCase
{
    /**
     * The twelve canonical English keys in Aries-first wheel order.
     */
    #[Test]
    public function keysAreTheTwelveSignsInWheelOrder(): void
    {
        self::assertSame(
            [
                'Aries', 'Taurus', 'Gemini', 'Cancer', 'Leo', 'Virgo',
                'Libra', 'Scorpio', 'Sagittarius', 'Capricornus', 'Aquarius', 'Pisces',
            ],
            ZodiacSigns::keys(),
        );

        self::assertSame(ZodiacSigns::keys(), array_keys(ZodiacSigns::ranges()));
    }

    /**
     * The Wikipedia tropical-zodiac boundary fix: 20 April is the last Aries
     * day, 21 April the first Taurus day (the value the SQL tally buckets on).
     */
    #[Test]
    public function ariesTaurusBoundaryFollowsWikipedia(): void
    {
        $ranges = ZodiacSigns::ranges();

        self::assertSame([4, 20], $ranges['Aries']['to'], '20 April is the last Aries day');
        self::assertSame([4, 21], $ranges['Taurus']['from'], '21 April is the first Taurus day');
    }

    /**
     * The ranges are contiguous and gap-free around the whole wheel: each sign's
     * `to` day plus one is exactly the next sign's `from` day (wrapping Pisces →
     * Aries), so every calendar day maps to exactly one sign. Boundaries never
     * touch the leap day, so a fixed 28-day February is sufficient here.
     */
    #[Test]
    public function rangesAreContiguousAroundTheWheel(): void
    {
        $ranges = array_values(ZodiacSigns::ranges());
        $count  = count($ranges);

        for ($i = 0; $i < $count; ++$i) {
            $current = $ranges[$i];
            $next    = $ranges[($i + 1) % $count];

            self::assertSame(
                $this->dayAfter($current['to'][0], $current['to'][1]),
                $next['from'],
                'Gap or overlap between consecutive signs',
            );
        }
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function signForProvider(): array
    {
        return [
            // name => [month, day, expectedSign]
            'last Aries day (boundary, to-month tail)'   => [4, 20, 'Aries'],
            'first Taurus day (boundary, from-month)'    => [4, 21, 'Taurus'],
            'mid-sign Libra (24 Sep, the conversion pt)' => [9, 24, 'Libra'],
            'first Pisces day (19 Feb)'                  => [2, 19, 'Pisces'],
            // Capricornus wraps the year-end — both tails resolve to it, and the
            // day after its tail flips to Aquarius.
            'Capricornus before year-end (21 Dec)'  => [12, 21, 'Capricornus'],
            'Capricornus after year-start (19 Jan)' => [1, 19, 'Capricornus'],
            'first Aquarius day (20 Jan)'           => [1, 20, 'Aquarius'],
        ];
    }

    /**
     * A Gregorian month/day maps to exactly the sign whose contiguous range
     * covers it, including the two boundary tails and the year-end wrap that
     * Capricornus straddles. This is the classification the births-by-sign tally
     * applies after converting a non-Gregorian date to its Gregorian month/day.
     */
    #[Test]
    #[DataProvider('signForProvider')]
    public function signForClassifiesACalendarDay(int $month, int $day, string $expected): void
    {
        self::assertSame($expected, ZodiacSigns::signFor($month, $day));
    }

    /**
     * The calendar day immediately after the given month/day on a non-leap year,
     * rolling over month and year boundaries.
     *
     * @param int $month The 1-based month
     * @param int $day   The 1-based day
     *
     * @return array{int, int} The `[month, day]` of the following day
     */
    private function dayAfter(int $month, int $day): array
    {
        $daysInMonth = [1 => 31, 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        if ($day < $daysInMonth[$month]) {
            return [$month, $day + 1];
        }

        if ($month < 12) {
            return [$month + 1, 1];
        }

        return [1, 1];
    }
}
