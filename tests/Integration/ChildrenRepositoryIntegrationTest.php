<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarPayload;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarSeries;
use MagicSunday\Webtrees\Statistic\Repository\ChildrenRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use MagicSunday\Webtrees\Statistic\Support\Locale\DecadeName;
use MagicSunday\Webtrees\Statistic\Support\Locale\MonthName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_keys;
use function array_map;
use function array_sum;

/**
 * Integration tests for {@see ChildrenRepository} backed by several curated
 * GEDCOM fixtures — see each test's docblock for the family layout the
 * assertions ride on. The shared lookups (children-per- family,
 * sibling-age-gap, childless distribution, first child by month, average per
 * family) ride on `children.ged`; the multi-birth,
 * sibling-modifier-edge-case, and cross-midnight proximity paths bring their
 * own dedicated fixtures.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ChildrenRepository::class)]
#[UsesClass(LineChartPayload::class)]
#[UsesClass(LineChartSeries::class)]
#[UsesClass(StackedBarPayload::class)]
#[UsesClass(StackedBarSeries::class)]
#[UsesClass(DecadeName::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(DateJoin::class)]
#[UsesClass(MonthName::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
#[UsesClass(CenturyName::class)]
final class ChildrenRepositoryIntegrationTest extends IntegrationTestCase
{
    private function repository(Tree $tree): ChildrenRepository
    {
        return new ChildrenRepository(
            $tree,
            $this->statisticsData($tree),
        );
    }

    /**
     * children-per-family histogram puts F2 in the "0" bucket and F1 in the "3"
     * bucket. No families ≥ 10 children, so the 10+ overflow stays empty.
     */
    #[Test]
    public function childrenPerFamilyDistributionCountsByFamilyChildCount(): void
    {
        $tree   = $this->importFixtureTree('children.ged');
        $result = $this->repository($tree)->childrenPerFamilyDistribution();

        self::assertSame(1, $result['0'], 'F2 has zero children');
        self::assertSame(1, $result['3'], 'F1 has three children');
        self::assertSame(0, $result['10+'], 'no heroic families');
    }

    /**
     * Sibling-age-gap distribution sees F1's three children at 1900-1902-1905 —
     * two pairs (2y, 3y). F2 contributes nothing.
     */
    #[Test]
    public function siblingAgeGapDistributionMeasuresConsecutivePairs(): void
    {
        $tree   = $this->importFixtureTree('children.ged');
        $result = $this->repository($tree)->siblingAgeGapDistribution();

        self::assertSame(1, $result['2y'] ?? null, '1900 → 1902 is a 2-year gap');
        self::assertSame(1, $result['3y'] ?? null, '1902 → 1905 is a 3-year gap');
        self::assertSame(2, array_sum($result));
    }

    /**
     * Year-only BIRT records, BEF/AFT/ABT modifiers, and BET..AND / FROM..TO
     * ranges all land in the `dates` table with `d_day = 0` and `d_mon = 0`,
     * and webtrees still synthesises a default julian-day (typically
     * 01.01.YYYY) so the row passes the `d_julianday1 <> 0` sentinel filter.
     * Without explicitly gating on `d_day > 0 AND d_mon > 0` the distribution
     * picks up three pathologies:
     *
     * * two year-only siblings of the same year collide in the `0y`
     *   bucket (phantom twins) — the distribution must NOT confuse
     *   them with real same-day siblings;
     * * a `BET..AND` child shows up as two rows in the JOIN, so the
     *   JD-sorted run produces a self-gap with the same `i_id` on
     *   both sides;
     * * `BEF` and `ABT` collapse onto their bare year, then compete
     *   with neighbouring full-date siblings via a default-JD anchor
     *   that ignores the actual GEDCOM modifier.
     *
     * The fixture carries:
     *
     * * F1 — three full-date children (15 MAR 1900, 10 AUG 1902,
     *   22 JUN 1905) → two ~2-year gaps;
     * * F2 — six children, ALL with modifiers (Year-only, BEF, ABT,
     *   BET..AND, AFT, FROM..TO) → must contribute zero gaps;
     * * F3 — mixed: three full-date siblings (20 MAR 1850, 10 AUG
     *   1853, 5 JUL 1859) PLUS a year-only sibling 1856 → the
     *   year-only child drops out, leaving the two surviving gaps
     *   1850→1853 (3y) and 1853→1859 (5y). The 5y overshoot is the
     *   documented trade-off: filtering the year-only sibling shifts
     *   the consecutive-pair calculation onto the next full-date
     *   sibling;
     * * F4 — two children with IDENTICAL full-date BIRT (14 FEB 1910)
     *   → real same-day twins that the filter must NOT remove;
     *   exercises the positive side of the `0y` bucket;
     * * F5 — BEF discriminator: 15 JAN 1820, BEF 1822, 15 JUN 1827 →
     *   BEF child dropped, surviving pair gaps 1820→1827 ≈ 7.4 years
     *   into `7y`;
     * * F6 — ABT discriminator: 10 MAR 1830, ABT 1832, 5 DEC 1838 →
     *   ABT child dropped, surviving pair gaps 1830→1838 ≈ 8.74 years
     *   into `8y`;
     * * F7 — BET..AND discriminator: 20 APR 1840, BET 1843 AND 1845
     *   (writes TWO rows!), 10 DEC 1849 → BET..AND child dropped
     *   (both rows), surviving pair gaps 1840→1849 ≈ 9.64 years into
     *   `9y`. Without the filter the BET..AND child would also
     *   produce a phantom self-gap with itself.
     *
     * The per-modifier buckets (7y / 8y / 9y) are intentionally distinct so a
     * regression that lets one modifier slip through the filter flips exactly
     * one assertion — pinpointing the defective modifier rather than masking it
     * in a shared bucket.
     */
    #[Test]
    public function siblingAgeGapDistributionExcludesYearOnlyAndModifierSiblings(): void
    {
        $tree   = $this->importFixtureTree('sibling-age-gap-edge-cases.ged');
        $result = $this->repository($tree)->siblingAgeGapDistribution();

        self::assertSame(1, $result['0y'] ?? 0, 'F4: real twins (identical full-date BIRT) stay in 0y');
        self::assertSame(2, $result['2y'] ?? 0, 'F1: 1900→1902 and 1902→1905 are both 2-year gaps');
        self::assertSame(1, $result['3y'] ?? 0, 'F3: 1850→1853 survives, year-only 1856 dropped');
        self::assertSame(1, $result['5y'] ?? 0, 'F3: 1853→1859 is the next surviving consecutive pair');
        self::assertSame(1, $result['7y'] ?? 0, 'F5: BEF 1822 dropped, 15 JAN 1820 → 15 JUN 1827 ≈ 7y overshoot');
        self::assertSame(1, $result['8y'] ?? 0, 'F6: ABT 1832 dropped, 10 MAR 1830 → 5 DEC 1838 ≈ 8y overshoot');
        self::assertSame(1, $result['9y'] ?? 0, 'F7: BET..AND 1843-1845 dropped (both rows), 20 APR 1840 → 10 DEC 1849 ≈ 9y overshoot');
        self::assertSame(8, array_sum($result), 'F2 modifier-only contributes 0; F1=2 + F3=2 + F4=1 + F5=1 + F6=1 + F7=1 = 8');
    }

    /**
     * Childless-families breakdown counts F1 (with children) and F2 (without).
     */
    #[Test]
    public function childlessFamiliesDistributionIsBinary(): void
    {
        $tree   = $this->importFixtureTree('children.ged');
        $result = $this->repository($tree)->childlessFamiliesDistribution();

        $byLabel = [];

        foreach ($result as $entry) {
            $byLabel[$entry['label']] = $entry['value'];
        }

        self::assertSame(1, $byLabel['With children'] ?? null);
        self::assertSame(1, $byLabel['Without children'] ?? null);
    }

    /**
     * Fixture has 2 families and 3 children → average 1.5 per family.
     * Pass-through over core's accessor.
     */
    #[Test]
    public function averageChildrenPerFamilyMatchesCoreAccessor(): void
    {
        $tree   = $this->importFixtureTree('children.ged');
        $result = $this->repository($tree)->averageChildrenPerFamily();

        self::assertSame(1.5, $result);
    }

    /**
     * `firstChildrenByMonth` returns the GEDCOM month-keyed counts. F1's three
     * children all born in JAN → JAN ×3. F2 has no children, contributes
     * nothing.
     */
    #[Test]
    public function firstChildrenByMonthCountsTheFirstChildPerFamily(): void
    {
        $tree   = $this->importFixtureTree('children.ged');
        $result = $this->repository($tree)->firstChildrenByMonth();

        // The first child of F1 was born JAN 1900.
        self::assertSame(1, $result['JAN'] ?? null);
        // No other months touched.
        self::assertSame(1, array_sum($result));
    }

    /**
     * The first child's birth may itself be an imprecise `BET 25 DEC 1899 AND
     * 5 JAN 1900` range, stored as two `dates` rows that straddle the December
     * / January month boundary. The card must count that first child once, in
     * its lower-bound month (December), not once per stored bound. webtrees'
     * own first-child query already collapses the range — it picks each
     * family's earliest child by `MIN(d_julianday1)` and joins back on that
     * single lower bound — so the pass-through stays range-safe; this test
     * locks that contract so a future re-implementation cannot silently
     * reintroduce the split.
     */
    #[Test]
    public function firstChildrenByMonthCountsARangedFirstBirthOnce(): void
    {
        $tree   = $this->importFixtureTree('first-children-month-range-dedup.ged');
        $result = $this->repository($tree)->firstChildrenByMonth();

        self::assertSame(1, $result['DEC'] ?? null, 'The ranged first birth counts once in its lower-bound month');
        self::assertSame(0, $result['JAN'] ?? null, 'The upper bound never spawns a second January tally');
        self::assertSame(1, array_sum($result), 'One family, one first child');
    }

    /**
     * The first-child anchor must be the child's BIRT fact, not the earliest of
     * *any* dated fact on the child record. The Anchor family's only child
     * carries an (erroneous) `RESI 10 JAN 1900` fact dated before its `BIRT 15
     * JUN 1900`; anchoring on the residence would mis-attribute the family to
     * January. The Control family's lone child is born cleanly in March and
     * pins the tally so the assertions cannot pass by zeroing every month.
     */
    #[Test]
    public function firstChildrenByMonthAnchorsOnTheBirthFactNotAnEarlierEvent(): void
    {
        $tree   = $this->importFixtureTree('first-children-month-nonbirth-anchor.ged');
        $result = $this->repository($tree)->firstChildrenByMonth();

        self::assertSame(1, $result['JUN'] ?? null, 'The first child is anchored on its June birth fact');
        self::assertSame(0, $result['JAN'] ?? null, 'The earlier non-birth fact never anchors the family');
        self::assertSame(1, $result['MAR'] ?? null, 'The control family is anchored on its March birth');
        self::assertSame(2, array_sum($result), 'Two families, two first children');
    }

    /**
     * Two children of one family may be born on the exact same julian day
     * (twins), so both birth rows share the family's minimum `d_julianday1`.
     * The card must count that family once in the shared month, not once per
     * tied child. The Twins family's twins are both born 1 JUN 1900; the
     * Control family's lone March child pins the tally so the assertions cannot
     * pass by zeroing every month.
     */
    #[Test]
    public function firstChildrenByMonthCountsSameDayTwinsOnce(): void
    {
        $tree   = $this->importFixtureTree('first-children-month-same-day-twins.ged');
        $result = $this->repository($tree)->firstChildrenByMonth();

        self::assertSame(1, $result['JUN'] ?? null, 'The same-day twins collapse to one June tally');
        self::assertSame(1, $result['MAR'] ?? null, 'The control family is anchored on its March birth');
        self::assertSame(2, array_sum($result), 'Two families, two first children');
    }

    /**
     * The family must be anchored on the month of its earliest-born child, not
     * on the lowest month number across all its children. The Mixed family's
     * first child is born June 1900; two later twins are born March 1905. The
     * join-back keys the family on the earliest julian day (June), so a
     * regression that aggregated the month across every child — or that dropped
     * the `min_birth_jd` correlation and tallied the later twins — would surface
     * March instead. A no-op double-count of the later twins would likewise
     * break the single-family total.
     */
    #[Test]
    public function firstChildrenByMonthAnchorsOnTheEarliestChildsMonth(): void
    {
        $tree   = $this->importFixtureTree('first-children-month-earliest-vs-later-twins.ged');
        $result = $this->repository($tree)->firstChildrenByMonth();

        self::assertSame(1, $result['JUN'] ?? null, 'The family is anchored on its June-born first child');
        self::assertSame(0, $result['MAR'] ?? null, 'The later March twins never anchor or double-count the family');
        self::assertSame(1, array_sum($result), 'One family, one first child');
    }

    /**
     * A year-only birth carries no month, so it cannot anchor a month tally —
     * the family falls through to its earliest *month-dated* child instead,
     * exactly as in webtrees core's first-child query. The YearOnly family's
     * first child is dated `1900` (no month) and its second child `4 JUL 1901`;
     * the family must anchor on July, not on the undated earlier child. Were the
     * month-known filter dropped, the year-only child's synthesised julian day
     * would win the family minimum and the family would land in no bucket — so
     * asserting July (not an empty result) discriminates that filter. The
     * Control family's March birth pins a clean single-child family alongside.
     */
    #[Test]
    public function firstChildrenByMonthFallsThroughAYearOnlyBirthToTheNextDatedChild(): void
    {
        $tree   = $this->importFixtureTree('first-children-month-year-only-fallthrough.ged');
        $result = $this->repository($tree)->firstChildrenByMonth();

        self::assertSame(1, $result['JUL'] ?? null, 'The family anchors on its earliest month-dated child, skipping the year-only birth');
        self::assertSame(0, $result['JAN'] ?? null, 'The year-only birth never anchors a (synthesised January) bucket');
        self::assertSame(1, $result['MAR'] ?? null, 'The month-precise control family is still counted');
        self::assertSame(2, array_sum($result), 'Two families, two first children');
    }

    /**
     * Multiple-birth rate histogram emits one series per multiplicity that
     * actually occurs in the tree. The dedicated fixture carries:
     *
     * * 18th century: 50 singletons (below MIN_COHORT_MULTIPLE_BIRTH=200,
     *   so the whole century is dropped).
     * * 19th century: 180 singletons + 5 twin sets (10 children)
     *   + 1 triplet set (3 children) + 1 quadruplet set (4 children)
     *   + 1 sextuplet set (6 children, lands in the 5+ bucket) = 203
     *   children. Qualifies. 27 multi-birth children → 13.3 % rate
     *   spread across all four multiplicities.
     * * 20th century: 195 singletons + 4 twin sets (8 children) = 203
     *   children. Qualifies. Only the twin series carries a non-zero
     *   value for this century.
     *
     * Locks every output surface the chart-lib consumer reads: categories
     * (compact century labels in chronological order), one series per
     * multiplicity bucket present anywhere in the tree, per-series CSS class
     * hooks, and the multi-band cohort floor that drops the 18th-century column
     * entirely.
     */
    #[Test]
    public function multipleBirthRateByCenturyEmitsOneSeriesPerMultiplicity(): void
    {
        $tree   = $this->importFixtureTree('multi-birth-rate.ged');
        $result = $this->repository($tree)->multipleBirthRateByCentury();

        // 18th century dropped (50 < 200 floor); 19th + 20th qualify.
        self::assertSame(['19th cent.', '20th cent.'], $result->categories);

        // Four series, one per multiplicity present anywhere in the
        // tree (Twins, Triplets, Quadruplets, Quintuplets+).
        $names = array_map(static fn (LineChartSeries $s): string => $s->name, $result->series);
        self::assertSame(
            ['Twins', 'Triplets', 'Quadruplets', 'Quintuplets and above'],
            $names,
        );

        $classes = array_map(static fn (LineChartSeries $s): string => $s->class ?? '', $result->series);
        self::assertSame(
            [
                'multiple-birth-twin',
                'multiple-birth-triplet',
                'multiple-birth-quadruplet',
                'multiple-birth-quintuplet-plus',
            ],
            $classes,
        );

        // 19th century: 10 twin children of 203 ≈ 4.93 %.
        self::assertEqualsWithDelta(4.93, $result->series[0]->values[0], 0.01);
        // 20th century: 8 twin children of 202 ≈ 3.96 %. (Year-1900
        // singletons drift into the 19th century cohort by one
        // because CenturyName::fromYear(1900) = 19.)
        self::assertEqualsWithDelta(3.96, $result->series[0]->values[1], 0.01);
        // 19th century: 3 triplet children of 203 ≈ 1.48 %.
        self::assertEqualsWithDelta(1.48, $result->series[1]->values[0], 0.01);
        // 20th century: no triplets.
        self::assertSame(0.0, $result->series[1]->values[1]);
        // 19th: 6 quintuplet+ children of 203 ≈ 2.96 %.
        self::assertEqualsWithDelta(2.96, $result->series[3]->values[0], 0.01);
    }

    /**
     * BCE births fold into a negative century rather than being dropped.
     * multi-birth-rate-bce.ged seeds 202 children all born in the 1st century
     * BCE — 200 singletons plus one same-day twin pair — so the cohort clears
     * the 200-child floor and the Twins series surfaces at 2/202 ≈ 0.99 %. A
     * regression that re-introduces either `birth_year <= 0` guard (the load
     * loop building the per-century totals, or the per-set primary-year guard)
     * drops the whole BCE cohort and returns an empty payload.
     */
    #[Test]
    public function multipleBirthRateByCenturyBucketsBceBirthsIntoNegativeCenturies(): void
    {
        $tree   = $this->importFixtureTree('multi-birth-rate-bce.ged');
        $result = $this->repository($tree)->multipleBirthRateByCentury();

        self::assertSame(
            [CenturyName::compactLabel(-1)],
            $result->categories,
        );
        self::assertSame('Twins', $result->series[0]->name);
        self::assertEqualsWithDelta(0.99, $result->series[0]->values[0], 0.01);
    }

    /**
     * Average family size by century folds BCE marriages into negative
     * centuries and picks the chronologically EARLIEST marriage per family
     * across the sign boundary (a more-negative year is earlier).
     * family-size-bce.ged seeds five 1st-century-BCE families (F1 2 children @
     * 60 B.C., F9 3 @ 50 B.C., F2 4 @ 40 B.C., F4 2 @ its earliest of two MARR
     * dates, 30 B.C., F10 1 @ 10 B.C.) and five 20th-century CE families (F3 3 @
     * 1905, F6 1 @ 1901, F7 2 @ 1902, F8 5 @ 1903, F11 2 @ 1906). Both centuries
     * sit exactly at the five-family cohort floor so this test isolates the
     * BCE-bucketing behaviour rather than the floor (see the dedicated
     * floor-straddle test for that). F5 carries a MARR with no parseable date
     * and drops out. The BCE century averages 12/5 = 2.4 and sorts ahead of the
     * CE cohort (13/5 = 2.6). A regression that clamped BCE years to 0 (the old
     * `max($year, 0)`) or kept the `<= 0` filter would drop every BCE family.
     */
    #[Test]
    public function averageFamilySizeByCenturyBucketsBceMarriagesIntoNegativeCenturies(): void
    {
        $tree   = $this->importFixtureTree('family-size-bce.ged');
        $result = $this->repository($tree)->averageFamilySizeByCentury();

        self::assertSame(
            [CenturyName::compactLabel(-1), CenturyName::compactLabel(20)],
            $result->categories,
        );
        self::assertEqualsWithDelta(2.4, $result->series[0]->values[0], 0.001, 'BCE: (2+3+4+2+1)/5 families');
        self::assertEqualsWithDelta(2.6, $result->series[0]->values[1], 0.001, 'CE: (3+1+2+5+2)/5 families');
    }

    /**
     * A century backed by fewer than {@see ChildrenRepository::MIN_COHORT_FAMILY_SIZE}
     * dated families is dropped from the line so a one- or two-family century
     * cannot render a spiky, statistically unrepresentative mean with the same
     * visual weight as a century backed by thousands — the same five-sample
     * cohort floor the child-mortality and tree-health per-century timelines
     * apply. family-size-cohort-floor.ged straddles the threshold: the 17th
     * century is backed by four families (below the floor of five → dropped)
     * and the 19th century by five (at the floor → kept). The dropped century
     * carries a distinct mean of 1.0, so a regression that removed the floor
     * would surface it as an extra leading point rather than silently agreeing.
     */
    #[Test]
    public function averageFamilySizeByCenturyDropsCenturiesBelowTheCohortFloor(): void
    {
        $tree   = $this->importFixtureTree('family-size-cohort-floor.ged');
        $result = $this->repository($tree)->averageFamilySizeByCentury();

        self::assertSame(
            [CenturyName::compactLabel(19)],
            $result->categories,
            'The four-family 17th century is below the floor and must not appear.',
        );
        self::assertCount(1, $result->series[0]->values);
        self::assertEqualsWithDelta(
            2.4,
            $result->series[0]->values[0],
            0.001,
            '19th century: (2+2+2+2+4)/5 families',
        );
    }

    /**
     * The decade-axis family-size chart folds BCE marriages into negative
     * decade keys, labelled "60s BCE" … "10s BCE" and ordered ahead of the CE
     * bar. The decade chart applies NO cohort floor (it is a composition chart,
     * not a per-cohort mean), so every populated decade becomes a bar.
     * family-size-bce.ged seeds BCE families at 60 / 50 / 40 / 30 / 10 B.C.
     * (decades −60 / −50 / −40 / −30 / −10, with 2 / 3 / 4 / 2 / 1 children) and
     * five CE families collapsed onto the 1900s bar (1 / 2 / 2 / 3 / 5 children).
     * The chart is sparse, so only populated decades become bars.
     */
    #[Test]
    public function familySizeStackedByDecadeIncludesBceDecades(): void
    {
        $tree   = $this->importFixtureTree('family-size-bce.ged');
        $result = $this->repository($tree)->familySizeStackedByDecade();

        self::assertSame(
            [
                DecadeName::for(-60),
                DecadeName::for(-50),
                DecadeName::for(-40),
                DecadeName::for(-30),
                DecadeName::for(-10),
                DecadeName::for(1900),
            ],
            $result->categories,
        );

        // Stacked buckets per decade: 1 / 2 / 3 / 4+ children. Columns line up
        // with the categories above (−60s, −50s, −40s, −30s, −10s, 1900s).
        self::assertSame(
            [
                [0, 0, 0, 0, 1, 1], // 1 child:  F10 (−10s), F6 (1900s)
                [1, 0, 0, 1, 0, 2], // 2 child:  F1 (−60s), F4 (−30s), F7 + F11 (1900s)
                [0, 1, 0, 0, 0, 1], // 3 child:  F9 (−50s), F3 (1900s)
                [0, 0, 1, 0, 0, 1], // 4+ child: F2 (−40s), F8 (1900s)
            ],
            array_map(static fn (StackedBarSeries $series): array => $series->data, $result->series),
        );
    }

    /**
     * A tree with no dated births returns an empty payload so the widget
     * surfaces the EmptyStatePlaceholder rather than an axis scaffold with zero
     * lines.
     */
    #[Test]
    public function multipleBirthRateByCenturyReturnsEmptyPayloadWithoutDatedChildren(): void
    {
        $tree   = $this->importFixtureTree('empty-marriages.ged');
        $result = $this->repository($tree)->multipleBirthRateByCentury();

        self::assertSame([], $result->categories);
        self::assertSame([], $result->series);
    }

    /**
     * Same-FAM siblings whose BIRT julian-days sit within one calendar day of
     * the set's earliest birth form a multi-birth set, with no INDI:ASSO link
     * required — a mother cannot deliver two separate pregnancies a day apart,
     * so same-FAM proximity is the signal by itself. Detection anchors each set
     * on its earliest birth rather than chaining off the previous child, so a
     * set never grows past a one-day span. The fixture carries 262 19th-century
     * children: 250 singletons in their own FAMs plus five hand-placed FAMs
     * that exercise every branch of the rule:
     * * F350 — 31 DEC 1850 / 1 JAN 1851: span 1 day → MERGE (twin),
     * * F351 — 10 JUN 1860 / 20 JUN 1860: span 10 days → NO merge,
     * * F352 — 10 JAN 1862 / 12 JAN 1862: span 2 days → NO merge,
     *   the boundary case one day past the tolerance,
     * * F353 — 30 DEC 1864 / 30 DEC 1864 / 31 DEC 1864: span 1 day
     *   → MERGE (cross-midnight triplet, two before midnight + one
     *   after),
     * * F354 — 28 / 29 / 30 DEC 1866: consecutive single-day steps.
     *   Anchored on the 28th, the 30th is 2 days out and splits off,
     *   so this yields a twin (28 / 29) plus a singleton — NOT a
     *   chained triplet.
     *
     * Resulting sets: 2 twin sets (F350 + F354's 28/29 pair) = 4 children, 1
     * triplet set (F353) = 3 children. The chaining bug this guards against
     * would instead read F354 as a triplet, flipping the figures to 1 twin set
     * (2 children) + 2 triplet sets (6 children).
     */
    #[Test]
    public function multipleBirthRateByCenturyUnionsSameFamilySiblingsBornWithinOneDay(): void
    {
        $tree   = $this->importFixtureTree('multi-birth-proximity.ged');
        $result = $this->repository($tree)->multipleBirthRateByCentury();

        // Only the 19th century clears the cohort floor (262 dated
        // births).
        self::assertSame(['19th cent.'], $result->categories);

        // Look series up by name so a future ksort drift would
        // shift positional indexes without breaking the contract.
        $byName = [];

        foreach ($result->series as $series) {
            $byName[$series->name] = $series;
        }

        // Twins and Triplets only — the 10-day and 2-day pairs stay
        // as singletons, and the consecutive-day F354 splits rather
        // than chaining into a quadruplet / quintuplet+.
        self::assertSame(['Twins', 'Triplets'], array_keys($byName));

        // 4 twin children of 262 ≈ 1.53 % (F350 + F354's anchored
        // 28/29 pair). Under the chaining bug this would be 0.76 %.
        self::assertEqualsWithDelta(1.53, $byName['Twins']->values[0], 0.01);

        // 3 triplet children of 262 ≈ 1.15 % (F353 only). Under the
        // chaining bug F354 would add a second triplet set → 2.29 %.
        self::assertEqualsWithDelta(1.15, $byName['Triplets']->values[0], 0.01);
    }
}
