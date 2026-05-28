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

    /**
     * Top-N ancestors podium: the grandparent (I1) carries two
     * transitive descendants (the parent I2 plus the child I3),
     * the parent itself one. The child I3 sits at zero descendants
     * and the isolated individual I4 has no parentage links at
     * all — both are excluded because zero-descendant entries do
     * not belong on a "structural roots" podium. So the ranking
     * is exactly two rows: Grandparent first with 2, Parent
     * second with 1, in descending order of descendant count.
     */
    #[Test]
    public function topAncestorsByDescendantCountRanksGrandparentFirst(): void
    {
        $tree   = $this->importFixtureTree('generation-depth.ged');
        $result = (new GenerationDepthRepository($tree, new ParentMapRepository($tree)))
            ->topAncestorsByDescendantCount(10);

        self::assertCount(2, $result, 'Only the two individuals with descendants land on the podium');

        // arsort preserves keys; convert to ordered pairs for assertion.
        $ordered = [];

        foreach ($result as $label => $count) {
            $ordered[] = [$label, $count];
        }

        self::assertSame(2, $ordered[0][1], 'Grandparent leads with two descendants (parent + grandchild)');
        self::assertStringContainsString('Grandparent', $ordered[0][0]);

        self::assertSame(1, $ordered[1][1], 'Parent follows with one descendant (the child)');
        self::assertStringContainsString('Parent', $ordered[1][0]);
    }

    /**
     * Edge: when the requested top-N exceeds the number of
     * individuals with at least one descendant, the result simply
     * stops at the available rows — no zero-padding, no overflow.
     * Locks the implicit "no leaves on the podium" contract.
     */
    #[Test]
    public function topAncestorsLimitClampsToAvailableRanks(): void
    {
        $tree   = $this->importFixtureTree('generation-depth.ged');
        $result = (new GenerationDepthRepository($tree, new ParentMapRepository($tree)))
            ->topAncestorsByDescendantCount(50);

        self::assertCount(2, $result, 'Asking for 50 rows on a 2-row tree still yields 2 rows');
    }
}
