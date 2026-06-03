<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventMonthTally;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Integration test for {@see EventMonthTally}. A month-spanning range date
 * (`BET DEC 1880 AND JAN 1881`) is stored as two `dates` rows — a December and
 * a January row — so a raw count splits the record across two months. The
 * tally collapses it to its lower-bound month (December) and counts it once.
 *
 * Fixture (`month-dedup.ged`):
 *  - I1 BIRT `BET DEC 1880 AND JAN 1881` (lower Dec), DEAT `BET JAN 1950 AND FEB 1950` (lower Jan)
 *  - I2 BIRT `15 MAR 1900` (precise, March)
 *  - I3 BIRT `1870` (year-only, no month → excluded)
 *  - F1 (I1 × I2) DIV `BET JUN 1920 AND JUL 1920` (lower Jun)
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(EventMonthTally::class)]
#[UsesClass(DedupedEventDates::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(RowCast::class)]
final class EventMonthTallyIntegrationTest extends IntegrationTestCase
{
    /**
     * Births collapse to the lower-bound month: the month-spanning I1 counts
     * once in December (never split into January), the precise I2 in March, and
     * the month-less year-only I3 is dropped entirely.
     */
    #[Test]
    public function collapsesMonthSpanningBirthsToTheLowerBoundMonth(): void
    {
        $tree = $this->importFixtureTree('month-dedup.ged');

        self::assertSame(['MAR' => 1, 'DEC' => 1], EventMonthTally::countByMonth($tree, 'BIRT'));
    }

    /**
     * Ranged death and divorce dates each count their record once in the
     * lower-bound month.
     */
    #[Test]
    public function countsRangedDeathAndDivorceOnce(): void
    {
        $tree = $this->importFixtureTree('month-dedup.ged');

        self::assertSame(['JAN' => 1], EventMonthTally::countByMonth($tree, 'DEAT'));
        self::assertSame(['JUN' => 1], EventMonthTally::countByMonth($tree, 'DIV'));
    }
}
