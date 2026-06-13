<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Model\Metric\ChildMortalitySummary;
use MagicSunday\Webtrees\Statistic\Repository\ChildMortalityRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\BirthDeathPairsQuery;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_column;

/**
 * End-to-end test of {@see ChildMortalityRepository} backed by
 * `child-mortality.ged`:
 *
 *   I1–I3 — 19th century, died < 5 years old
 *   I4–I5 — 19th century, survived past 5
 *   I6–I10 — 20th century, survived past 5
 *   I11 — 16th century, died < 5 (single child — below cohort
 *          threshold, must be dropped from the per-century view)
 *   I12 — birth only, no death (excluded)
 *   I13 — death only, no birth (excluded)
 *
 * Expected:
 *   - tree-wide: 11 valid pairs, 4 died (I1+I2+I3 in 19th, I11 in 16th) → 36.4 %
 *   - per-century: 19th 60.0 %, 20th 0.0 %, 16th omitted
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ChildMortalityRepository::class)]
#[UsesClass(ChildMortalitySummary::class)]
#[UsesClass(BirthDeathPairsQuery::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(DateJoin::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
#[UsesClass(CenturyName::class)]
final class ChildMortalityRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * The tree-wide summary counts every individual whose BIRT and DEAT
     * julian-days are both > 0 (regardless of recording completeness elsewhere)
     * and computes the under-5 percentage across all of them, ignoring
     * per-century cohort suppression.
     */
    #[Test]
    public function summaryAggregatesAcrossAllValidPairs(): void
    {
        $tree   = $this->importFixtureTree('child-mortality.ged');
        $result = (new ChildMortalityRepository($tree))->summary();

        self::assertNotNull($result);
        self::assertSame(11, $result->total);
        self::assertSame(4, $result->died);
        self::assertSame(36.4, $result->rate);
    }

    /**
     * Per-century breakdown drops cohorts below the minimum threshold (5
     * children) so the line does not spike on a single unlucky family. The
     * 16th-century single death (Klara) must not appear; the 19th and 20th
     * centuries must.
     */
    #[Test]
    public function perCenturyBreakdownSuppressesTinyCohorts(): void
    {
        $tree   = $this->importFixtureTree('child-mortality.ged');
        $result = (new ChildMortalityRepository($tree))->byBirthCentury();

        $centuries = array_column($result, 'century');
        self::assertNotContains(16, $centuries, '16th-century cohort has 1 child — below threshold, must be dropped');
        self::assertContains(19, $centuries);
        self::assertContains(20, $centuries);
    }

    /**
     * Per-century rates carry the actual numerator + denominator so the view
     * can phrase the tooltip prose ("3 of 5 children died before age 5").
     * Verify the numbers match the fixture inventory.
     */
    #[Test]
    public function perCenturyRatesCarryNumeratorAndDenominator(): void
    {
        $tree   = $this->importFixtureTree('child-mortality.ged');
        $result = (new ChildMortalityRepository($tree))->byBirthCentury();

        $byCentury = [];

        foreach ($result as $entry) {
            $byCentury[$entry['century']] = $entry;
        }

        self::assertSame(5, $byCentury[19]['total']);
        self::assertSame(3, $byCentury[19]['died']);
        self::assertSame(60.0, $byCentury[19]['rate']);

        self::assertSame(5, $byCentury[20]['total']);
        self::assertSame(0, $byCentury[20]['died']);
        self::assertSame(0.0, $byCentury[20]['rate']);
    }

    /**
     * Year-only BIRT records, BEF / AFT / ABT modifiers, and BET..AND /
     * FROM..TO ranges all land in the `dates` table with `d_day = 0` and `d_mon
     * = 0`, and webtrees still synthesises a default julian-day (typically
     * 01.01.YYYY). The under-5 threshold (`UNDER_FIVE_THRESHOLD_DAYS = 1826
     * days`) is day-precise, so a year-only BIRT 1872 + full-date DEAT
     * 10 JUN 1875 would be misread as a ~3-year-old death — a phantom under-5
     * entry. BET..AND and FROM..TO additionally write TWO rows per single
     * GEDCOM date, double-counting the individual in the JOIN.
     *
     * The dedicated fixture `child-mortality-edge-cases.ged` carries:
     *
     * * I1-I5: 19th-century full-date control — three under-5
     *   (1850→1852, 1855→1858, 1860→1863) and two long-lived
     *   (1865→1892, 1870→1955) → 5 pairs, 3 died, 60 %;
     * * I6-I10: 19th-century modifier individuals — Year-only BIRT,
     *   BEF BIRT, ABT DEAT, BET..AND BIRT (writes two BIRT rows),
     *   FROM..TO DEAT (writes two DEAT rows) → must all be filtered
     *   out before the rate count;
     * * I11-I15: 20th-century full-date control — 5 long-lived
     *   individuals, none under 5 → 0 % rate;
     * * I16: 16th-century full-date singleton — under-5 death, but
     *   below the `MIN_COHORT_SIZE = 5` floor → dropped from the
     *   per-century breakdown, still in the tree-wide summary.
     *
     * Tree-wide summary post-filter: 5 + 5 + 1 = 11 valid pairs, 3 + 0 + 1 = 4
     * died → 4 / 11 ≈ 36.4 %. Without the filter the modifier-only individuals
     * would flip every assertion: phantom under-5 counts from the year-only /
     * BEF / ABT diffs, doubled BET..AND and FROM..TO rows inflating both
     * `total` and `died`.
     */
    #[Test]
    public function summaryExcludesYearOnlyAndModifierAffectedBirthDeathPairs(): void
    {
        $tree   = $this->importFixtureTree('child-mortality-edge-cases.ged');
        $result = (new ChildMortalityRepository($tree))->summary();

        self::assertNotNull($result);
        self::assertSame(11, $result->total, '5 (19th control) + 5 (20th control) + 1 (16th singleton); modifier rows excluded');
        self::assertSame(4, $result->died, '3 (19th under-5) + 0 (20th survived) + 1 (16th under-5)');
        self::assertSame(36.4, $result->rate);
    }

    /**
     * A day-precise `BET 5 JAN 1900 AND 20 JAN 1900` birth survives the
     * full-date filter — both stored rows carry a non-zero day and month — so
     * it slips past the year-only / modifier guard that catches the coarser
     * ranges. Without a per-individual collapse the BIRT-to-DEAT self-join then
     * pairs the one child's death against both birth rows, counting the
     * under-five death twice and inflating the cohort. The deduplicated fixture
     * pairs that ranged child (died at three) with a precise survivor, so the
     * summary must read two individuals, one death, 50 % — not the pre-fix
     * three / two / 66.7 %.
     */
    #[Test]
    public function summaryCollapsesDayPreciseRangedBirthPerIndividual(): void
    {
        $tree   = $this->importFixtureTree('child-mortality-day-range-dedup.ged');
        $result = (new ChildMortalityRepository($tree))->summary();

        self::assertNotNull($result);
        self::assertSame(2, $result->total, 'Two individuals — the ranged birth counts once');
        self::assertSame(1, $result->died, 'One under-five death, not two');
        self::assertSame(50.0, $result->rate);
    }

    /**
     * The per-century breakdown filters modifier-affected rows BEFORE the
     * cohort-size gate. With the modifier rows still in, the 19th-century
     * cohort would carry 10 individuals plus the BET..AND / FROM..TO doubles;
     * with them out, the count locks back at the control's 5 individuals (3
     * died → 60 %). The 16th century stays below the `MIN_COHORT_SIZE = 5`
     * floor and is dropped from the breakdown.
     */
    #[Test]
    public function perCenturyBreakdownExcludesModifierAffectedPairsBeforeCohortGate(): void
    {
        $tree   = $this->importFixtureTree('child-mortality-edge-cases.ged');
        $result = (new ChildMortalityRepository($tree))->byBirthCentury();

        $byCentury = [];

        foreach ($result as $entry) {
            $byCentury[$entry['century']] = $entry;
        }

        self::assertArrayNotHasKey(16, $byCentury, '16th-century singleton stays below MIN_COHORT_SIZE');

        self::assertSame(5, $byCentury[19]['total'], '19th-century cohort drops back to the 5 full-date controls');
        self::assertSame(3, $byCentury[19]['died']);
        self::assertSame(60.0, $byCentury[19]['rate']);

        self::assertSame(5, $byCentury[20]['total']);
        self::assertSame(0, $byCentury[20]['died']);
        self::assertSame(0.0, $byCentury[20]['rate']);
    }

    /**
     * Boundary of the under-5 day threshold. A child who lived exactly 1825 days
     * (1 Jan 1850 → 31 Dec 1854, four years and 364 days — still before the fifth
     * birthday) MUST count as under-5; a child who lived 1826 days (1 Jan 1860 →
     * 31 Dec 1864, the fifth-birthday mark) MUST NOT. The threshold therefore has
     * to be 1826 days with a strict `<` comparison: at 1825 days the first child
     * would be wrongly excluded, undercounting genuine under-5 deaths by a day at
     * the edge — and the explanation copy already states "1826 days".
     */
    #[Test]
    public function countsTheLastDayBeforeTheFifthBirthdayAsUnderFive(): void
    {
        $tree   = $this->importFixtureTree('child-mortality-boundary.ged');
        $result = (new ChildMortalityRepository($tree))->summary();

        self::assertNotNull($result);
        self::assertSame(2, $result->total);
        self::assertSame(1, $result->died, '1825-day child is under-5; the 1826-day child has reached five');
        self::assertSame(50.0, $result->rate);
    }

    /**
     * The reported-bug class for the cohort cards (issue #135): a child whose
     * birth is written in the French Republican calendar (An XII = 1803) must
     * land in the 19th-century cohort instead of being excluded as the module
     * did before. child-mortality-non-gregorian.ged seeds five An-XII births —
     * three dead before five, two survivors with Gregorian death dates — so the
     * cohort proves both the Gregorian-century conversion AND that the
     * under-five julian-day span is correct across a French birth and a
     * Gregorian death. Under the old Gregorian/Julian-only filter the whole
     * cohort was dropped, so `$byCentury[19]` would not exist at all.
     */
    #[Test]
    public function byBirthCenturyConvertsNonGregorianBirthsToTheirGregorianCentury(): void
    {
        $tree   = $this->importFixtureTree('child-mortality-non-gregorian.ged');
        $result = (new ChildMortalityRepository($tree))->byBirthCentury();

        $byCentury = [];

        foreach ($result as $entry) {
            $byCentury[$entry['century']] = $entry;
        }

        self::assertArrayHasKey(19, $byCentury, 'French Republican An XII births form a 19th-century cohort.');
        self::assertArrayNotHasKey(1, $byCentury, 'The native An-XII year (12) is not read as the 1st century.');
        self::assertSame(5, $byCentury[19]['total']);
        self::assertSame(3, $byCentury[19]['died']);
        self::assertSame(60.0, $byCentury[19]['rate']);
    }

    /**
     * Coherence across calendars on the BirthDeathPairs seam (issue #135 audit):
     * an individual carrying TWO BIRT facts in different calendars at DISTINCT
     * julian days must be bucketed by its representative (lower-julian-day)
     * birth. child-mortality-multi-calendar.ged gives five individuals each an
     * earlier Hebrew birth (`1 TSH 5560` = 1799, 18th century) and a later
     * Gregorian birth (`1 JAN 1801`, 19th century). The representative is the
     * Hebrew one, so the cohort is the 18th century — the former independent-MIN
     * read `MIN(d_type) = @#DGREGORIAN@` with `MIN(d_year) = 1801` and
     * mis-bucketed them into the 19th. This pins the `BirthDeathPairsQuery`
     * representative-birth join-back; removing it would turn the cohort 19th.
     */
    #[Test]
    public function byBirthCenturyBucketsAMultiCalendarBirthByItsRepresentativeRow(): void
    {
        $tree   = $this->importFixtureTree('child-mortality-multi-calendar.ged');
        $result = (new ChildMortalityRepository($tree))->byBirthCentury();

        $byCentury = [];

        foreach ($result as $entry) {
            $byCentury[$entry['century']] = $entry;
        }

        self::assertArrayHasKey(18, $byCentury, 'The earlier Hebrew (1799) birth is the representative, so the cohort is the 18th century.');
        self::assertArrayNotHasKey(19, $byCentury, 'The later Gregorian (1801) birth must not drive the cohort.');
        self::assertSame(5, $byCentury[18]['total']);
        self::assertSame(3, $byCentury[18]['died']);
        self::assertSame(60.0, $byCentury[18]['rate']);
    }
}
