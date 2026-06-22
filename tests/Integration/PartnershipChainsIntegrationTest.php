<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\PartnershipMapRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\PartnershipChains;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;
use function array_reverse;
use function array_unique;
use function count;

/**
 * End-to-end test of {@see PartnershipChains} against the `partner-chains.ged`
 * fixture, fed through the real {@see PartnershipMapRepository}. The fixture
 * encodes exactly two partnership groups of three-plus people: a large 41-person
 * web of 40 partnerships and a smaller 11-person chain; every other family is a
 * lone couple that the three-person threshold drops.
 *
 * Expected: largest group = 41 members / 40 edges, qualifying component sizes
 * = [41, 11].
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(PartnershipChains::class)]
#[UsesClass(PartnershipMapRepository::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class PartnershipChainsIntegrationTest extends IntegrationTestCase
{
    /**
     * The largest connected partnership group in the fixture spans 41 people
     * joined by 40 partnerships.
     */
    #[Test]
    public function largestGroupHas41MembersAnd40Edges(): void
    {
        $tree      = $this->importFixtureTree('partner-chains.ged');
        $adjacency = (new PartnershipMapRepository($tree))->build();
        $largest   = PartnershipChains::largestGroup($adjacency);

        self::assertNotNull($largest);
        self::assertCount(41, $largest['members'], 'partner-chains.ged largest partnership web spans 41 people');
        self::assertSame(40, $largest['edges'], 'partner-chains.ged largest partnership web has 40 partnerships');
    }

    /**
     * Only two groups clear the three-person threshold, of sizes 41 and 11;
     * every other family in the fixture is a lone couple that is dropped.
     */
    #[Test]
    public function qualifyingComponentSizesAreFortyOneAndEleven(): void
    {
        $tree       = $this->importFixtureTree('partner-chains.ged');
        $adjacency  = (new PartnershipMapRepository($tree))->build();
        $components = PartnershipChains::components($adjacency);

        self::assertSame(
            [41, 11],
            array_map(count(...), $components),
            'partner-chains.ged has exactly two groups of 3+ people: a 41-person web and an 11-person chain',
        );
    }

    /**
     * The longest partnership chain in the fixture spans 15 people joined by 14
     * partnerships — NOT 14 people.
     *
     * The reporter's reference module measures the chain as the eccentricity of
     * a fixed proband (the longest line FROM one chosen person OUTWARD), which
     * under-reports: on this fixture's 41-person web it yields a 14-person walk
     * because it never crosses the branch hub the proband-rooted line misses. A
     * tree-wide statistic must be start-point independent, so {@see PartnershipChains}
     * computes the graph DIAMETER (the longest simple path anywhere in the
     * component, via double-BFS), which runs leaf-to-leaf through that hub and
     * finds the true 15-person / 14-partnership chain. This test pins 15 and
     * asserts it is not 14, so any regression back to a proband-rooted walk
     * fails here.
     */
    #[Test]
    public function longestChainHas15PersonsNot14(): void
    {
        $tree      = $this->importFixtureTree('partner-chains.ged');
        $adjacency = (new PartnershipMapRepository($tree))->build();
        $chain     = PartnershipChains::longestChain($adjacency);

        // 15, NOT 14: a proband-rooted eccentricity walk under-reports this
        // 41-person web at 14 because it never crosses the branch hub the
        // diameter (double-BFS longest simple path) runs through.
        self::assertCount(15, $chain, 'partner-chains.ged longest partnership chain spans 15 people, not the 14 a proband-rooted walk reports');

        // The 15 people are not just any 15: they form a genuine simple path —
        // no repeated person, and every consecutive pair is a real partnership
        // edge in the graph — so a regression returning a different 15-node walk
        // (e.g. one that revisits a person or crosses a non-edge) fails here.
        self::assertCount(15, array_unique($chain), 'the chain visits 15 distinct people');

        $previous = null;

        foreach ($chain as $person) {
            if ($previous !== null) {
                self::assertContains(
                    $person,
                    $adjacency[$previous],
                    $previous . ' and ' . $person . ' must be a married couple',
                );
            }

            $previous = $person;
        }
    }

    /**
     * The longest chain is independent of the adjacency map's insertion order.
     * The BFS sorts every neighbour list before use, so reversing neighbour
     * lists alone would be inert; this test ALSO reverses the top-level key
     * order — the start-node-seeding input — so it genuinely exercises the
     * double-BFS choices (start node, farthest pick, component tie-break),
     * proving they are all pinned to the byte-order xref tie-break.
     */
    #[Test]
    public function longestChainIsInsertionOrderIndependent(): void
    {
        $tree      = $this->importFixtureTree('partner-chains.ged');
        $adjacency = (new PartnershipMapRepository($tree))->build();
        $shuffled  = array_map(array_reverse(...), array_reverse($adjacency, true));

        self::assertSame(
            PartnershipChains::longestChain($adjacency),
            PartnershipChains::longestChain($shuffled),
        );
    }
}
