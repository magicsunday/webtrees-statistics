<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\GenerationDepthRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end test of {@see GenerationDepthRepository} against
 * `generation-depth.ged`:
 *
 *   I1 (grandparent) → I2 (parent) → I3 (child)
 *   I4 — isolated individual, not in any FAMC/FAMS relationship
 *
 * Expected:
 *   - maxDepth: 2
 *   - distribution: {0: 1 (I3), 1: 1 (I2), 2: 1 (I1)}
 *   - I4 contributes nothing (no parentage links)
 *   - capped: false
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class GenerationDepthRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * The summary captures the longest descent and the distribution
     * of individuals across generation distances. The isolated
     * individual is correctly excluded because they have no entry
     * in the parent-of map (no FAMC link).
     */
    #[Test]
    public function summaryMatchesAcceptanceFixture(): void
    {
        $tree   = $this->importFixtureTree('generation-depth.ged');
        $result = (new GenerationDepthRepository($tree, new ParentMapRepository($tree)))->summary();

        self::assertSame(2, $result->maxDepth);
        self::assertFalse($result->capped);
        self::assertSame(
            [0 => 1, 1 => 1, 2 => 1],
            $result->distribution,
        );
    }
}
