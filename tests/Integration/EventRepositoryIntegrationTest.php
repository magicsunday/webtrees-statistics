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
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventCenturyTally;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventMonthTally;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_sum;

/**
 * Integration test for {@see EventRepository::getBirthsByZodiacSign} and
 * {@see EventRepository::eventsByCentury}. The zodiac fixture has six births
 * across four signs plus one undated-day birth that must be silently excluded:
 *
 *   1 APR 1900 → Aries
 *  25 MAR 1950 → Aries (boundary check: 21 Mar–21 Apr)
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
#[UsesClass(EventCenturyTally::class)]
#[UsesClass(EventMonthTally::class)]
#[UsesClass(DedupedEventDates::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(CenturyName::class)]
#[UsesClass(RowCast::class)]
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
     * The births-by-century card seam deduplicates the two-row range encoding:
     * the fixture's three births (a within-century range, a precise date and a
     * century-straddling range) all count once in the 19th century. A regression
     * to core's raw `countEventsByCentury` would report five.
     */
    #[Test]
    public function eventsByCenturyCountsEachRangedBirthOnce(): void
    {
        $tree = $this->importFixtureTree('century-dedup.ged');

        self::assertSame(['19th' => 3], (new EventRepository($tree))->eventsByCentury('BIRT'));
    }

    /**
     * The births-by-month card seam deduplicates the two-row range encoding: a
     * month-spanning birth (`BET DEC 1880 AND JAN 1881`) counts once in its
     * lower-bound month (December), never split into January, alongside the
     * precise March birth; the month-less year-only birth is dropped.
     */
    #[Test]
    public function eventsByMonthCountsEachRangedBirthOnce(): void
    {
        $tree = $this->importFixtureTree('month-dedup.ged');

        self::assertSame(['MAR' => 1, 'DEC' => 1], (new EventRepository($tree))->eventsByMonth('BIRT'));
    }
}
