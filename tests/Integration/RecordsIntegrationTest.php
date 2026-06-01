<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Enum\AgePairExtremum;
use MagicSunday\Webtrees\Statistic\Enum\Sex;
use MagicSunday\Webtrees\Statistic\Model\Record\FamilyCountRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\FamilyDurationDaysRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\FamilyDurationYearsRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualAgeRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualCountRecord;
use MagicSunday\Webtrees\Statistic\Repository\ChildrenRepository;
use MagicSunday\Webtrees\Statistic\Repository\LifeSpanRepository;
use MagicSunday\Webtrees\Statistic\Repository\MarriageRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParenthoodRepository;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\IndividualAgeRecordResolver;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * End-to-end tests for the Hall-of-Fame record-holder methods across LifeSpan /
 * Marriage / Children / Parenthood repositories. Each test imports
 * `records.ged`, runs one record method, and asserts the deliberate extreme it
 * was designed to surface:
 *
 *   I1 Centenarian (BIRT 1850, DEAT 1960, 110 years) — beats I2
 *       ShortLife (30 years) as oldest deceased.
 *
 *   F1 LongMarriedHusband + LongMarriedWife (MARR 1925, husband
 *       DEAT 1980 = 55 years) — beats F3 ShortMarried (1930
 *       MARR + DIV 06 Apr 1930 = 95 days). Two distinct record
 *       holders: longest AND shortest.
 *
 *   F2 BigDad + BigMom with 6 children — both `largestFamily`
 *       (6 children in one FAM) AND `mostChildrenPerPerson`
 *       (BigDad's only family is F2 with 6 children — beats
 *       I18 PolygamistA who has two FAMS but zero children).
 *
 *   F4 YoungParent + YoungParentWife → EarlyChild (BIRT 1915,
 *       parents born 1900 / 1905) — youngest father (15 years)
 *       AND youngest mother (10 years … below MIN_PLAUSIBLE_AGE)
 *       — actually the youngest-mother case in this fixture
 *       falls outside the plausibility band and returns null;
 *       use that as the negative control.
 *
 *   I18 PolygamistA has FAMS @F5@ + @F6@ — exercises the
 *       most-spouses record on the Marriage side.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ChildrenRepository::class)]
#[CoversClass(LifeSpanRepository::class)]
#[CoversClass(MarriageRepository::class)]
#[CoversClass(ParenthoodRepository::class)]
#[UsesClass(AgePairExtremum::class)]
#[UsesClass(Sex::class)]
#[UsesClass(FamilyCountRecord::class)]
#[UsesClass(FamilyDurationDaysRecord::class)]
#[UsesClass(FamilyDurationYearsRecord::class)]
#[UsesClass(IndividualAgeRecord::class)]
#[UsesClass(IndividualCountRecord::class)]
#[UsesClass(IndividualAgeRecordResolver::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(DateJoin::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class RecordsIntegrationTest extends IntegrationTestCase
{
    /**
     * Oldest-deceased picks Centenarian (110 years) over ShortLife (30 years) —
     * confirms descending-delta order.
     */
    #[Test]
    public function oldestDeceasedPicksTheLongestLifespan(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new LifeSpanRepository($tree, $this->statisticsData($tree)))->oldestDeceasedRecord();

        self::assertNotNull($record);
        self::assertSame('I1', $record->individual->xref());
        self::assertSame(110, $record->ageYears);
    }

    /**
     * Longest-marriage picks F1 (1925 → 1980, 55 years), not F3 (1930 MARR + 06
     * Apr 1930 DIV ≈ 95 days). Confirms that the underlying iterator picks the
     * largest end-julian-day delta.
     */
    #[Test]
    public function longestMarriagePicksFiftyFiveYearMarriage(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new MarriageRepository($tree, $this->statisticsData($tree)))->longestMarriageRecord();

        self::assertNotNull($record);
        self::assertSame('F1', $record->family->xref());
        self::assertSame(55, $record->durationYears);
    }

    /**
     * Shortest-marriage picks F3 (95 days) — confirms the
     * minimum-end-julian-day branch of the shared iterator.
     */
    #[Test]
    public function shortestMarriagePicksTheNinetyFiveDayMarriage(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new MarriageRepository($tree, $this->statisticsData($tree)))->shortestMarriageRecord();

        self::assertNotNull($record);
        self::assertSame('F3', $record->family->xref());
        self::assertSame(95, $record->durationDays);
    }

    /**
     * Largest-family picks F2 (6 children) — the only family with children in
     * the fixture.
     */
    #[Test]
    public function largestFamilyPicksTheSixChildFamily(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new ChildrenRepository($tree, $this->statisticsData($tree)))->largestFamilyRecord();

        self::assertNotNull($record);
        self::assertSame('F2', $record->family->xref());
        self::assertSame(6, $record->count);
    }

    /**
     * Most children per person — BigDad (I7) and BigMom (I8) both belong to F2
     * with six children. The aggregator picks one of the two (tie-breaker is
     * the DB's natural order over `link.l_from`, which isn't fixture-stable
     * across MySQL versions). Either is correct; what matters is that the
     * record beats PolygamistA (two FAMS, zero children).
     */
    #[Test]
    public function mostChildrenPerPersonAggregatesAcrossAllFams(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new ChildrenRepository($tree, $this->statisticsData($tree)))->mostChildrenPerPersonRecord();

        self::assertNotNull($record);
        self::assertContains($record->individual->xref(), ['I7', 'I8']);
        self::assertSame(6, $record->count);
    }

    /**
     * Youngest father at first child — YoungParent (BIRT 1900) + EarlyChild
     * (BIRT 1915) → 15 years. Sits at the MIN_PLAUSIBLE_AGE boundary (12) so it
     * survives the filter. This record carries the digit-only XREF "915" so the
     * test doubles as a regression for #71: the numeric XREF must round-trip
     * through Registry::make() and back out of xref() as the string "915", not
     * a coerced integer.
     */
    #[Test]
    public function youngestFatherAtFirstChildPicksFifteenYearOldFather(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new ParenthoodRepository($tree))->youngestParentAtFirstChildRecord('M');

        self::assertNotNull($record);
        self::assertSame('915', $record->individual->xref());
        self::assertSame(15, $record->ageYears);
    }

    /**
     * Oldest father at first child — I7 BigDad (BIRT 1900) + Child1 (BIRT 1925)
     * → 25 years. Beats YoungParent (915) at 15. Mirrors the youngest-father
     * test so both AgePairExtremum branches (Lowest / Highest) carry direct
     * coverage through the ParenthoodRepository pair iterator.
     */
    #[Test]
    public function oldestFatherAtFirstChildPicksTwentyFiveYearOldBigDad(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new ParenthoodRepository($tree))->oldestParentAtFirstChildRecord('M');

        self::assertNotNull($record);
        self::assertSame('I7', $record->individual->xref());
        self::assertSame(25, $record->ageYears);
    }

    /**
     * Oldest mother at first child — I8 BigMom (BIRT 1905) + Child1 (BIRT 1925)
     * → 20 years. The only plausible mother in the fixture: I16 YoungParentWife
     * was 10 at EarlyChild's birth which sits below MIN_PLAUSIBLE_AGE (12) and
     * gets filtered out.
     */
    #[Test]
    public function oldestMotherAtFirstChildPicksTwentyYearOldBigMom(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new ParenthoodRepository($tree))->oldestParentAtFirstChildRecord('F');

        self::assertNotNull($record);
        self::assertSame('I8', $record->individual->xref());
        self::assertSame(20, $record->ageYears);
    }

    /**
     * Most-spouses picks PolygamistA (I18) with two FAMS.
     */
    #[Test]
    public function mostSpousesPicksThePolygamist(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new MarriageRepository($tree, $this->statisticsData($tree)))->mostSpousesRecord();

        self::assertNotNull($record);
        self::assertSame('I18', $record->individual->xref());
        self::assertSame(2, $record->count);
    }

    /**
     * Youngest husband at marriage — F1 I3 (BIRT 1900, MARR 1925 → 25 years)
     * wins over I5 (BIRT 1900, MARR 1930 → 30 years). Pins the youngest-spouse
     * picker through the resolver chain that just replaced the inline
     * Registry+instanceof block.
     */
    #[Test]
    public function youngestHusbandAtMarriagePicksTheTwentyFiveYearOld(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new MarriageRepository($tree, $this->statisticsData($tree)))->youngestSpouseAtMarriageRecord('M');

        self::assertNotNull($record);
        self::assertSame('I3', $record->individual->xref());
        self::assertSame(25, $record->ageYears);
    }

    /**
     * Oldest husband at marriage — F3 I5 (BIRT 1900, MARR 1930 → 30 years)
     * wins. Mirrors the youngest picker so both branches of the shared resolver
     * carry coverage.
     */
    #[Test]
    public function oldestHusbandAtMarriagePicksTheThirtyYearOld(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new MarriageRepository($tree, $this->statisticsData($tree)))->oldestSpouseAtMarriageRecord('M');

        self::assertNotNull($record);
        self::assertSame('I5', $record->individual->xref());
        self::assertSame(30, $record->ageYears);
    }

    /**
     * Youngest wife at marriage — F1 I4 (BIRT 1905, MARR 1925 → 20 years) wins.
     */
    #[Test]
    public function youngestWifeAtMarriagePicksTheTwentyYearOld(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new MarriageRepository($tree, $this->statisticsData($tree)))->youngestSpouseAtMarriageRecord('F');

        self::assertNotNull($record);
        self::assertSame('I4', $record->individual->xref());
        self::assertSame(20, $record->ageYears);
    }

    /**
     * Oldest wife at marriage — F3 I6 (BIRT 1905, MARR 1930 → 25 years) wins.
     */
    #[Test]
    public function oldestWifeAtMarriagePicksTheTwentyFiveYearOld(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = (new MarriageRepository($tree, $this->statisticsData($tree)))->oldestSpouseAtMarriageRecord('F');

        self::assertNotNull($record);
        self::assertSame('I6', $record->individual->xref());
        self::assertSame(25, $record->ageYears);
    }
}
