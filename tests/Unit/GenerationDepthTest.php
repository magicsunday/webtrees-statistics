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
     * of depth 0, 1, 2 below their deepest descendant. The deepestChain field
     * surfaces the three IDs in eldest-first order so the view can render "G1 →
     * V → C".
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
     * When two distinct lines tie at the maximum depth, the chain picker must
     * produce a deterministic answer — alphabetically earliest root,
     * alphabetically earliest descendant at each step — so the rendered chain
     * does not flip between page loads.
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
     * An empty tree produces an empty chain (there is no longest descent to
     * name).
     */
    #[Test]
    public function emptyTreeYieldsEmptyDeepestChain(): void
    {
        $result = GenerationDepth::compute([]);

        self::assertSame([], $result['deepestChain']);
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

        self::assertSame(3, $result['maxDepth']);
        self::assertSame(['G', 'Q', 'P2', 'C1'], $result['deepestChain']);
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
        // Chain reconstruction still works under clamping: the chosen root is
        // the boundary node (the shallowest one at MAX_DEPTH, I100), whose
        // descent decrements cleanly to the leaf — so the chain rebuilds to
        // MAX_DEPTH + 1 nodes rather than truncating at the clamp.
        self::assertCount(GenerationDepth::MAX_DEPTH + 1, $result['deepestChain']);
        self::assertSame('I100', $result['deepestChain'][0]);
        self::assertSame('I0', $result['deepestChain'][GenerationDepth::MAX_DEPTH]);
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
     * Numeric-only XREFs (e.g. "54", common in trees imported from software
     * that uses digit-only pointers) become integer array keys the moment they
     * index a PHP array — `$parentOf["54"]` stores key int 54. `array_keys()`
     * and `foreach`-over-keys then yield ints, which crash the `string`-typed
     * walk under strict_types. This variant of {@see
     * threeGenerationLinearChainMatchesAcceptance} guards that the whole
     * compute path tolerates the coercion and still emits a string-typed
     * eldest-first chain (regression for #71).
     */
    #[Test]
    public function numericOnlyXrefsLinearChainReturnsStringChain(): void
    {
        // Keys are written as numeric strings but PHP coerces them to
        // int array keys: 3 (grandparent) → 12 (parent) → 54 (child).
        $parentOf = [
            '54' => ['12', null],
            '12' => ['3', null],
        ];

        $result = GenerationDepth::compute($parentOf);

        self::assertSame(2, $result['maxDepth']);
        self::assertSame(
            [0 => 1, 1 => 1, 2 => 1],
            $result['distribution'],
        );
        self::assertFalse($result['capped']);
        // assertSame is strict: a coerced [3, 12, 54] of ints would
        // not match the expected string chain, so this pins both the
        // values and their string type.
        self::assertSame(['3', '12', '54'], $result['deepestChain']);
    }

    /**
     * Numeric-XREF variant of {@see
     * deepestChainIsDeterministicOnTiesAlphabetical} with roots whose lexical
     * and numeric orderings disagree: candidate roots 2 and 10 ("10" sorts
     * before "2" lexically, but 2 before 10 numerically). This pins both that
     * the tie-break runs on the coerced keys at all and that the picked chain
     * stays string-typed (regression for #71). The chain "2 → 200" — not "10 →
     * 100" — confirms PHP's default numeric-string sort decided the tie.
     */
    #[Test]
    public function numericOnlyXrefsDeepestChainIsDeterministic(): void
    {
        $parentOf = [
            '200' => ['2', null],
            '100' => ['10', null],
        ];

        $result = GenerationDepth::compute($parentOf);

        self::assertSame(1, $result['maxDepth']);
        self::assertSame(['2', '200'], $result['deepestChain']);
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
     * Leading-zero XREFs ("007") are NOT canonical decimal-integer strings, so
     * PHP does not coerce them to int when they index an array — they survive
     * as string keys. This guards the boundary the AGENTS.md "Key patterns"
     * note describes: the chain must carry "007" verbatim, not a coerced "7",
     * proving the fix neither over- nor under-normalises (regression for #71).
     */
    #[Test]
    public function leadingZeroXrefsSurviveAsStringKeys(): void
    {
        $parentOf = [
            '007' => ['1', null],
        ];

        $result = GenerationDepth::compute($parentOf);

        self::assertSame(1, $result['maxDepth']);
        self::assertSame(['1', '007'], $result['deepestChain']);
    }

    /**
     * A descent line longer than {@see GenerationDepth::MAX_DEPTH} saturates the
     * depth of every node in the over-cap region to `MAX_DEPTH`: the eldest
     * ancestor and its first ~50 children all cache the same clamped value. The
     * lexically-first max-depth candidate is then a *deep* clamped node (its own
     * child is also clamped, not decremented by one), which the exact-decrement
     * step could not step past — it truncated the chain to the lone root. The
     * reconstruction must instead follow the deepest child at each hop and return
     * a full `MAX_DEPTH + 1`-node chain down the longest line (issue #164).
     */
    #[Test]
    public function deepestChainReconstructsFullyForDescentLongerThanMaxDepth(): void
    {
        // One straight 150-generation chain I000 → I001 → … → I150. Fixed-width
        // zero-padded XREFs keep the lexical sort numeric, so candidates[0] is
        // the eldest ancestor I000 — a deep clamped node whose only child I001
        // is itself clamped to MAX_DEPTH: the exact case that truncated.
        $generations = GenerationDepth::MAX_DEPTH + 50;
        $parentOf    = [];

        for ($i = 1; $i <= $generations; ++$i) {
            $parentOf[sprintf('I%03d', $i)] = [sprintf('I%03d', $i - 1), null];
        }

        $result = GenerationDepth::compute($parentOf);

        self::assertSame(GenerationDepth::MAX_DEPTH, $result['maxDepth']);
        self::assertTrue($result['capped']);

        // The chain holds MAX_DEPTH + 1 individuals — not a lone root — running
        // contiguously down the deepest line from the eldest ancestor I000.
        $expectedChain = [];

        for ($i = 0; $i <= GenerationDepth::MAX_DEPTH; ++$i) {
            $expectedChain[] = sprintf('I%03d', $i);
        }

        self::assertSame($expectedChain, $result['deepestChain']);
    }

    /**
     * When an over-cap descent BRANCHES, every node in the clamped region of
     * both branches caches the identical `MAX_DEPTH`, so the walk can no longer
     * tell the branches apart by depth. It must still pick deterministically —
     * the lexically-first child at each saturated fork — and return a
     * contiguous `MAX_DEPTH + 1`-node chain rather than truncating. This locks
     * the tie-break under depth saturation, the adversarial-naming case behind
     * issue #164 (the old exact-decrement walk dead-ended at the root here too).
     */
    #[Test]
    public function deepestChainPicksTheLexicallyFirstBranchUnderDepthSaturation(): void
    {
        // Two parallel 150-generation lines sharing the eldest ancestor I000:
        // I000 → I001 → … → I150 and I000 → J001 → … → J150. At I000 both
        // children (I001, J001) are clamped to MAX_DEPTH, so the walk must
        // resolve the tie to the lexically-smaller "I001" branch.
        $generations = GenerationDepth::MAX_DEPTH + 50;
        $parentOf    = [];

        for ($i = 1; $i <= $generations; ++$i) {
            $parentOf[sprintf('I%03d', $i)] = [sprintf('I%03d', $i - 1), null];
            $parentOf[sprintf('J%03d', $i)] = [$i === 1 ? 'I000' : sprintf('J%03d', $i - 1), null];
        }

        $result = GenerationDepth::compute($parentOf);

        self::assertSame(GenerationDepth::MAX_DEPTH, $result['maxDepth']);
        self::assertTrue($result['capped']);

        $chain = $result['deepestChain'];

        // Full-length, contiguous, and committed to the "I" branch from the
        // first saturated fork onward (never a stray "J" node).
        self::assertCount(GenerationDepth::MAX_DEPTH + 1, $chain);
        self::assertSame('I000', $chain[0]);
        self::assertSame('I001', $chain[1], 'The tie at the saturated root must resolve to the lexically-first child');

        $expectedChain = [];

        for ($i = 0; $i <= GenerationDepth::MAX_DEPTH; ++$i) {
            $expectedChain[] = sprintf('I%03d', $i);
        }

        self::assertSame($expectedChain, $chain);
    }
}
