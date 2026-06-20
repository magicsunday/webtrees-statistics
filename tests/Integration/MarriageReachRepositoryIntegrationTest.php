<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Tree\MarriageGroupExcerpt;
use MagicSunday\Webtrees\Statistic\Model\Tree\MarriageReachReport;
use MagicSunday\Webtrees\Statistic\Repository\GenerationDepthRepository;
use MagicSunday\Webtrees\Statistic\Repository\MarriageMapRepository;
use MagicSunday\Webtrees\Statistic\Repository\MarriageReachRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Calc\MarriageChains;
use MagicSunday\Webtrees\Statistic\Support\Database\ChunkedWhereIn;
use MagicSunday\Webtrees\Statistic\Support\Database\GedcomByXref;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\IndividualWire;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RecordName;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;
use function strcmp;

/**
 * End-to-end test of {@see MarriageReachRepository} against
 * `partner-chains.ged` — a real-world intermarriage web of forty-one people
 * (the "40 marriages" cluster) plus a handful of unrelated couples.
 *
 * The largest connected marriage group holds 41 people joined by 40 marriage
 * edges, and the longest unbroken marriage chain through it is 15 people. The
 * group sits well below the {@see MarriageReachRepository::NETWORK_CAP} = 70
 * excerpt cap, so every member is shown (`shownCount == totalCount == 41`).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(MarriageReachRepository::class)]
#[UsesClass(MarriageReachReport::class)]
#[UsesClass(MarriageGroupExcerpt::class)]
#[UsesClass(IndividualWire::class)]
#[UsesClass(MarriageMapRepository::class)]
#[UsesClass(GenerationDepthRepository::class)]
#[UsesClass(ParentMapRepository::class)]
#[UsesClass(MarriageChains::class)]
#[UsesClass(GregorianDate::class)]
#[UsesClass(ChunkedWhereIn::class)]
#[UsesClass(GedcomByXref::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RecordName::class)]
#[UsesClass(RowCast::class)]
final class MarriageReachRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * The summary captures the 15-person longest chain and the 41-person /
     * 40-edge largest group, resolves both to live {@see Individual} objects,
     * and — because the group fits under the excerpt cap — shows every member.
     */
    #[Test]
    public function summaryMatchesAcceptanceFixture(): void
    {
        $tree   = $this->importFixtureTree('partner-chains.ged');
        $result = $this->repository($tree)->summary();

        self::assertInstanceOf(MarriageReachReport::class, $result);

        self::assertSame(15, $result->longestChainLength, 'longest marriage chain through the cluster = 15 people');
        self::assertSame(15, $result->breadthChain);
        self::assertCount(15, $result->chain, 'the 15-person chain resolves to 15 Individual objects');
        self::assertContainsOnlyInstancesOf(Individual::class, $result->chain);

        self::assertSame(41, $result->group->totalCount, 'largest connected marriage group = 41 people');
        self::assertSame(41, $result->group->shownCount, '41 < NETWORK_CAP (70), so every member is shown');
        self::assertCount(41, $result->group->nodes, 'the 41 group members resolve to 41 Individual objects');
        self::assertContainsOnlyInstancesOf(Individual::class, $result->group->nodes);
        self::assertCount(40, $result->group->edges, '40 marriage edges join the 41-person cluster');
        self::assertCount(15, $result->group->chainIds, 'chainIds is the 15-person longest path inside the group');

        // The hub is a real group member; the median is the LOWER-median of the
        // group's collapsed birth+death years (one representative year per
        // person). I21 carries an imprecise `BET 1889 AND 1891` birth, which
        // webtrees stores as two `dates` rows (years 1889 and 1891). Collapsing
        // each person to a single lower-bound year keeps the multiset at 67
        // values whose lower-median is 1893; feeding BOTH of I21's rows (the
        // pre-fix double-count) would add the upper bound 1891 too, dragging the
        // lower-median down to 1892. Pinning 1893 therefore fails RED the moment
        // the per-person collapse is removed.
        self::assertContains($result->group->hubId, $this->nodeXrefs($result));
        self::assertSame(1893, $result->group->medianYear, 'lower-median of the collapsed birth+death years');
    }

    /**
     * `depthPath` mirrors the tree-wide maximum generation depth, so the ratio's
     * depth component is the very value {@see GenerationDepthRepository::summary()}
     * reports for the same tree — derived here rather than hard-coded so the two
     * code paths can never silently disagree.
     */
    #[Test]
    public function summaryDepthPathMirrorsGenerationDepth(): void
    {
        $tree = $this->importFixtureTree('partner-chains.ged');

        $expectedDepth = (new GenerationDepthRepository($tree, new ParentMapRepository($tree)))
            ->summary()
            ->maxDepth;

        $result = $this->repository($tree)->summary();

        self::assertInstanceOf(MarriageReachReport::class, $result);
        self::assertSame($expectedDepth, $result->depthPath);
    }

    /**
     * `jsonSerialize()` must flatten every person to a `{xref, label, sex, birth,
     * death, url}` row and every edge to an `[idA, idB]` xref pair — no
     * {@see Individual} instance survives onto the wire.
     */
    #[Test]
    public function jsonSerializeFlattensPeopleAndEdges(): void
    {
        $tree   = $this->importFixtureTree('partner-chains.ged');
        $result = $this->repository($tree)->summary();

        self::assertInstanceOf(MarriageReachReport::class, $result);

        $json = $result->jsonSerialize();

        self::assertSame(15, $json['longestChainLength']);
        self::assertSame(15, $json['breadthChain']);
        self::assertCount(15, $json['chain']);
        self::assertCount(41, $json['group']['nodes']);
        self::assertCount(40, $json['group']['edges']);

        // The flattened chain carries the same people, in order, as the live
        // chain — proving the Individual objects were replaced by their wire rows
        // (xref + plain label) rather than encoded as Individual instances.
        $chainXrefs = array_map(
            static fn (array $person): string => $person['xref'],
            $json['chain'],
        );
        self::assertSame(
            array_map(static fn (Individual $individual): string => $individual->xref(), $result->chain),
            $chainXrefs,
        );

        // The wire label is the plain-text full name (no `<span class="NAME">`
        // markup, no escaped entities) — the strip-and-decode flatten actually
        // ran on the Individual rather than leaking its HTML name.
        $firstChainPerson = $json['chain'][0];
        self::assertStringNotContainsString('<', $firstChainPerson['label']);
        self::assertStringNotContainsString('&', $firstChainPerson['label']);
        self::assertNotSame('', $firstChainPerson['label']);

        // Every edge is an ordered xref pair drawn from the group's node set —
        // never an Individual on the wire.
        $nodeXrefs = array_map(
            static fn (array $person): string => $person['xref'],
            $json['group']['nodes'],
        );

        foreach ($json['group']['edges'] as $edge) {
            self::assertContains($edge[0], $nodeXrefs);
            self::assertContains($edge[1], $nodeXrefs);
            self::assertLessThan(0, strcmp($edge[0], $edge[1]), 'edge endpoints are byte-ordered low→high');
        }

        self::assertSame($result->group->hubId, $json['group']['hubId']);
        self::assertSame(41, $json['group']['totalCount']);
        self::assertSame(41, $json['group']['shownCount']);
        self::assertSame($result->group->medianYear, $json['group']['medianYear']);
    }

    /**
     * Wire the repository under test for a given tree.
     */
    private function repository(Tree $tree): MarriageReachRepository
    {
        return new MarriageReachRepository(
            $tree,
            new MarriageMapRepository($tree),
            new GenerationDepthRepository($tree, new ParentMapRepository($tree)),
        );
    }

    /**
     * Collect the resolved group nodes' xrefs.
     *
     * @return list<string>
     */
    private function nodeXrefs(MarriageReachReport $report): array
    {
        $xrefs = [];

        foreach ($report->group->nodes as $node) {
            $xrefs[] = $node->xref();
        }

        return $xrefs;
    }
}
