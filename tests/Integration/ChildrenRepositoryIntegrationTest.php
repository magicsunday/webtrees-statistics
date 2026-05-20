<?php

declare(strict_types=1);

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

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

        self::assertSame(1, $result['0'] ?? null, 'F2 has zero children');
        self::assertSame(1, $result['3'] ?? null, 'F1 has three children');
        self::assertSame(0, $result['10+'] ?? null, 'no heroic families');
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
}
