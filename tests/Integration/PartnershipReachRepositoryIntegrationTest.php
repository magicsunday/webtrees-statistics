<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Factories\CacheFactory;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Tree\PartnershipReachReport;
use MagicSunday\Webtrees\Statistic\Repository\GenerationDepthRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use MagicSunday\Webtrees\Statistic\Repository\PartnershipMapRepository;
use MagicSunday\Webtrees\Statistic\Repository\PartnershipReachRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\PartnershipChains;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\IndividualWire;
use PHPUnit\Framework\Attributes\Test;

use function array_column;
use function array_flip;
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
final class PartnershipReachRepositoryIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * The summary captures the 15-person longest chain and the 41-person /
     * 40-edge largest group, carries both as wire rows,
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
        self::assertCount(15, $result->chain, 'the 15-person chain resolves to 15 wire rows');
        self::assertSame(['xref', 'label', 'sex', 'birth', 'death', 'url'], array_keys($result->chain[0]));

        self::assertSame(41, $result->group->totalCount, 'largest connected partnership group = 41 people');
        self::assertSame(41, $result->group->shownCount, '41 < NETWORK_CAP (70), so every member is shown');
        self::assertCount(41, $result->group->nodes, 'the 41 group members resolve to 41 wire rows');
        self::assertSame(['xref', 'label', 'sex', 'birth', 'death', 'url'], array_keys($result->group->nodes[0]));
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
     * A group over the excerpt cap draws the number of people that the cap
     * allows, while the legend figures keep reporting the group. The partnership
     * count of the legend is the edge count of the group and not the edge count
     * of the drawn excerpt. The generated cluster is a spine of couples with one
     * further partner on each spine person.
     */
    #[Test]
    public function summaryReportsTheWholeGroupAboveTheExcerptCap(): void
    {
        $spineLength = 40;
        $tree        = $this->importGedcomTree('over-cap', $this->spineWithPartnersGedcom($spineLength));
        $result      = $this->repository($tree)->summary();

        self::assertInstanceOf(PartnershipReachReport::class, $result);

        $people = 2 * $spineLength;

        self::assertGreaterThan(PartnershipReachRepository::NETWORK_CAP, $result->group->totalCount, 'The group has to exceed the cap');

        self::assertSame($people, $result->group->totalCount);
        self::assertSame($people - 1, $result->group->totalEdgeCount, 'the legend counts the partnerships of the group');

        self::assertSame(PartnershipReachRepository::NETWORK_CAP, $result->group->shownCount);
        self::assertCount(PartnershipReachRepository::NETWORK_CAP, $result->group->nodes);
        self::assertLessThan($result->group->totalEdgeCount, count($result->group->edges), 'the drawn excerpt holds fewer partnerships than the group');

        // The excerpt holds every partnership among the drawn people and no other. A
        // spine person shares one with the own partner and one with the next spine person.
        $shown = array_flip($this->nodeXrefs($result));

        $expectedEdgeCount = 0;

        for ($index = 1; $index <= $spineLength; ++$index) {
            $number = sprintf('%02d', $index);

            if (isset($shown['S' . $number], $shown['P' . $number])) {
                ++$expectedEdgeCount;
            }

            if (
                ($index < $spineLength)
                && isset($shown['S' . $number], $shown['S' . sprintf('%02d', $index + 1)])
            ) {
                ++$expectedEdgeCount;
            }
        }

        self::assertGreaterThan(0, $expectedEdgeCount, 'the drawn people have to share partnerships');
        self::assertCount($expectedEdgeCount, $result->group->edges);
    }

    /**
     * Four people whose partnerships form a loop (A with B, B with C, C with D,
     * D with A). Double BFS returns a valid but not necessarily longest chain
     * for such a group, so the report marks the chain as approximate and the
     * payload carries the same status.
     */
    #[Test]
    public function summaryMarksTheChainOfAGroupWithALoopAsApproximate(): void
    {
        $tree   = $this->importGedcomTree('loop', $this->fourCycleGedcom());
        $result = $this->repository($tree)->summary();

        self::assertInstanceOf(PartnershipReachReport::class, $result);
        self::assertTrue($result->chainIsApproximate);
        self::assertFalse($result->chainIsCapped);
        self::assertGreaterThanOrEqual(3, $result->longestChainLength);

        $payload = $result->jsonSerialize();

        self::assertTrue($payload['chainIsApproximate']);
        self::assertFalse($payload['chainIsCapped']);
    }

    /**
     * A partnership chain through a group without a loop is the exact longest
     * chain, so neither status is set.
     */
    #[Test]
    public function summaryKeepsTheChainOfAGroupWithoutALoopExact(): void
    {
        $spineLength = 5;
        $tree        = $this->importGedcomTree('no-loop', $this->spineWithPartnersGedcom($spineLength));
        $result      = $this->repository($tree)->summary();

        self::assertInstanceOf(PartnershipReachReport::class, $result);
        self::assertFalse($result->chainIsApproximate);
        self::assertFalse($result->chainIsCapped);
        self::assertSame($spineLength + 2, $result->longestChainLength, 'the first partner, the spine and the last partner');
    }

    /**
     * A chain that reaches the reconstruction bound is marked as capped. The
     * spine of couples has no loop, so only the cap status is set.
     */
    #[Test]
    public function summaryMarksAChainAtTheReconstructionBoundAsCapped(): void
    {
        $tree   = $this->importGedcomTree('capped', $this->spineWithPartnersGedcom(PartnershipChains::MAX_CHAIN));
        $result = $this->repository($tree)->summary();

        self::assertInstanceOf(PartnershipReachReport::class, $result);
        self::assertTrue($result->chainIsCapped);
        self::assertFalse($result->chainIsApproximate);
        self::assertSame(PartnershipChains::MAX_CHAIN, $result->longestChainLength);

        self::assertTrue($result->jsonSerialize()['chainIsCapped']);
    }

    /**
     * The serialised chain and group nodes are the wire rows of the people that
     * they name, in the order of the chain. A person who cannot be shown keeps
     * the node and loses every derived fact, which the wire row of the record
     * decides.
     */
    #[Test]
    public function serializedPeopleEqualTheWireRowsOfTheirRecords(): void
    {
        $tree    = $this->importFixtureTree('partner-chains.ged');
        $payload = ($this->repository($tree)->summary() ?? self::fail('the fixture has a partnership group'))->jsonSerialize();

        $wireRow = static fn (string $xref): array => IndividualWire::row(
            Registry::individualFactory()->make($xref, $tree) ?? self::fail('unknown record ' . $xref),
        );

        self::assertSame(array_map($wireRow, $payload['group']['chainIds']), $payload['chain']);
        self::assertSame(
            array_map($wireRow, array_column($payload['group']['nodes'], 'xref')),
            $payload['group']['nodes'],
        );
    }

    /**
     * A chain of three deceased people whose middle person carries a confidential
     * SEX fact. A guest sees the records but not that fact, so the wire row of
     * the middle person reads the unknown sex in the chain and in the group.
     */
    #[Test]
    public function restrictedSexReadsUnknownInTheSerializedChainAndGroup(): void
    {
        $lines = ['0 HEAD', '1 CHAR UTF-8', '1 GEDC', '2 VERS 5.5.1', '2 FORM LINEAGE-LINKED'];

        foreach (['A' => ['M', ''], 'B' => ['F', "\n2 RESN confidential"], 'C' => ['M', '']] as $xref => [$sex, $restriction]) {
            $lines[] = '0 @' . $xref . '@ INDI';
            $lines[] = '1 NAME Person ' . $xref . ' /Chain/';
            $lines[] = '1 SEX ' . $sex . $restriction;
            $lines[] = '1 BIRT';
            $lines[] = '2 DATE 1900';
            $lines[] = '1 DEAT';
            $lines[] = '2 DATE 1960';
        }

        foreach ([['F1', 'A', 'B'], ['F2', 'C', 'B']] as [$family, $husband, $wife]) {
            $lines[] = '0 @' . $family . '@ FAM';
            $lines[] = '1 HUSB @' . $husband . '@';
            $lines[] = '1 WIFE @' . $wife . '@';
        }

        $lines[] = '0 TRLR';

        $tree = $this->importGedcomTree('restricted-sex-chain', implode("\n", $lines));
        $this->hideLivingPeople($tree);

        Auth::logout();

        $payload = ($this->repository($tree)->summary() ?? self::fail('the chain is a partnership group'))->jsonSerialize();

        self::assertSame(['A', 'B', 'C'], array_column($payload['chain'], 'xref'));
        self::assertSame(['M', 'U', 'M'], array_column($payload['chain'], 'sex'));
        self::assertSame(['M', 'U', 'M'], array_column($payload['group']['nodes'], 'sex'));
    }

    /**
     * A group over the excerpt cap whose people all carry digit-only XREFs is
     * drawn like any other group. The XREFs are integers once they are set keys
     * of the excerpt, and the excerpt returns them as strings again.
     */
    #[Test]
    public function summaryDrawsAnOverCapGroupOfDigitOnlyXrefs(): void
    {
        $people = PartnershipReachRepository::NETWORK_CAP + 5;
        $tree   = $this->importGedcomTree('over-cap-numeric', $this->numericStarGedcom($people));
        $result = $this->repository($tree)->summary();

        self::assertInstanceOf(PartnershipReachReport::class, $result);

        self::assertSame($people, $result->group->totalCount);
        self::assertSame(PartnershipReachRepository::NETWORK_CAP, $result->group->shownCount);

        $xrefs = $this->nodeXrefs($result);

        self::assertCount(PartnershipReachRepository::NETWORK_CAP, $xrefs);
        self::assertContains('1', $xrefs, 'the hub that every partnership of the star runs through is drawn');
        self::assertCount(PartnershipReachRepository::NETWORK_CAP - 1, $result->group->edges, 'every drawn person but the hub hangs on one edge');
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
     * `jsonSerialize()` carries every person as a `{xref, label, sex, birth,
     * death, url}` row and every edge as an `[idA, idB]` xref pair — no
     * framework record survives onto the wire.
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

        // The serialised chain carries the same people, in order, as the report
        // chain, as wire rows (xref + plain label).
        $chainXrefs = array_map(
            static fn (array $person): string => $person['xref'],
            $json['chain'],
        );
        self::assertSame(
            array_column($result->chain, 'xref'),
            $chainXrefs,
        );

        // The wire label is the plain-text full name (no `<span class="NAME">`
        // markup, no escaped entities) — the strip-and-decode flatten actually
        // ran on the record rather than leaking its HTML name.
        $firstChainPerson = $json['chain'][0];
        self::assertStringNotContainsString('<', $firstChainPerson['label']);
        self::assertStringNotContainsString('&', $firstChainPerson['label']);
        self::assertNotSame('', $firstChainPerson['label']);

        // Every edge is an ordered xref pair drawn from the group's node set —
        // never a framework record on the wire.
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
     * A guest needs no visibility lookup for a person whose own facts carry no
     * date, because such a person cannot give a year to the median. Two groups
     * above both the excerpt cap and the chain bound draw and chain the same
     * number of people, so the number of queries stays the same when the group
     * grows. A first run warms the preference cache of the host, which would add
     * queries to the run that follows it.
     */
    #[Test]
    public function guestQueryCountDoesNotGrowWithPeopleWithoutLifeDates(): void
    {
        $this->guestQueryCount('dateless-warm-up', 8);

        self::assertSame(
            $this->guestQueryCount('dateless-small', PartnershipChains::MAX_CHAIN + 50),
            $this->guestQueryCount('dateless-large', PartnershipChains::MAX_CHAIN + 200),
        );
    }

    /**
     * Count the queries of one summary for a guest on a chain of people who have
     * parents and no dates at all.
     *
     * @param string $name   The name of the imported tree
     * @param int    $people The number of people in the chain
     */
    private function guestQueryCount(string $name, int $people): int
    {
        $tree = $this->importGedcomTree($name, $this->datelessChainGedcom($people));
        $this->actAsVisitorWithLivingHidden($tree);

        // The import fills the record cache of the host, and a real request starts
        // with an empty one, so every record that the viewer check needs is a query.
        Registry::cache(new CacheFactory());

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $report = $this->repository($tree)->summary() ?? self::fail('the chain is a partnership group');

        self::assertSame($people, $report->group->totalCount, 'precondition: the whole chain is one group');

        return count(DB::connection()->getQueryLog());
    }

    /**
     * GEDCOM of a chain of partners without any date, where every person is the
     * child of a couple outside the chain, so the host has relatives to look at.
     */
    private function datelessChainGedcom(int $people): string
    {
        $lines = ['0 HEAD', '1 CHAR UTF-8', '1 GEDC', '2 VERS 5.5.1', '2 FORM LINEAGE-LINKED'];

        for ($index = 1; $index <= $people; ++$index) {
            $sex     = ($index % 2 === 1) ? 'M' : 'F';
            $lines[] = '0 @C' . $index . '@ INDI';
            $lines[] = '1 NAME Chain ' . $index . ' /Dateless/';
            $lines[] = '1 SEX ' . $sex;
            $lines[] = '1 FAMC @FP' . $index . '@';

            if ($index > 1) {
                $lines[] = '1 FAMS @FC' . ($index - 1) . '@';
            }

            if ($index < $people) {
                $lines[] = '1 FAMS @FC' . $index . '@';
            }

            $lines[] = '0 @FA' . $index . '@ INDI';
            $lines[] = '1 NAME Father ' . $index . ' /Dateless/';
            $lines[] = '1 SEX M';
            $lines[] = '1 FAMS @FP' . $index . '@';
            $lines[] = '0 @MO' . $index . '@ INDI';
            $lines[] = '1 NAME Mother ' . $index . ' /Dateless/';
            $lines[] = '1 SEX F';
            $lines[] = '1 FAMS @FP' . $index . '@';
            $lines[] = '0 @FP' . $index . '@ FAM';
            $lines[] = '1 HUSB @FA' . $index . '@';
            $lines[] = '1 WIFE @MO' . $index . '@';
            $lines[] = '1 CHIL @C' . $index . '@';

            if ($index < $people) {
                $lines[] = '0 @FC' . $index . '@ FAM';
                $lines[] = '1 ' . (($sex === 'M') ? 'HUSB' : 'WIFE') . ' @C' . $index . '@';
                $lines[] = '1 ' . (($sex === 'M') ? 'WIFE' : 'HUSB') . ' @C' . ($index + 1) . '@';
            }
        }

        $lines[] = '0 TRLR';

        return implode("\n", $lines);
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
        return array_column($report->group->nodes, 'xref');
    }

    /**
     * GEDCOM for one connected star of people with digit-only XREFs. Person 1 is
     * a man married to each of the other people, who are women, so the longest
     * chain of the group is short and the cap cuts the rest. A star of n people
     * gives n - 1 partnerships.
     */
    private function numericStarGedcom(int $people): string
    {
        $lines = ['0 HEAD', '1 CHAR UTF-8', '1 GEDC', '2 VERS 5.5.1', '2 FORM LINEAGE-LINKED'];

        for ($number = 1; $number <= $people; ++$number) {
            $lines[] = '0 @' . $number . '@ INDI';
            $lines[] = '1 NAME Person ' . $number . ' /Star/';
            $lines[] = '1 SEX ' . (($number === 1) ? 'M' : 'F');
        }

        for ($number = 2; $number <= $people; ++$number) {
            $lines[] = '0 @F' . $number . '@ FAM';
            $lines[] = '1 HUSB @1@';
            $lines[] = '1 WIFE @' . $number . '@';
        }

        $lines[] = '0 TRLR';

        return implode("\n", $lines);
    }

    /**
     * GEDCOM for four people whose partnerships form a loop. A and C are male,
     * B and D female, and each of the four couples is its own family.
     */
    private function fourCycleGedcom(): string
    {
        $lines = ['0 HEAD', '1 CHAR UTF-8', '1 GEDC', '2 VERS 5.5.1', '2 FORM LINEAGE-LINKED'];

        foreach (['A' => 'M', 'B' => 'F', 'C' => 'M', 'D' => 'F'] as $xref => $sex) {
            $lines[] = '0 @' . $xref . '@ INDI';
            $lines[] = '1 NAME Person ' . $xref . ' /Loop/';
            $lines[] = '1 SEX ' . $sex;
        }

        foreach ([['F1', 'A', 'B'], ['F2', 'C', 'B'], ['F3', 'C', 'D'], ['F4', 'A', 'D']] as [$family, $husband, $wife]) {
            $lines[] = '0 @' . $family . '@ FAM';
            $lines[] = '1 HUSB @' . $husband . '@';
            $lines[] = '1 WIFE @' . $wife . '@';
        }

        $lines[] = '0 TRLR';

        return implode("\n", $lines);
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
