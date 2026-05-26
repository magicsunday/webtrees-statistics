<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit;

use MagicSunday\Webtrees\Statistic\Support\Calc\GenerationDepth;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage of the pure {@see GenerationDepth::compute()}
 * helper — exercises the empty case, a clean 3-generation linear
 * chain (issue acceptance scenario), branching where two parents
 * meet again further up (pedigree collapse must not double-count
 * the shared ancestor's depth), and the cycle guard that protects
 * against accidentally-cyclic GEDCOM input.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class GenerationDepthTest extends TestCase
{
    /**
     * An empty parent-of map describes a tree with no recorded
     * parent–child links: max depth is zero, the distribution is
     * empty, and the cap is not tripped.
     */
    #[Test]
    public function emptyParentMapReturnsZeroDepth(): void
    {
        $result = GenerationDepth::compute([]);

        self::assertSame(0, $result['maxDepth']);
        self::assertSame([0 => 0], $result['distribution']);
        self::assertFalse($result['capped']);
    }

    /**
     * The issue acceptance scenario: a clean G1 → V → C chain
     * (grandparent → parent → child). Max depth is 2, and exactly
     * one individual sits at each of depth 0, 1, 2 below their
     * deepest descendant. The deepestChain field surfaces the
     * three IDs in eldest-first order so the view can render
     * "G1 → V → C".
     */
    #[Test]
    public function threeGenerationLinearChainMatchesAcceptance(): void
    {
        $parentOf = [
            'C' => ['V', null],
            'V' => ['G1', null],
        ];

        $result = GenerationDepth::compute($parentOf);

        self::assertSame(2, $result['maxDepth']);
        self::assertSame(
            [0 => 1, 1 => 1, 2 => 1],
            $result['distribution'],
        );
        self::assertFalse($result['capped']);
        self::assertSame(['G1', 'V', 'C'], $result['deepestChain']);
    }

    /**
     * When two distinct lines tie at the maximum depth, the chain
     * picker must produce a deterministic answer — alphabetically
     * earliest root, alphabetically earliest descendant at each
     * step — so the rendered chain does not flip between page loads.
     */
    #[Test]
    public function deepestChainIsDeterministicOnTiesAlphabetical(): void
    {
        // Two parallel two-generation chains: A → X and B → Y.
        // Both A and B have depth 1; the picker must choose A.
        $parentOf = [
            'X' => ['A', null],
            'Y' => ['B', null],
        ];

        $result = GenerationDepth::compute($parentOf);

        self::assertSame(1, $result['maxDepth']);
        self::assertSame(['A', 'X'], $result['deepestChain']);
    }

    /**
     * An empty tree produces an empty chain (there is no longest
     * descent to name).
     */
    #[Test]
    public function emptyTreeYieldsEmptyDeepestChain(): void
    {
        $result = GenerationDepth::compute([]);

        self::assertSame([], $result['deepestChain']);
    }

    /**
     * When two distinct child lines converge into the same deeper
     * ancestor (pedigree collapse via cousin marriage), the
     * ancestor's depth is computed from the LONGEST downward path
     * — not the sum of both. Verifies the visited-set within a
     * single DFS prevents a re-entry from doubling the depth.
     */
    #[Test]
    public function pedigreeCollapseChoosesLongestDownwardPath(): void
    {
        // Tree: G is the common grandparent of both P1 and P2.
        // C1 has P1 as father and P2 as mother (cousin marriage).
        // Both lines climb back through G via different bridges.
        $parentOf = [
            'C1' => ['P1', 'P2'],
            'P1' => ['G', null],
            'P2' => ['G', null],
        ];

        $result = GenerationDepth::compute($parentOf);

        self::assertSame(2, $result['maxDepth']);
        // P1 and P2 both have depth 1 (each reaches C1 one step down).
        // G has depth 2 (longest downward through either branch).
        // C1 has depth 0 (no descendants).
        self::assertSame(
            [0 => 1, 1 => 2, 2 => 1],
            $result['distribution'],
        );
    }

    /**
     * A cycle (illegal but possible: someone self-edits the parent
     * map so an ancestor points back to a descendant) must not
     * loop forever. The visited-set on a per-walk basis breaks the
     * cycle; depth is bounded but the `capped` flag may or may
     * not trip depending on chain length.
     */
    #[Test]
    public function cyclicParentMapDoesNotLoopForever(): void
    {
        $parentOf = [
            'A' => ['B', null],
            'B' => ['C', null],
            'C' => ['A', null], // cycle: A → B → C → A
        ];

        // We're not asserting an exact depth — the cycle's
        // implementation-defined break point is implementation-detail.
        // The contract is: it terminates and returns a sane shape.
        $result = GenerationDepth::compute($parentOf);

        self::assertGreaterThanOrEqual(0, $result['maxDepth']);
        self::assertLessThanOrEqual(GenerationDepth::MAX_DEPTH, $result['maxDepth']);
        self::assertNotSame([], $result['distribution']);
    }
}
