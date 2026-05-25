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
use MagicSunday\Webtrees\Statistic\Repository\NameRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for {@see NameRepository}. Uses the existing
 * `name-trends.ged` fixture (eleven dated individuals with five
 * distinct given names + one common surname "Test").
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class NameRepositoryIntegrationTest extends IntegrationTestCase
{
    private function repository(Tree $tree): NameRepository
    {
        return new NameRepository($tree, $this->statisticsData($tree));
    }

    /**
     * Every individual carries the surname "Test" so the distinct-
     * surname count is 1. Tests the "headline number stays in
     * lockstep with the Top-N aggregation" promise.
     */
    #[Test]
    public function countDistinctSurnamesIs1ForFixture(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = $this->repository($tree)->countDistinctSurnames();

        self::assertSame(1, $result);
    }

    /**
     * Five distinct given names appear in the fixture (Anna,
     * Friedrich, Maria, Hans, Lisa), split across sexes. Female:
     * Anna×3, Maria×2, Lisa×1 → 3 distinct. Male: Friedrich×2,
     * Hans×3 → 2 distinct. The 12th individual is undated but
     * still has a given name so still contributes.
     */
    #[Test]
    public function countDistinctGivenNamesPerSex(): void
    {
        $tree = $this->importFixtureTree('name-trends.ged');
        $repo = $this->repository($tree);

        self::assertGreaterThanOrEqual(3, $repo->countDistinctGivenNames('F'));
        self::assertGreaterThanOrEqual(2, $repo->countDistinctGivenNames('M'));
    }

    /**
     * The threshold filter excludes given names that occur fewer
     * times than the threshold. Asking for names that appear at
     * least 3 times across the fixture should drop the count.
     */
    #[Test]
    public function countDistinctGivenNamesRespectsThreshold(): void
    {
        $tree = $this->importFixtureTree('name-trends.ged');
        $repo = $this->repository($tree);

        // With threshold 1 we see all five given names; with
        // threshold 3 only Anna (3) and Hans (3) qualify.
        $unbounded = $repo->countDistinctGivenNames('ALL', 1);
        $threeOnly = $repo->countDistinctGivenNames('ALL', 3);

        self::assertGreaterThan($threeOnly, $unbounded);
    }
}
