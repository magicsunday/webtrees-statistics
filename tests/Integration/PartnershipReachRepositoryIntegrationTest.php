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
use MagicSunday\Webtrees\Statistic\Model\Tree\PartnershipGroupExcerpt;
use MagicSunday\Webtrees\Statistic\Model\Tree\PartnershipReachReport;
use MagicSunday\Webtrees\Statistic\Repository\GenerationDepthRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use MagicSunday\Webtrees\Statistic\Repository\PartnershipMapRepository;
use MagicSunday\Webtrees\Statistic\Repository\PartnershipReachRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Calc\PartnershipChains;
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
use function count;
use function implode;
use function sprintf;
use function strcmp;

/**
 * End-to-end test of {@see PartnershipReachRepository} against
 * `partner-chains.ged` — a real-world interconnected partnership web of forty-one people
 * (the "40 partnerships" cluster) plus a handful of unrelated couples.
 *
 * The largest connected partnership group holds 41 people joined by 40 partnership
 * edges, and the longest unbroken partnership chain through it is 15 people. The
 * group sits well below the {@see PartnershipReachRepository::NETWORK_CAP} = 70
 * excerpt cap, so every member is shown (`shownCount == totalCount == 41`).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(PartnershipReachRepository::class)]
#[UsesClass(PartnershipReachReport::class)]
#[UsesClass(PartnershipGroupExcerpt::class)]
#[UsesClass(IndividualWire::class)]
#[UsesClass(PartnershipMapRepository::class)]
#[UsesClass(GenerationDepthRepository::class)]
#[UsesClass(ParentMapRepository::class)]
#[UsesClass(PartnershipChains::class)]
#[UsesClass(GregorianDate::class)]
#[UsesClass(ChunkedWhereIn::class)]
#[UsesClass(GedcomByXref::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RecordName::class)]
#[UsesClass(RowCast::class)]
final class PartnershipReachRepositoryIntegrationTest extends AbstractIntegrationTestCase
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

        self::assertInstanceOf(PartnershipReachReport::class, $result);

        self::assertSame(15, $result->longestChainLength, 'longest partnership chain through the cluster = 15 people');
        self::assertSame(15, $result->breadthChain);
        self::assertCount(15, $result->chain, 'the 15-person chain resolves to 15 Individual objects');
        self::assertContainsOnlyInstancesOf(Individual::class, $result->chain);

        self::assertSame(41, $result->group->totalCount, 'largest connected partnership group = 41 people');
        self::assertSame(41, $result->group->shownCount, '41 < NETWORK_CAP (70), so every member is shown');
        self::assertCount(41, $result->group->nodes, 'the 41 group members resolve to 41 Individual objects');
        self::assertContainsOnlyInstancesOf(Individual::class, $result->group->nodes);
        self::assertCount(40, $result->group->edges, '40 partnership edges join the 41-person cluster');
        self::assertSame(40, $result->group->totalEdgeCount, 'totalEdgeCount is the whole-group partnership count; here = the 40 shown edges since 41 < cap');
        self::assertCount(15, $result->group->chainIds, 'chainIds is the 15-person longest path inside the group');

        // hubDegree is the hub's real partnership degree over the WHOLE group. With
        // 41 < NETWORK_CAP every member is shown, so the full-group degree must
        // equal the number of drawn edges incident to the hub — derived from the
        // edge list rather than hard-coded so the assertion can never drift from
        // the fixture, and non-zero so it cannot pass vacuously.
        $hubIncidentEdges = 0;

        foreach ($result->group->edges as $edge) {
            if (($edge[0] === $result->group->hubId) || ($edge[1] === $result->group->hubId)) {
                ++$hubIncidentEdges;
            }
        }

        self::assertGreaterThan(0, $hubIncidentEdges, 'the hub must have at least one partnership');
        self::assertSame($hubIncidentEdges, $result->group->hubDegree, 'under the cap the hub degree equals its incident shown edges');

        // The hub is a real group member. The median is the lower median over one
        // lower-bound year per person and tag, taken from the visible BIRT and DEAT
        // facts. I21 carries an imprecise `BET 1889 AND 1891` birth, so the year
        // that counts is the lower bound.
        self::assertContains($result->group->hubId, $this->nodeXrefs($result));
        self::assertSame(1893, $result->group->medianYear, 'lower median of the visible lower-bound years');
    }

    /**
     * A group over the excerpt cap draws the number of people that the cap allows, while
     * the legend figures keep reporting the whole group. The partnership count
     * of the legend is the edge count of the group and not the edge
     * count of the drawn excerpt. The generated cluster is a spine of couples
     * with one further partner on each spine person.
     */
    #[Test]
    public function summaryReportsTheWholeGroupAboveTheExcerptCap(): void
    {
        $spineLength = 40;
        $tree        = $this->importGedcomTree('over-cap', $this->spineWithPartnersGedcom($spineLength));
        $result      = $this->repository($tree)->summary();

        self::assertInstanceOf(PartnershipReachReport::class, $result);

        $people = 2 * $spineLength;

        self::assertGreaterThan(PartnershipReachRepository::NETWORK_CAP, $people, 'precondition: the group exceeds the cap');

        self::assertSame($people, $result->group->totalCount);
        self::assertSame($people - 1, $result->group->totalEdgeCount, 'the legend counts the partnerships of the whole group');

        self::assertSame(PartnershipReachRepository::NETWORK_CAP, $result->group->shownCount);
        self::assertCount(PartnershipReachRepository::NETWORK_CAP, $result->group->nodes);
        self::assertLessThan($result->group->totalEdgeCount, count($result->group->edges), 'the drawn excerpt holds fewer partnerships than the group');
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

        self::assertInstanceOf(PartnershipReachReport::class, $result);
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

        self::assertInstanceOf(PartnershipReachReport::class, $result);

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
        self::assertSame($result->group->hubDegree, $json['group']['hubDegree']);
        self::assertSame(41, $json['group']['totalCount']);
        self::assertSame(40, $json['group']['totalEdgeCount']);
        self::assertSame(41, $json['group']['shownCount']);
        self::assertSame($result->group->medianYear, $json['group']['medianYear']);
    }

    /**
     * Wire the repository under test for a given tree.
     */
    private function repository(Tree $tree): PartnershipReachRepository
    {
        return new PartnershipReachRepository(
            $tree,
            new PartnershipMapRepository($tree),
            new GenerationDepthRepository($tree, new ParentMapRepository($tree)),
        );
    }

    /**
     * Collect the resolved group nodes' xrefs.
     *
     * @return list<string>
     */
    private function nodeXrefs(PartnershipReachReport $report): array
    {
        $xrefs = [];

        foreach ($report->group->nodes as $node) {
            $xrefs[] = $node->xref();
        }

        return $xrefs;
    }

    /**
     * GEDCOM for one connected partnership cluster. A spine of people S01, S02
     * and so on with alternating sex, each married to the next one, and one
     * further partner P01, P02 and so on of the other sex married to each spine
     * person. A spine of n people gives 2n people and 2n - 1 partnerships.
     */
    private function spineWithPartnersGedcom(int $spineLength): string
    {
        $lines = ['0 HEAD', '1 CHAR UTF-8', '1 GEDC', '2 VERS 5.5.1', '2 FORM LINEAGE-LINKED'];

        for ($index = 1; $index <= $spineLength; ++$index) {
            $number      = sprintf('%02d', $index);
            $spineSex    = ($index % 2 === 1) ? 'M' : 'F';
            $partnerSex  = ($spineSex === 'M') ? 'F' : 'M';
            $spineRole   = ($spineSex === 'M') ? 'HUSB' : 'WIFE';
            $partnerRole = ($spineSex === 'M') ? 'WIFE' : 'HUSB';
            $lines[]     = '0 @S' . $number . '@ INDI';
            $lines[]     = '1 NAME Spine ' . $number . ' /Cluster/';
            $lines[]     = '1 SEX ' . $spineSex;
            $lines[]     = '0 @P' . $number . '@ INDI';
            $lines[]     = '1 NAME Partner ' . $number . ' /Cluster/';
            $lines[]     = '1 SEX ' . $partnerSex;
            $lines[]     = '0 @FP' . $number . '@ FAM';
            $lines[]     = '1 ' . $spineRole . ' @S' . $number . '@';
            $lines[]     = '1 ' . $partnerRole . ' @P' . $number . '@';

            if ($index < $spineLength) {
                $next     = sprintf('%02d', $index + 1);
                $nextRole = ($spineSex === 'M') ? 'WIFE' : 'HUSB';
                $lines[]  = '0 @FS' . $number . '@ FAM';
                $lines[]  = '1 ' . $spineRole . ' @S' . $number . '@';
                $lines[]  = '1 ' . $nextRole . ' @S' . $next . '@';
            }
        }

        $lines[] = '0 TRLR';

        return implode("\n", $lines);
    }
}
