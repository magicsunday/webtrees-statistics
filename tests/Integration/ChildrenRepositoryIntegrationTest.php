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
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Repository\ChildrenRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_keys;
use function array_map;
use function array_sum;
use function array_values;

/**
 * Integration test for {@see ChildrenRepository}. The fixture has
 * two families: F1 with three children born 1900, 1902, 1905 (so
 * two consecutive-sibling pairs at 2-year and 3-year gaps) and F2
 * with zero children.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
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
     * children-per-family histogram puts F2 in the "0" bucket and
     * F1 in the "3" bucket. No families ≥ 10 children, so the 10+
     * overflow stays empty.
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
     * Sibling-age-gap distribution sees F1's three children at
     * 1900-1902-1905 — two pairs (2y, 3y). F2 contributes nothing.
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
     * Childless-families breakdown counts F1 (with children) and
     * F2 (without).
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
     * Fixture has 2 families and 3 children → average 1.5 per
     * family. Pass-through over core's accessor.
     */
    #[Test]
    public function averageChildrenPerFamilyMatchesCoreAccessor(): void
    {
        $tree   = $this->importFixtureTree('children.ged');
        $result = $this->repository($tree)->averageChildrenPerFamily();

        self::assertSame(1.5, $result);
    }

    /**
     * Top-N largest families list F1 first (3 children); F2 with
     * 0 children should still appear (the accessor sorts
     * descending by child count, not "only those > 0").
     */
    #[Test]
    public function topLargestFamiliesRanksByChildCount(): void
    {
        $tree   = $this->importFixtureTree('children.ged');
        $result = $this->repository($tree)->topLargestFamilies(10);

        // Two families in the fixture.
        self::assertCount(2, $result);
        // F1 wins with 3 children → first value is 3.
        self::assertSame(3, array_values($result)[0]);
    }

    /**
     * `firstChildrenByMonth` returns the GEDCOM month-keyed
     * counts. F1's three children all born in JAN → JAN ×3.
     * F2 has no children, contributes nothing.
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
     * Multiple-birth rate histogram emits one series per multiplicity
     * that actually occurs in the tree. The dedicated fixture carries:
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
     * Locks every output surface the chart-lib consumer reads:
     * categories (compact century labels in chronological order),
     * one series per multiplicity bucket present anywhere in the
     * tree, per-series CSS class hooks, and the multi-band cohort
     * floor that drops the 18th-century column entirely.
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
     * A tree with no dated births returns an empty payload so the
     * widget surfaces the EmptyStatePlaceholder rather than an axis
     * scaffold with zero lines.
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
     * INDI:ASSO partners whose BIRT julian-days sit within one day
     * of each other (cross-midnight twins) get unioned into a
     * single multi-birth set, closing the same-day-BIRT heuristic's
     * blind spot. The fixture carries 252 19th-century children:
     * 250 singletons in their own FAMs plus
     * * one cross-midnight twin pair (31 DEC 1850 / 1 JAN 1851)
     *   mutually linked via ASSO — diff = 1 day → MERGE,
     * * a negative case (10 JUN 1860 / 20 JUN 1860, also same FAM,
     *   mutually ASSO-linked) — diff = 10 days → NO merge.
     *
     * The RELA token on the ASSO sub-block is not consulted by the
     * production code; the date-proximity cutoff
     * ({@see ChildrenRepository::MULTI_BIRTH_ASSO_MAX_DAY_DIFF})
     * gates the union by itself. Locks both halves of the
     * contract: a regex typo on the ASSO scan would miss the
     * cross-midnight twins (rate=0), a missing day-diff cutoff
     * would pull in the 10-day-apart pair (rate=1.59 %).
     */
    #[Test]
    public function multipleBirthRateByCenturyUnionsAssoLinkedSiblingsWithinOneDay(): void
    {
        $tree   = $this->importFixtureTree('multi-birth-asso.ged');
        $result = $this->repository($tree)->multipleBirthRateByCentury();

        // Only the 19th century clears the cohort floor (252 dated
        // births).
        self::assertSame(['19th cent.'], $result->categories);

        // Look series up by name so a future ksort drift would
        // shift positional indexes without breaking the contract.
        $byName = [];

        foreach ($result->series as $series) {
            $byName[$series->name] = $series;
        }

        // Only Twins emitted — the 10-days-apart ASSO pair stays as
        // two singletons, so triplet / quadruplet / quintuplet+
        // series never enter the payload.
        self::assertSame(['Twins'], array_keys($byName));

        // 2 twin children of 252 ≈ 0.79 %.
        self::assertEqualsWithDelta(0.79, $byName['Twins']->values[0], 0.01);
    }
}
