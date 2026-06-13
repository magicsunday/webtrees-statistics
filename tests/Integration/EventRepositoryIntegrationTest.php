<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\EventRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\ZodiacSigns;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_sum;

/**
 * Integration test for {@see EventRepository::getBirthsByZodiacSign}. The
 * zodiac fixture has six births across four signs plus one undated-day birth
 * that must be silently excluded:
 *
 *   1 APR 1900 → Aries
 *  25 MAR 1950 → Aries (boundary check: 21 Mar–20 Apr)
 *   1 MAY 1900 → Taurus
 *  25 DEC 1900 → Capricornus (>= 21 Dec)
 *  15 JAN 1901 → Capricornus (<= 19 Jan)
 *  JUN 1900    → no day → silently dropped
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(EventRepository::class)]
#[UsesClass(DedupedEventDates::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(ZodiacSigns::class)]
final class EventRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Every sign in the result keeps its position (12 keys total) and the
     * counts match the fixture, the day-less BIRT is dropped, the boundary
     * dates land in the correct sign.
     */
    #[Test]
    public function getBirthsByZodiacSignBucketsAndDropsUndatedDays(): void
    {
        $tree   = $this->importFixtureTree('events.ged');
        $result = (new EventRepository($tree))->getBirthsByZodiacSign();

        // Every sign always present so the chart stays stable.
        self::assertCount(12, $result);

        self::assertSame(2, $result['Aries'] ?? null, '1 APR + 25 MAR');
        self::assertSame(1, $result['Taurus'] ?? null, '1 MAY');
        self::assertSame(2, $result['Capricornus'] ?? null, '25 DEC + 15 JAN');

        // The day-less June birth never enters any sign — query
        // condition `d_day != 0 AND d_mon != 0` ensures the
        // undated-day individual contributes nothing.
        self::assertSame(5, array_sum($result));
    }

    /**
     * The zodiac card deduplicates the two-row range encoding. A day-precise
     * `BET 10 JAN 1900 AND 25 JAN 1900` birth is stored as two rows — a 10 JAN
     * lower-bound (Capricornus) and a 25 JAN upper-bound (Aquarius). The raw
     * per-row count tallied the one individual into both signs; collapsing to
     * the lower-bound representative keeps it in Capricornus alone. The precise
     * 15 MAR control (Pisces) confirms the dedup folds the two stored bounds
     * without merging the two distinct individuals.
     */
    #[Test]
    public function getBirthsByZodiacSignCountsEachRangedBirthOnce(): void
    {
        $tree   = $this->importFixtureTree('zodiac-dedup.ged');
        $result = (new EventRepository($tree))->getBirthsByZodiacSign();

        self::assertSame(1, $result['Capricornus'] ?? null, 'Ranged birth counts once in its lower-bound sign');
        self::assertSame(0, $result['Aquarius'] ?? null, 'Upper bound never spawns a second tally');
        self::assertSame(1, $result['Pisces'] ?? null, 'Precise control individual');
        self::assertSame(2, array_sum($result), 'Two distinct individuals, two births');
    }

    /**
     * The Aries/Taurus boundary follows the Wikipedia tropical-zodiac table: 20
     * April is the last Aries day, 21 April the first Taurus day. The fixture
     * straddles exactly that edge so the test discriminates the boundary — an
     * off-by-one table that ends Aries on 21 April (the value shipped before
     * GH-127) would tally 21 April into Aries and fail this assertion.
     */
    #[Test]
    public function getBirthsByZodiacSignPlacesTheAriesTaurusBoundaryPerWikipedia(): void
    {
        $tree   = $this->importFixtureTree('zodiac-boundary.ged');
        $result = (new EventRepository($tree))->getBirthsByZodiacSign();

        self::assertSame(1, $result['Aries'] ?? null, '20 April is the last Aries day');
        self::assertSame(1, $result['Taurus'] ?? null, '21 April is the first Taurus day');
        self::assertSame(2, array_sum($result), 'Two distinct individuals, two births');
    }

    /**
     * The reported-bug class for the month/day cards (issue #135): a birth
     * written in the French Republican calendar must be classified by its
     * GREGORIAN month and day, not the foreign calendar's index.
     * zodiac-non-gregorian.ged seeds two births on `1 VEND 12` (1 Vendémiaire
     * An XII = 24 September 1803) — Libra. Reading the native fields (month 1,
     * day 1) would mis-classify them as Capricornus, and the old
     * Gregorian/Julian-only filter dropped them entirely; both failures are
     * pinned here.
     */
    #[Test]
    public function getBirthsByZodiacSignConvertsNonGregorianDatesToTheirGregorianSign(): void
    {
        $tree   = $this->importFixtureTree('zodiac-non-gregorian.ged');
        $result = (new EventRepository($tree))->getBirthsByZodiacSign();

        self::assertSame(2, $result['Libra'] ?? null, '24 September 1803 (1 Vendémiaire An XII) is Libra');
        self::assertSame(0, $result['Capricornus'] ?? null, 'The native month/day (1/1) is not read as Capricornus');
        self::assertSame(2, array_sum($result), 'Both French Republican births are counted');
    }
}
