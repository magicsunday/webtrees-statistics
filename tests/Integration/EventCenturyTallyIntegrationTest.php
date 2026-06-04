<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventCenturyTally;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Integration test for {@see EventCenturyTally}. Confirms that a range date
 * (`BET..AND`), which webtrees stores as two `dates` rows, counts the record
 * exactly once in its lower-bound century instead of twice.
 *
 * Fixture (`century-dedup.ged`):
 *  - I1 BIRT `BET 1850 AND 1855` (19th), DEAT `BET 1910 AND 1915` (20th)
 *  - I2 BIRT `1 JAN 1830` (19th, precise)
 *  - I3 BIRT `BET 1890 AND 1910` (straddles the 19th/20th edge)
 *  - F1 (I1 × I2) MARR `BET 1875 AND 1880` (19th), DIV `BET 1885 AND 1890` (19th)
 *
 * Births land three individuals in the 19th century: the ranged I1 once, the
 * precise I2 once, and the century-straddling I3 once in its lower-bound (1890)
 * century — a raw row count would report five (I1 and I3 contribute two rows
 * each) and the upper-bound 1910 row of I3 would leak into the 20th century.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(EventCenturyTally::class)]
#[UsesClass(DedupedEventDates::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(CenturyName::class)]
#[UsesClass(RowCast::class)]
final class EventCenturyTallyIntegrationTest extends IntegrationTestCase
{
    /**
     * Every birth counts once in its lower-bound century: the within-century
     * range (I1), the precise birth (I2) and the century-straddling range (I3)
     * all land in the 19th century, and nothing leaks into the 20th. A raw row
     * count would report five and split I3 across both centuries.
     */
    #[Test]
    public function countsRangedBirthsOncePerIndividualInTheLowerBoundCentury(): void
    {
        $tree = $this->importFixtureTree('century-dedup.ged');

        self::assertSame(['19th' => 3], EventCenturyTally::countByCentury($tree, 'BIRT'));
    }

    /**
     * Ranged death, marriage and divorce dates each count their record once in
     * the lower-bound century.
     */
    #[Test]
    public function countsRangedDeathMarriageAndDivorceOnce(): void
    {
        $tree = $this->importFixtureTree('century-dedup.ged');

        self::assertSame(['20th' => 1], EventCenturyTally::countByCentury($tree, 'DEAT'));
        self::assertSame(['19th' => 1], EventCenturyTally::countByCentury($tree, 'MARR'));
        self::assertSame(['19th' => 1], EventCenturyTally::countByCentury($tree, 'DIV'));
    }

    /**
     * BCE births fold into negative centuries the histogram labels as "%s BCE",
     * sorted ahead of the CE cohorts with no degenerate century-0 bar between
     * them. The fixture seeds:
     *  - I1 BIRT `BET 90 B.C. AND 70 B.C.` — a 1st-century-BCE range that, like
     *    its CE siblings, must count once in its lower-bound century (-90), not
     *    twice; the most-negative `d_year` is the chronological lower bound.
     *  - I2 BIRT `1 JAN 50 B.C.` — a precise 1st-century-BCE birth.
     *  - I3 BIRT `1 JAN 150 B.C.` — a 2nd-century-BCE birth.
     *  - I4 BIRT `1 JAN 50` — a 1st-century-CE birth that pins the ordering.
     *
     * A truncate-toward-zero fold would merge the BCE births into the CE
     * ordinals (or the degenerate "0 century"); a missing range dedup would
     * report the 1st-century-BCE cohort as three rather than two.
     */
    #[Test]
    public function bucketsBceBirthsIntoNegativeCenturiesOrderedAheadOfCe(): void
    {
        $tree = $this->importFixtureTree('century-dedup-bce.ged');

        self::assertSame(
            ['2nd BCE' => 1, '1st BCE' => 2, '1st' => 1],
            EventCenturyTally::countByCentury($tree, 'BIRT'),
        );
    }
}
