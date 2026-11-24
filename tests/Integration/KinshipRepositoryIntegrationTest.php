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
use MagicSunday\Webtrees\Statistic\Repository\KinshipRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_sum;

/**
 * Integration test for {@see KinshipRepository}. Fixture has 6 individuals
 * across 3 generations:
 *
 *   Grossvater @G1@ + Grossmutter @G2@ — generation 0 (no parents)
 *   Vater @V@      — son of G1+G2 — generation 1 (2 known ancestors)
 *   Mutter @M@     — generation 0 (no parents)
 *   Kind @C@       — son of V+M  — generation 2 (V, M, G1, G2 = 4)
 *   Solo @S@       — generation 0 (no parents at all)
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class KinshipRepositoryIntegrationTest extends AbstractIntegrationTestCase
{
    private function repository(Tree $tree): KinshipRepository
    {
        return new KinshipRepository($tree, new ParentMapRepository($tree));
    }

    /**
     * Ancestor-count distribution gives each of 0, 1 and 2 known ancestors its
     * own bar, so the many individuals without a recorded parent do not hide
     * the rest. The four bottom-of-the-tree individuals (G1, G2, M, Solo) have
     * none, Vater has two, and Kind (4 known) lands in the 3-5 bucket.
     */
    #[Test]
    public function ancestorCountDistributionBucketsByKnownAncestors(): void
    {
        $tree   = $this->importFixtureTree('kinship.ged');
        $result = $this->repository($tree)->ancestorCountDistribution();

        // Six individuals total.
        self::assertSame(6, array_sum($result));
        // G1, G2, M and S have no known ancestor, V has two, nobody has one.
        self::assertSame(4, $result[0] ?? null);
        self::assertSame(0, $result[1] ?? null);
        self::assertSame(1, $result[2] ?? null);
        self::assertArrayNotHasKey('0–2', $result);
        // Kind has 4 known ancestors → bucket 3-5
        self::assertSame(1, $result['3–5'] ?? null);
    }

    /**
     * Carl has three known ancestors (Franz, Maria and Gustav), which is the
     * first count of the grouped buckets, Franz has exactly one known ancestor
     * (Gustav) and Maria and Gustav have none.
     */
    #[Test]
    public function ancestorCountDistributionPlacesOneAndThreeKnownAncestors(): void
    {
        $tree   = $this->importFixtureTree('kinship-one-and-three.ged');
        $result = $this->repository($tree)->ancestorCountDistribution();

        self::assertSame(4, array_sum($result));
        self::assertSame(2, $result[0] ?? null);
        self::assertSame(1, $result[1] ?? null);
        self::assertSame(0, $result[2] ?? null);
        self::assertSame(1, $result['3–5'] ?? null);
    }

    /**
     * Average pedigree completeness across the fixture individuals. Vater
     * contributes 2/2 at gen 1 + 0 elsewhere = 1/4 = 0.25. Kind contributes 2/2
     * at gen 1 + 2/4 at gen 2 + 0 elsewhere = 0.25 + 0.125 = 0.375. The other
     * individuals have 0.0, so the average is (0.25 + 0.375) / 6, which is
     * about 0.1042 after rounding.
     */
    #[Test]
    public function averagePedigreeCompletenessAggregatesAcrossPopulation(): void
    {
        $tree   = $this->importFixtureTree('kinship.ged');
        $result = $this->repository($tree)->averagePedigreeCompleteness();

        // (0.25 + 0.375) / 6 = 0.104166..., rounded the way the source rounds it.
        self::assertSame(0.1042, $result);
    }
}
