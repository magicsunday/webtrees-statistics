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
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;

/**
 * Unit coverage of the pure {@see GenerationDepth::compute()} helper —
 * exercises the empty case, a clean 3-generation linear chain (issue acceptance
 * scenario), branching where two parents meet again further up (pedigree
 * collapse must not double-count the shared ancestor's depth), and the cycle
 * guard that protects against accidentally-cyclic GEDCOM input.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(GenerationDepth::class)]
final class GenerationDepthTest extends TestCase
{
    /**
     * An empty parent-of map describes a tree with no recorded parent–child
     * links: max depth is zero, the distribution is empty, and the cap is not
     * tripped.
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
     * The issue acceptance scenario: a clean G1 → V → C chain (grandparent →
     * parent → child). Max depth is 2, and exactly one individual sits at each
     * of depth 0, 1, 2 below their deepest descendant.
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
    }

    /**
     * When two distinct child lines converge into the same deeper ancestor
     * (pedigree collapse via cousin marriage), the ancestor's depth is computed
     * from the LONGEST downward path — not the sum of both. Verifies the
     * longest-path memoisation (`depth = 1 + max(child)`) takes the deeper
     * branch rather than adding the two converging lines.
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
     * Pedigree collapse where the two reconverging lines have DIFFERENT
     * lengths: the deeper individual's depth must be the LONGER path, and the
     * answer must not depend on which path the walk happens to explore first.
     * {@see pedigreeCollapseChoosesLongestDownwardPath} only uses equal-length
     * lines, so it never actually exercises "longest wins over a shorter
     * alternative" (regression for #161).
     */
    #[Test]
    public function downwardWalkTakesTheLongerOfTwoUnequalLengthPaths(): void
    {
        // C1 is reachable downward from G via two paths of different length:
        //   G -> P1 -> C1         (2)
        //   G -> Q  -> P2 -> C1   (3)
        // The entry order below makes the SHORTER path explored first, which
        // a visited-set DFS under-counts to maxDepth 2.
        $parentOf = [
            'C1' => ['P1', 'P2'],
            'Q'  => ['G', null],
            'P2' => ['Q', null],
            'P1' => ['G', null],
        ];

        $result = GenerationDepth::compute($parentOf);

        // maxDepth 3 (not 2) proves the longest of the two reconverging lines
        // wins regardless of traversal order.
        self::assertSame(3, $result['maxDepth']);
    }

    /**
     * A legitimate but implausibly long ACYCLIC descent (deeper than MAX_DEPTH)
     * saturates at the cap and trips the `capped` data-quality flag. Pins the
     * per-node `min($depth, MAX_DEPTH)` clamp the longest-path rewrite introduced
     * (regression for #161).
     */
    #[Test]
    public function chainDeeperThanMaxDepthClampsAndTripsCappedFlag(): void
    {
        // A linear chain of 150 generations: I0 (child) up to I150 (eldest).
        $parentOf = [];

        for ($i = 0; $i < 150; ++$i) {
            $parentOf['I' . $i] = ['I' . ($i + 1), null];
        }

        $result = GenerationDepth::compute($parentOf);

        self::assertSame(GenerationDepth::MAX_DEPTH, $result['maxDepth'], 'Depth saturates at MAX_DEPTH');
        self::assertTrue($result['capped'], 'A >MAX_DEPTH acyclic chain trips the capped signal');
    }

    /**
     * A cycle (illegal but possible: someone self-edits the parent map so an
     * ancestor points back to a descendant) must not loop forever. The per-walk
     * on-path back-edge guard absorbs the cycle to a small finite depth, so it
     * never trips `capped` (which signals only genuinely long acyclic descent).
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
        // A cycle is absorbed by the on-path back-edge guard to a small finite
        // depth — it must NOT saturate the cap, so `capped` stays false (the
        // flag signals only genuinely long acyclic descent, not corrupt data).
        self::assertFalse($result['capped']);
    }

    /**
     * Upward-walk tie-break with numeric-only XREFs whose lexical and numeric
     * orderings disagree: a leaf with two equally-distant parents 2 and 10 ("10"
     * sorts before "2" lexically, but 2 before 10 numerically). The reconstructed
     * chain "2 → 200" — not "10 → 200" — confirms the tie ran through PHP's
     * default numeric-string `sort()` on the coerced keys and that the chain
     * stays string-typed (regression for #71).
     */
    #[Test]
    public function numericOnlyXrefsUpwardWalkTieBreakUsesNumericSort(): void
    {
        // Leaf 200 has two root parents 2 and 10, both at upward distance 0, so
        // the parent step is a pure tie the numeric sort must resolve to 2.
        $parentOf = [
            '200' => ['2', '10'],
        ];

        $upDistance = GenerationDepth::upDistanceCache($parentOf);

        self::assertSame(1, $upDistance['200'] ?? null);

        $chain = GenerationDepth::walkUpFromLeaf($parentOf, $upDistance, '200', 1);

        // assertSame is strict: a coerced [2, 200] of ints would not match, so
        // this pins both the numeric tie-break and the string type.
        self::assertSame(['2', '200'], $chain);
    }

    /**
     * Numeric-XREF variant of {@see
     * pedigreeCollapseChoosesLongestDownwardPath}: 100 has parents 10 and 20,
     * both children of 1. Confirms the children-of inversion and depth
     * memoisation survive integer key coercion (regression for #71).
     */
    #[Test]
    public function numericOnlyXrefsPedigreeCollapseComputesDepth(): void
    {
        $parentOf = [
            '100' => ['10', '20'],
            '10'  => ['1', null],
            '20'  => ['1', null],
        ];

        $result = GenerationDepth::compute($parentOf);

        self::assertSame(2, $result['maxDepth']);
        self::assertSame(
            [0 => 1, 1 => 2, 2 => 1],
            $result['distribution'],
        );
    }

    /**
     * The upward-walk pair {@see GenerationDepth::upDistanceCache()} and {@see
     * GenerationDepth::walkUpFromLeaf()} mirror the descendant walk on the
     * parent side and are otherwise only reached through the repository. This
     * locks them directly against numeric-only XREFs: the cache must record the
     * leaf's upward distance and the reconstructed chain must come back
     * eldest-first and string-typed (regression for #71).
     */
    #[Test]
    public function numericOnlyXrefsUpwardWalkReturnsStringChain(): void
    {
        $parentOf = [
            '54' => ['12', null],
            '12' => ['3', null],
        ];

        $upDistance = GenerationDepth::upDistanceCache($parentOf);

        // Leaf 54 sits two generations below the eldest ancestor 3.
        self::assertSame(2, $upDistance['54'] ?? null);

        $chain = GenerationDepth::walkUpFromLeaf($parentOf, $upDistance, '54', 2);

        // Strict equality pins the eldest-first order and the string
        // type of every entry in one assertion.
        self::assertSame(['3', '12', '54'], $chain);
    }

    /**
     * The upward walk must follow BOTH parents of a node and record the deeper
     * of the two ancestor lines, and a null father must not abort the maternal
     * line. The earlier upward tests only exercise single-parent linear chains,
     * which cannot tell a both-parents walk (deeper wins) from a father-only
     * one, nor prove the null-slot is skipped rather than terminating the walk.
     */
    #[Test]
    public function upwardWalkFollowsBothParentsTakingTheDeeperLineAndSurvivesANullFather(): void
    {
        $parentOf = [
            // C's maternal line (C→M→GM1→GM2 = 3) is deeper than its paternal
            // line (C→F→GF = 2); the cache must record 3, proving both parents
            // are walked and the deeper line wins.
            'C'   => ['F', 'M'],
            'F'   => ['GF', null],
            'M'   => ['GM1', null],
            'GM1' => ['GM2', null],
            // X has no father but a mother; the null father must not stop the
            // maternal walk (X→Y→Z = 2).
            'X' => [null, 'Y'],
            'Y' => ['Z', null],
        ];

        $upDistance = GenerationDepth::upDistanceCache($parentOf);

        self::assertSame(3, $upDistance['C'] ?? null, 'Both parents must be walked and the deeper line wins');
        self::assertSame(2, $upDistance['X'] ?? null, 'A null father must not stop the maternal line');
    }

    /**
     * Ancestor-side counterpart of {@see downwardWalkTakesTheLongerOfTwoUnequalLengthPaths}:
     * a node reachable UPWARD via two lines of unequal length, arranged so the
     * SHORTER line is explored first, must still record the longer distance —
     * the upward walk shares the same longest-path memoisation (regression for
     * #161).
     */
    #[Test]
    public function upwardWalkTakesTheLongerOfTwoUnequalLengthPaths(): void
    {
        // L reaches the eldest ancestor A via two upward lines:
        //   L -> F -> N -> A   (3, paternal — explored second)
        //   L -> M -> A        (2, maternal — explored first)
        $parentOf = [
            'L' => ['F', 'M'],
            'F' => ['N', null],
            'N' => ['A', null],
            'M' => ['A', null],
        ];

        $upDistance = GenerationDepth::upDistanceCache($parentOf);

        self::assertSame(3, $upDistance['L'] ?? null);
    }

    /**
     * An ancestry line longer than {@see GenerationDepth::MAX_DEPTH} saturates
     * the upward distance of every node in the over-cap region to `MAX_DEPTH`:
     * the genuine leaf and its first ~50 ancestors all cache the same clamped
     * value. The upward walk must follow the DEEPEST parent at each hop (largest
     * recorded `upDistance`, `sort()` tie-break) so it stays on the longest line
     * and rebuilds a full `MAX_DEPTH + 1`-node chain ending at the genuine leaf,
     * rather than dead-ending the moment the exact `remaining - 1` decrement
     * fails inside the clamped region (issue #167).
     */
    #[Test]
    public function walkUpFromLeafReconstructsFullyForAncestryLongerThanMaxDepth(): void
    {
        // One straight 150-generation line I000 → I001 → … → I150 with I150 the
        // sole genuine leaf. Its upward distance clamps to MAX_DEPTH, and every
        // ancestor up to I050 is clamped too — the exact case that dead-ended.
        $generations = GenerationDepth::MAX_DEPTH + 50;
        $parentOf    = [];

        for ($i = 1; $i <= $generations; ++$i) {
            $parentOf[sprintf('I%03d', $i)] = [sprintf('I%03d', $i - 1), null];
        }

        $upDistance = GenerationDepth::upDistanceCache($parentOf);
        $leaf       = sprintf('I%03d', $generations);

        self::assertSame(GenerationDepth::MAX_DEPTH, $upDistance[$leaf] ?? null);

        $chain = GenerationDepth::walkUpFromLeaf($parentOf, $upDistance, $leaf, GenerationDepth::MAX_DEPTH);

        // A full MAX_DEPTH + 1 chain, eldest-first, ending at the genuine leaf.
        self::assertNotNull($chain);
        self::assertCount(GenerationDepth::MAX_DEPTH + 1, $chain);
        self::assertSame($leaf, $chain[GenerationDepth::MAX_DEPTH]);

        $expectedChain = [];

        for ($i = $generations - GenerationDepth::MAX_DEPTH; $i <= $generations; ++$i) {
            $expectedChain[] = sprintf('I%03d', $i);
        }

        self::assertSame($expectedChain, $chain);
    }

    /**
     * When an over-cap ancestry BRANCHES, the clamped parents of both lines all
     * cache the identical `MAX_DEPTH`, so the upward walk can no longer tell them
     * apart by distance. It must still pick deterministically — the
     * lexically-first parent at each saturated fork — and return a contiguous
     * chain rather than dead-ending (issue #167).
     */
    #[Test]
    public function walkUpFromLeafPicksTheLexicallyFirstParentUnderDistanceSaturation(): void
    {
        // The genuine leaf L sits below two clamped ancestral lines that merge
        // at it: an "I" line and a "J" line, both longer than MAX_DEPTH. At the
        // first fork above L both parents are clamped, so the tie must resolve
        // to the lexically-smaller "I" parent.
        $generations = GenerationDepth::MAX_DEPTH + 50;
        $parentOf    = ['L' => [sprintf('I%03d', $generations), sprintf('J%03d', $generations)]];

        for ($i = 1; $i <= $generations; ++$i) {
            $parentOf[sprintf('I%03d', $i)] = [sprintf('I%03d', $i - 1), null];
            $parentOf[sprintf('J%03d', $i)] = [sprintf('J%03d', $i - 1), null];
        }

        $upDistance = GenerationDepth::upDistanceCache($parentOf);
        $chain      = GenerationDepth::walkUpFromLeaf($parentOf, $upDistance, 'L', GenerationDepth::MAX_DEPTH);

        self::assertNotNull($chain);
        self::assertCount(GenerationDepth::MAX_DEPTH + 1, $chain);
        // The leaf ends the chain; the hop directly above it commits to the "I"
        // line (lexically before "J") and never strays onto a "J" node.
        self::assertSame('L', $chain[GenerationDepth::MAX_DEPTH]);
        self::assertSame(sprintf('I%03d', $generations), $chain[GenerationDepth::MAX_DEPTH - 1]);

        foreach ($chain as $id) {
            self::assertStringStartsNotWith('J', $id);
        }
    }

    /**
     * Leading-zero XREFs ("007") are NOT canonical decimal-integer strings, so
     * PHP does not coerce them to int when they index an array — they survive
     * as string keys. This guards the boundary the AGENTS.md "Key patterns"
     * note describes: the reconstructed chain must carry "007" verbatim, not a
     * coerced "7", proving the fix neither over- nor under-normalises
     * (regression for #71).
     */
    #[Test]
    public function leadingZeroXrefsSurviveAsStringKeys(): void
    {
        $parentOf = [
            '007' => ['1', null],
        ];

        $upDistance = GenerationDepth::upDistanceCache($parentOf);

        self::assertSame(1, $upDistance['007'] ?? null);

        $chain = GenerationDepth::walkUpFromLeaf($parentOf, $upDistance, '007', 1);

        self::assertSame(['1', '007'], $chain);
    }
}
