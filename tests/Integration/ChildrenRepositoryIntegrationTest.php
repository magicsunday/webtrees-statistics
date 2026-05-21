<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Repository\ChildrenRepository;
use PHPUnit\Framework\Attributes\Test;

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
            new StatisticsData($tree, new UserService()),
        );
    }

    /**
     * children-per-family histogram puts F2 in the "0" bucket and
     * F1 in the "3" bucket. No families ≥ 10 children, so the 10+
     * overflow stays empty.
     */
    #[Test]
    public function childrenPerFamilyHistogramCountsByFamilyChildCount(): void
    {
        $tree   = $this->importFixtureTree('children.ged');
        $result = $this->repository($tree)->childrenPerFamilyHistogram();

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
    public function childlessFamiliesBreakdownIsBinary(): void
    {
        $tree   = $this->importFixtureTree('children.ged');
        $result = $this->repository($tree)->childlessFamiliesBreakdown();

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
     * `familySizeByCentury` groups families by century-of-MARR and
     * child-count bucket. Fixture (family-size-by-century.ged):
     *   F1 MARR 1850, 2 children          → 19th / bucket "2"
     *   F2 MARR 1875, 1 child             → 19th / bucket "1"
     *   F3 MARR 1925, 3 children          → 20th / bucket "3"
     *   F4 MARR 1960, 0 children          → 20th / bucket "0"
     *   F5 MARR (no DATE)                 → DROPPED (no temporal anchor)
     *   F6 MARR 1955, 12 children         → 20th / bucket "10+" overflow
     *   F7 MARR 1899 + 1901, 4 children   → 19th / bucket "4" (earliest year wins).
     */
    #[Test]
    public function familySizeByCenturyBucketsByMarriageCentury(): void
    {
        $tree   = $this->importFixtureTree('family-size-by-century.ged');
        $result = $this->repository($tree)->familySizeByCentury();

        self::assertSame('root', $result['name']);
        self::assertSame('19th', $result['children'][0]['name']);
        self::assertSame('20th', $result['children'][1]['name']);

        self::assertSame(1, $this->bucketValue($result['children'][0]['children'], '1'), 'F2 1875 → 1 child');
        self::assertSame(1, $this->bucketValue($result['children'][0]['children'], '2'), 'F1 1850 → 2 children');
        self::assertSame(1, $this->bucketValue($result['children'][0]['children'], '4'), 'F7 1899/1901 → 4 children, earliest year places it in 19th');
        self::assertCount(3, $result['children'][0]['children']);

        self::assertSame(1, $this->bucketValue($result['children'][1]['children'], '0'), 'F4 1960 → 0 children');
        self::assertSame(1, $this->bucketValue($result['children'][1]['children'], '3'), 'F3 1925 → 3 children');
        self::assertSame(1, $this->bucketValue($result['children'][1]['children'], '10+'), 'F6 1955 → 12 children → 10+ overflow');
        self::assertCount(3, $result['children'][1]['children']);
    }

    /**
     * Multi-MARR families count once, anchored to the earliest year.
     * F7 has two MARR DATE rows (1899 and 1901). Without the PHP-side
     * aggregation a LEFT JOIN duplicates the row and the family
     * would either be counted twice or land in the 20th century via
     * the 1901 row. Both centuries would over-count.
     */
    #[Test]
    public function familySizeByCenturyPicksEarliestMarrYearForMultiMarrFamilies(): void
    {
        $tree   = $this->importFixtureTree('family-size-by-century.ged');
        $result = $this->repository($tree)->familySizeByCentury();

        // F7 has 4 children — only the 19th-century bucket "4"
        // gets the family. A 20th-century bucket "4" would prove
        // the earliest-year-wins rule is broken.
        self::assertSame(1, $this->bucketValue($result['children'][0]['children'], '4'), 'F7 anchored to 1899 → 19th');
        self::assertSame(0, $this->bucketValue($result['children'][1]['children'], '4'), 'F7 must not also surface in 20th');
    }

    /**
     * Families with a MARR fact but no DATE are dropped — the chart
     * needs a temporal anchor and undated marriages cannot be
     * placed on a century. The F5 row in the fixture exercises this
     * branch.
     */
    #[Test]
    public function familySizeByCenturyDropsMarriedFamiliesWithoutDate(): void
    {
        $tree   = $this->importFixtureTree('family-size-by-century.ged');
        $result = $this->repository($tree)->familySizeByCentury();

        // Sum every bucket across every century — the fixture has 7
        // families but F5 is undated and must not appear, so the
        // grand total is 6.
        $grandTotal = 0;

        foreach ($result['children'] as $century) {
            foreach ($century['children'] as $bucket) {
                $grandTotal += $bucket['value'];
            }
        }

        self::assertSame(6, $grandTotal);
    }

    /**
     * 12-children family F6 surfaces as a single "10+" bucket with
     * the `family-size-max` class — the overflow lane the CSS
     * gradient is wired for.
     */
    #[Test]
    public function familySizeByCenturyOverflowsAtTenPlus(): void
    {
        $tree   = $this->importFixtureTree('family-size-by-century.ged');
        $result = $this->repository($tree)->familySizeByCentury();

        self::assertSame('family-size-max', $this->bucketClass($result['children'][1]['children'], '10+'));
    }

    /**
     * Leaf tiles carry the `family-size-{N}` class so the gradient
     * CSS picks up the magnitude cue. The "10+" overflow uses the
     * `family-size-max` class so the CSS rule doesn't need a token
     * called `family-size-10+`.
     */
    #[Test]
    public function familySizeByCenturyLeavesCarryStableCssClasses(): void
    {
        $tree   = $this->importFixtureTree('family-size-by-century.ged');
        $result = $this->repository($tree)->familySizeByCentury();

        self::assertSame('family-size-1', $this->bucketClass($result['children'][0]['children'], '1'));
        self::assertSame('family-size-2', $this->bucketClass($result['children'][0]['children'], '2'));
    }

    /**
     * Helper: find a leaf by its bucket label and return its value.
     * Returns 0 when the bucket is absent so assertions read as
     * "expected count vs actual" rather than as missing-key noise.
     *
     * @param list<array{name: string, value: int, class: string}> $leaves
     */
    private function bucketValue(array $leaves, string $bucket): int
    {
        foreach ($leaves as $leaf) {
            if ($leaf['name'] === $bucket) {
                return $leaf['value'];
            }
        }

        return 0;
    }

    /**
     * Helper: find a leaf by its bucket label and return its class.
     * Returns the empty string when the bucket is absent so the
     * assertSame failure is "expected vs ''" rather than a null/
     * mixed-access surprise.
     *
     * @param list<array{name: string, value: int, class: string}> $leaves
     */
    private function bucketClass(array $leaves, string $bucket): string
    {
        foreach ($leaves as $leaf) {
            if ($leaf['name'] === $bucket) {
                return $leaf['class'];
            }
        }

        return '';
    }

    /**
     * Empty tree (no families at all) returns the empty hierarchy
     * shape — chart-lib's empty-state placeholder picks up the
     * absence and renders the "no data" message.
     */
    #[Test]
    public function familySizeByCenturyReturnsEmptyShapeOnNoFamilies(): void
    {
        $tree   = $this->importFixtureTree('empty-marriages.ged');
        $result = $this->repository($tree)->familySizeByCentury();

        self::assertSame(['name' => 'root', 'children' => []], $result);
    }
}
