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
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

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
#[CoversClass(KinshipRepository::class)]
#[UsesClass(ParentMapRepository::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class KinshipRepositoryIntegrationTest extends IntegrationTestCase
{
    private function repository(Tree $tree): KinshipRepository
    {
        return new KinshipRepository($tree, new ParentMapRepository($tree));
    }

    /**
     * Ancestor-count distribution puts the four bottom-of-the-tree individuals
     * (G1, G2, M, Solo) plus Vater (2 known) into the 0-2 bucket — five
     * individuals with 0–2 ancestors — and Kind (4 known) into the 3-5 bucket.
     */
    #[Test]
    public function ancestorCountDistributionBucketsByKnownAncestors(): void
    {
        $tree   = $this->importFixtureTree('kinship.ged');
        $result = $this->repository($tree)->ancestorCountDistribution();

        // Six individuals total.
        self::assertSame(6, array_sum($result));
        // G1, G2, M, S, V (V has 2 ancestors so still bucket 0-2)
        self::assertSame(5, $result['0–2'] ?? null);
        // Kind has 4 known ancestors → bucket 3-5
        self::assertSame(1, $result['3–5'] ?? null);
    }

    /**
     * Average pedigree completeness across the six fixture individuals. Vater
     * contributes 2/2 at gen 1 + 0 elsewhere = 1/4 = 0.25. Kind contributes 2/2
     * at gen 1 + 2/4 at gen 2 + 0 elsewhere = 0.25 + 0.125 = 0.375. The other
     * four have 0.0. Average = (0.25 + 0.375 + 0 + 0 + 0 + 0) / 6 ≈ 0.1042.
     */
    #[Test]
    public function averagePedigreeCompletenessAggregatesAcrossPopulation(): void
    {
        $tree   = $this->importFixtureTree('kinship.ged');
        $result = $this->repository($tree)->averagePedigreeCompleteness();

        self::assertGreaterThan(0.0, $result);
        self::assertLessThan(1.0, $result);
        // Sanity range: between (0.25+0.375)/6 ~ 0.10 and 0.25 (if all
        // individuals had Kind's coverage).
        self::assertGreaterThan(0.09, $result);
        self::assertLessThan(0.12, $result);
    }
}
