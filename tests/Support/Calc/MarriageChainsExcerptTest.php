<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Calc;

use MagicSunday\Webtrees\Statistic\Support\Calc\MarriageChains;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill_keys;
use function array_map;
use function array_reverse;
use function count;
use function in_array;
use function sprintf;

/**
 * Unit coverage of {@see MarriageChains::excerpt()} — the pure cap/excerpt graph
 * logic that the {@see \MagicSunday\Webtrees\Statistic\Repository\MarriageReachRepository}
 * delegates to. The repository's integration fixture has only 41 connected
 * people (< the 70 cap), so the no-op below-cap branch alone is exercised there;
 * the failure-prone over-cap path — the BFS frontier, the cap cut, the
 * always-include of the chain seeds and the edge restriction to shown nodes —
 * is pinned here against a SYNTHETIC connected graph of more than seventy people
 * built programmatically, so the cap behaviour ships with real coverage.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(MarriageChains::class)]
final class MarriageChainsExcerptTest extends TestCase
{
    /**
     * The excerpt cap used throughout this suite — the same value the repository
     * pins as {@see \MagicSunday\Webtrees\Statistic\Repository\MarriageReachRepository::NETWORK_CAP},
     * passed explicitly so the pure helper stays decoupled from the repository.
     */
    private const int CAP = 70;

    /**
     * Build a connected marriage graph of `$spineLength` spine people joined in a
     * line (I001–I002–…), each spine person additionally carrying one pendant
     * leaf (L001, L002, …). With a long-enough spine the component runs well over
     * the cap, while the leaves give the BFS a deterministic frontier to cut.
     *
     * The xrefs are zero-padded so byte order equals numeric order, which makes
     * the smallest-xref BFS frontier hand-predictable.
     *
     * @param int $spineLength The number of people on the central spine
     *
     * @return array<array-key, list<string>>
     */
    private function spineWithLeaves(int $spineLength): array
    {
        $adjacency = [];

        for ($index = 1; $index <= $spineLength; ++$index) {
            $spine = sprintf('I%03d', $index);
            $leaf  = sprintf('L%03d', $index);

            $neighbours = [$leaf];

            if ($index > 1) {
                $neighbours[] = sprintf('I%03d', $index - 1);
            }

            if ($index < $spineLength) {
                $neighbours[] = sprintf('I%03d', $index + 1);
            }

            $adjacency[$spine] = $neighbours;
            $adjacency[$leaf]  = [$spine];
        }

        return $adjacency;
    }

    /**
     * Every key of the adjacency map, the full connected component, byte-order
     * sorted — the real `$members` set the repository would hand the excerpt.
     *
     * @param array<array-key, list<string>> $adjacency The marriage graph
     *
     * @return list<string>
     */
    private function members(array $adjacency): array
    {
        $members = array_map(strval(...), array_keys($adjacency));

        sort($members);

        return $members;
    }

    /**
     * Over the cap, the excerpt holds EXACTLY `CAP` people, the real component is
     * larger (so `totalCount > shownCount`), every chain seed survives the cut,
     * and no returned edge crosses the excerpt boundary. The chain is chosen from
     * the HIGH-numbered spine so a cap-broken implementation that simply took the
     * smallest-xref `CAP` nodes — dropping the chain — fails the subset check.
     */
    #[Test]
    public function overCapExcerptHoldsExactlyCapPeopleKeepsChainAndRestrictsEdges(): void
    {
        // 50 spine + 50 leaves = 100 connected people, comfortably over the 70 cap.
        $adjacency = $this->spineWithLeaves(50);
        $members   = $this->members($adjacency);

        self::assertGreaterThan(self::CAP, count($members), 'fixture must exceed the cap to exercise the excerpt');

        // A chain made of the HIGHEST spine xrefs and their leaves: in pure
        // smallest-xref order these would be the LAST nodes the BFS reaches, so
        // they only appear in the result because the excerpt force-includes the
        // chain seeds.
        $chainIds = ['I048', 'I049', 'I050', 'L050'];

        $excerpt    = MarriageChains::excerpt($adjacency, $members, $chainIds, self::CAP);
        $shown      = $excerpt['members'];
        $shownEdges = $excerpt['edges'];

        // shownCount === CAP, totalCount (the real component) is larger.
        self::assertCount(self::CAP, $shown, 'the excerpt must hold exactly the cap');
        self::assertGreaterThan(count($shown), count($members), 'the real component must be larger than the excerpt');

        // chainIds ⊆ members: every always-included chain node survives the cut.
        foreach ($chainIds as $chainId) {
            self::assertContains(
                $chainId,
                $shown,
                $chainId . ' is a chain node and must always appear in the excerpt',
            );
        }

        // Every returned edge has BOTH endpoints inside the excerpt — no edge
        // leaks to a node the cap dropped.
        $shownSet = array_fill_keys($shown, true);

        foreach ($shownEdges as $edge) {
            self::assertArrayHasKey($edge[0], $shownSet, $edge[0] . ' is an edge endpoint outside the excerpt');
            self::assertArrayHasKey($edge[1], $shownSet, $edge[1] . ' is an edge endpoint outside the excerpt');
        }

        // The excerpt is byte-order sorted (matches the full-group ordering).
        $sorted = $shown;
        sort($sorted);
        self::assertSame($sorted, $shown, 'the excerpt members must be byte-order sorted');
    }

    /**
     * The over-cap excerpt is a no-op for member SELECTION when the group fits:
     * a 41-person-style group (here 30 spine + 30 leaves = 60 < cap) returns
     * every member, in the input order, with every internal edge — the exact
     * branch the repository's 41-person integration fixture exercises.
     */
    #[Test]
    public function belowCapReturnsEveryMemberAndEdgeUnchanged(): void
    {
        $adjacency = $this->spineWithLeaves(30);
        $members   = $this->members($adjacency);

        self::assertLessThanOrEqual(self::CAP, count($members), 'fixture must fit within the cap');

        $excerpt = MarriageChains::excerpt($adjacency, $members, ['I001', 'I002'], self::CAP);

        // Members returned unchanged (no excerpt grown).
        self::assertSame($members, $excerpt['members']);

        // Edge count for a spine-with-leaves of length n: (n - 1) spine edges
        // plus n pendant edges = 2n - 1. For n = 30 that is 59 internal edges.
        self::assertCount((2 * 30) - 1, $excerpt['edges']);
    }

    /**
     * The excerpt is independent of the adjacency map's insertion order and of
     * any neighbour-list order: reversing both yields a byte-for-byte identical
     * result (members AND edges). The BFS sorts its frontier, so without that
     * discipline the cap would cut a different set on the reversed input.
     */
    #[Test]
    public function excerptIsInsertionOrderIndependent(): void
    {
        $adjacency = $this->spineWithLeaves(50);
        $members   = $this->members($adjacency);
        $chainIds  = ['I048', 'I049', 'I050', 'L050'];

        $shuffled = array_map(array_reverse(...), array_reverse($adjacency, true));

        self::assertSame(
            MarriageChains::excerpt($adjacency, $members, $chainIds, self::CAP),
            MarriageChains::excerpt($shuffled, $members, $chainIds, self::CAP),
        );
    }

    /**
     * A chain seed that is NOT a member of the group (e.g. a stale xref from a
     * different component) is silently ignored: it never enters the excerpt and
     * never seeds the BFS, so the result stays within the group's own members.
     */
    #[Test]
    public function nonMemberChainSeedsAreIgnored(): void
    {
        $adjacency = $this->spineWithLeaves(50);
        $members   = $this->members($adjacency);
        $memberSet = array_fill_keys($members, true);

        // "Z999" belongs to no node in the graph.
        $chainIds = ['Z999', 'I048', 'I049', 'I050'];

        $excerpt = MarriageChains::excerpt($adjacency, $members, $chainIds, self::CAP);

        self::assertCount(self::CAP, $excerpt['members']);
        self::assertNotContains('Z999', $excerpt['members'], 'a non-member chain seed must never enter the excerpt');

        foreach ($excerpt['members'] as $shown) {
            self::assertArrayHasKey($shown, $memberSet, $shown . ' is outside the group');
        }
    }

    /**
     * Guards the always-include guarantee directly: a chain that alone exceeds a
     * SMALL cap is still fully present (the cap cannot evict a seed). With a cap
     * of 4 and four chain seeds, all four must show even though the BFS adds no
     * further node.
     */
    #[Test]
    public function chainSeedsAreNeverEvictedByASmallerCap(): void
    {
        $adjacency = $this->spineWithLeaves(50);
        $members   = $this->members($adjacency);
        $chainIds  = ['I010', 'I011', 'I012', 'I013'];

        $excerpt = MarriageChains::excerpt($adjacency, $members, $chainIds, 4);

        self::assertCount(4, $excerpt['members']);

        foreach ($chainIds as $chainId) {
            self::assertTrue(
                in_array($chainId, $excerpt['members'], true),
                $chainId . ' is a chain seed and must survive even a tight cap',
            );
        }
    }
}
