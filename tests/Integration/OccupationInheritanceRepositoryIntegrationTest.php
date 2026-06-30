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
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyFlowsPayload;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyLink;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeySample;
use MagicSunday\Webtrees\Statistic\Repository\OccupationInheritanceRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RecordName;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Sankey\BipartiteSankeyAssembler;
use MagicSunday\Webtrees\Statistic\Support\Sankey\SankeySampleResolver;
use MagicSunday\Webtrees\Statistic\Test\Support\Narrowing\PayloadNarrowing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;
use function array_slice;
use function array_unique;

/**
 * End-to-end test of the parent → child occupation-inheritance aggregator. The
 * curated fixture exercises every data-layer behaviour that matters: a trade
 * passed down to four children (cap on samples), a case-folded variant that
 * merges into that flow, a changed trade, a parent with several occupations
 * (only the first counts), a father → daughter flow and a mother → daughter
 * flow (both parents are considered, regardless of sex), a child of two working
 * parents that feeds two distinct flows, and four kinds of pair that must be
 * dropped — a parent without an occupation, a child without an occupation, a
 * child with no resolvable parent, and a child whose only recorded parent lacks
 * a trade.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(OccupationInheritanceRepository::class)]
#[UsesClass(SankeyFlowsPayload::class)]
#[UsesClass(SankeyLink::class)]
#[UsesClass(SankeySample::class)]
#[UsesClass(ParentMapRepository::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(GedcomScanner::class)]
#[UsesClass(RowCast::class)]
#[UsesClass(BipartiteSankeyAssembler::class)]
#[UsesClass(SankeySampleResolver::class)]
#[UsesClass(RecordName::class)]
final class OccupationInheritanceRepositoryIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * Aggregates the fixture into the bipartite Sankey payload. Seven flows
     * survive: Farmer → Farmer (×5, four direct children plus the case-folded
     * `farmer`/`FARMER` pair), Blacksmith → Farmer (×1), Carpenter → Carpenter
     * (×1), Weaver → Weaver (×1, a father → daughter), Seamstress → Seamstress
     * (×1, a mother → daughter), and Mason → Glazier plus Potter → Glazier (×1
     * each, the two trades of a child's working father and mother). Every
     * dropped pair stays out of the result.
     */
    #[Test]
    public function occupationInheritanceReturnsTheExpectedAggregation(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        // Seven distinct flows survive.
        self::assertCount(7, $result->links);

        // Source column then target column, each in encounter order over the
        // weight-sorted flows. Equal-weight flows keep their insertion order,
        // which follows the lexicographic xref scan (I1, I10, …, I2, …, I9), so
        // the single Blacksmith → Farmer pair (child I9) lands last.
        self::assertSame(
            [
                'Farmer', 'Carpenter', 'Weaver', 'Seamstress', 'Mason', 'Potter', 'Blacksmith',
                'Farmer', 'Carpenter', 'Weaver', 'Seamstress', 'Glazier',
            ],
            $result->nodes,
        );

        // Heaviest flow leads — Farmer (source idx 0) → Farmer (target idx 0,
        // shifted by sourceColumnSize=7 to absolute idx 7). Its weight of 5
        // proves the case-folded `farmer` / `FARMER` pair merged into the four
        // direct Farmer → Farmer children.
        $heaviest = $result->links[0];
        self::assertSame(0, $heaviest->source);
        self::assertSame(7, $heaviest->target);
        self::assertSame(5, $heaviest->value);

        // The six remaining flows weigh 1 each.
        $tailValues = array_map(
            static fn (SankeyLink $link): int => $link->value,
            array_slice($result->links, 1),
        );
        self::assertSame([1, 1, 1, 1, 1, 1], $tailValues);

        // Every link respects the bipartite invariant: source index in the
        // source column [0, 7), target index in the target column [7, 12).
        foreach ($result->links as $link) {
            self::assertLessThan(7, $link->source, 'source index must be in the source column');
            self::assertGreaterThanOrEqual(7, $link->target, 'target index must be in the target column');
        }
    }

    /**
     * Both parents are considered regardless of sex, so a daughter inherits a
     * father's trade (Weaver → Weaver) and a mother's trade reaches her daughter
     * (Seamstress → Seamstress) — the two flows the issue asked for that the
     * former father → son scan dropped.
     */
    #[Test]
    public function occupationInheritanceCountsBothParentSexesAndChildSexes(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        $flows = $this->flowLabels($result);

        self::assertContains(['Weaver', 'Weaver'], $flows, 'a father → daughter trade must be counted');
        self::assertContains(['Seamstress', 'Seamstress'], $flows, 'a mother → daughter trade must be counted');
    }

    /**
     * A child whose father and mother both carry a (different) trade feeds TWO
     * distinct flows — one per parent occupation. The fixture's Nina has a Mason
     * father and a Potter mother and is herself a Glazier, so both Mason →
     * Glazier and Potter → Glazier surface, each weighing one.
     */
    #[Test]
    public function occupationInheritanceCountsBothParentsOfOneChild(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        $flows = $this->flowLabels($result);

        self::assertContains(['Mason', 'Glazier'], $flows, 'the father trade of a two-parent child must be counted');
        self::assertContains(['Potter', 'Glazier'], $flows, 'the mother trade of a two-parent child must be counted');
    }

    /**
     * A child whose father and mother share the same trade is counted only once
     * for that flow — the two parents must not double the single child into the
     * band. The fixture has one Baker father, one Baker mother and a Baker
     * child, so the Baker → Baker flow weighs one and surfaces the child a
     * single time.
     */
    #[Test]
    public function occupationInheritanceCountsAChildOnceWhenBothParentsShareTheTrade(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance-shared-parent-trade.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        self::assertCount(1, $result->links, 'two same-trade parents feed one flow, not two');
        self::assertSame(['Baker', 'Baker'], $result->nodes);

        $flow = PayloadNarrowing::sankeyLinkAt($result->links, 0);
        self::assertSame(1, $flow->value, 'the child is counted once, not once per parent');
        self::assertCount(1, $flow->samples, 'the child surfaces a single sample, not a duplicate');
    }

    /**
     * A parent carrying several `1 OCCU` lines contributes only their FIRST
     * recorded trade — pairing every parent trade against the child would
     * inflate one succession into a cross-product. The fixture's
     * multi-occupation father lists Carpenter before Mayor, so Carpenter is the
     * source node and Mayor never appears.
     */
    #[Test]
    public function occupationInheritanceReadsOnlyThePrimaryParentOccupation(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        self::assertContains('Carpenter', $result->nodes);
        self::assertNotContains('Mayor', $result->nodes, 'only the first parent OCCU counts');
    }

    /**
     * Pairs where either side lacks an occupation, a child with no resolvable
     * parent and a child whose only recorded parent lacks a trade are all
     * dropped. None of their occupations leak into the node table.
     */
    #[Test]
    public function occupationInheritanceDropsIncompletePairs(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        // Tailor: child's trade, but his father has no occupation.
        // Miller: father's trade, but his child has none.
        // Baker:  child has no FAMC at all (no resolvable parent).
        // Cooper: child's only recorded parent is a mother without a trade.
        foreach (['Tailor', 'Miller', 'Baker', 'Cooper'] as $occupation) {
            self::assertNotContains($occupation, $result->nodes, $occupation . ' pair must be dropped');
        }
    }

    /**
     * Every link carries up to three sample children so the hover tooltip can
     * surface representative people behind the band. The Farmer → Farmer flow
     * has five contributing children precisely to exercise the cap: the sample
     * list holds exactly three distinct names drawn from the five, while the
     * flow value still reflects all five. WHICH three survive is pinned by the
     * repository's ORDER BY i_id, but the assertion stays order-agnostic so
     * renumbering the fixture xrefs would not cascade into noisy churn.
     */
    #[Test]
    public function occupationInheritanceAttachesSampleChildrenPerLink(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        $heaviest = PayloadNarrowing::sankeyLinkAt($result->links, 0);
        self::assertSame(5, $heaviest->value, 'flow weight reflects all 5 contributing children');
        self::assertCount(3, $heaviest->samples, 'sample list caps at SAMPLES_PER_FLOW=3');

        $names = array_map(
            static fn (SankeySample $sample): string => $sample->name,
            $heaviest->samples,
        );

        // Every surfaced sample must be one of the five known Farmer → Farmer
        // children — proves the cap picks from the right population.
        $candidates = ['Anton Farmer', 'Bernd Farmer', 'Carl Farmer', 'Dirk Farmer', 'Emil Upper'];

        foreach ($names as $name) {
            self::assertContains($name, $candidates, 'sample names must come from the fixture pool');
        }

        // No duplicates: every cap slot held by a distinct child.
        self::assertCount(3, array_unique($names), 'cap picks 3 distinct children');

        // Each sample carries its child xref so the tooltip could link to the
        // individual page.
        foreach ($heaviest->samples as $sample) {
            self::assertStringStartsWith('I', $sample->xref);
        }
    }

    /**
     * A small top-N limit keeps only the heaviest flow and drops the tail; node
     * entries referenced solely by dropped links disappear too.
     */
    #[Test]
    public function occupationInheritanceRespectsTheTopLinksLimit(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(1);

        self::assertCount(1, $result->links);
        self::assertSame(5, $result->links[0]->value);
        self::assertSame(['Farmer', 'Farmer'], $result->nodes);
    }

    /**
     * A person in the middle of a three-generation chain contributes to TWO
     * distinct flows: as the CHILD of their own parent (grandfather → father)
     * and as the PARENT of their child (father → son). The single-pass design
     * resolves each role independently, so the occupation surfaces once on the
     * target side of the upper-generation flow and once on the source side of
     * the lower-generation flow — guarding the dual-role behaviour against a
     * future refactor that might couple the two.
     */
    #[Test]
    public function occupationInheritanceCountsAPersonAsBothChildAndParentAcrossGenerations(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance-multigeneration.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        // Two flows: Smith → Carpenter (grandfather → father) and Carpenter →
        // Mason (father → son). Carpenter appears once per column.
        self::assertCount(2, $result->links);
        self::assertSame(['Smith', 'Carpenter', 'Carpenter', 'Mason'], $result->nodes);

        // Grandfather → father: the middle man is the child-sample here.
        $upperFlow = PayloadNarrowing::sankeyLinkAt($result->links, 0);
        self::assertSame(0, $upperFlow->source);
        self::assertSame(2, $upperFlow->target);
        self::assertSame('Vater Schmidt', PayloadNarrowing::sankeySampleAt($upperFlow->samples, 0)->name);

        // Father → son: the SAME middle man is now the source occupation, and
        // his son is the sample.
        $lowerFlow = PayloadNarrowing::sankeyLinkAt($result->links, 1);
        self::assertSame(1, $lowerFlow->source);
        self::assertSame(3, $lowerFlow->target);
        self::assertSame('Sohn Schmidt', PayloadNarrowing::sankeySampleAt($lowerFlow->samples, 0)->name);
    }

    /**
     * A tree of lineages that carry no occupation at all produces no flows —
     * every pair is dropped for want of a trade on both ends and the aggregator
     * returns its empty shape.
     */
    #[Test]
    public function occupationInheritanceReturnsEmptyWhenNoOccupationsRecorded(): void
    {
        $tree   = $this->importFixtureTree('father-son-name-passdown.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        self::assertSame([], $result->nodes);
        self::assertSame([], $result->links);
    }

    /**
     * Hover samples are resolved through the record factory, so a sample child
     * the current user cannot see is dropped and the next deterministic child
     * takes its slot — the private name never reaches the tooltip. The fixture
     * has one Blacksmith → Blacksmith flow with FOUR children in xref order
     * (Anton, Bernd, Carl, Dirk); Bernd carries `1 RESN confidential`. As the
     * importing admin all four are visible, so the cap of three holds the first
     * three (Anton, Bernd, Carl) — this proves Bernd sits inside the cap window,
     * making the visitor assertion a real discriminator rather than a
     * fixture-ordering artefact. As an anonymous visitor Bernd is skipped and
     * Dirk is promoted into the freed slot, while the flow weight stays at four.
     */
    #[Test]
    public function occupationInheritanceDropsSamplesTheUserCannotSeeAndPromotesTheNext(): void
    {
        $tree = $this->importFixtureTree('occupation-inheritance-privacy.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        // Control: as the importing admin every child is visible, so the
        // confidential Bernd occupies a cap slot.
        $adminNames = $this->blacksmithFlowSampleNames($tree);
        self::assertContains('Bernd Secret', $adminNames, 'the confidential sample sits inside the cap window for an admin');

        // Drop to an anonymous visitor so the `1 RESN confidential` marker
        // actually restricts visibility.
        Auth::logout();

        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);
        $heaviest = PayloadNarrowing::sankeyLinkAt($result->links, 0);

        // The flow weight still reflects all four children — dropping a private
        // sample must not distort the aggregate.
        self::assertSame(4, $heaviest->value, 'the flow weight is counted independently of the samples');
        self::assertCount(3, $heaviest->samples, 'the cap still holds three samples after the private one is skipped');

        $names = array_map(static fn (SankeySample $sample): string => $sample->name, $heaviest->samples);

        self::assertNotContains('Bernd Secret', $names, 'a sample the visitor cannot see must not leak into the tooltip');
        self::assertContains('Dirk Public', $names, 'the next deterministic child is promoted into the freed slot');
        self::assertSame(['Anton Public', 'Carl Public', 'Dirk Public'], $names);
    }

    /**
     * A flow whose every contributing child is hidden from the current user
     * keeps its full weight but surfaces no sample names — the weight is counted
     * from the child scan, independent of whether any representative can be
     * shown. The fixture's Tailor → Tailor flow has two children, both `1 RESN
     * confidential`, so an anonymous visitor sees a weight of two with an empty
     * sample list rather than a leaked name or a dropped flow.
     */
    #[Test]
    public function occupationInheritanceKeepsTheWeightButOmitsSamplesWhenEveryContributorIsPrivate(): void
    {
        $tree = $this->importFixtureTree('occupation-inheritance-privacy.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        Auth::logout();

        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        $tailorToTailor = null;

        foreach ($result->links as $link) {
            $targetNode = $result->nodes[$link->target] ?? self::fail('link target index missing from the node table');

            if ($targetNode === 'Tailor') {
                $tailorToTailor = $link;

                break;
            }
        }

        self::assertNotNull($tailorToTailor, 'the all-private flow is still present');
        self::assertSame(2, $tailorToTailor->value, 'the weight counts both private contributors');
        self::assertSame([], $tailorToTailor->samples, 'no private name leaks into the sample list');
    }

    /**
     * Resolve the Blacksmith → Blacksmith flow's sample names for the current
     * user. Helper for the privacy discriminator above so the admin control and
     * the visitor assertion read the same flow the same way.
     *
     * @return list<string>
     */
    private function blacksmithFlowSampleNames(Tree $tree): array
    {
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        return array_map(
            static fn (SankeySample $sample): string => $sample->name,
            PayloadNarrowing::sankeyLinkAt($result->links, 0)->samples,
        );
    }

    /**
     * Reduce a payload to a list of `[sourceLabel, targetLabel]` pairs so a test
     * can assert a flow exists by its human-readable trades without pinning the
     * encounter-order node indices.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function flowLabels(SankeyFlowsPayload $payload): array
    {
        $flows = [];

        foreach ($payload->links as $link) {
            $source = $payload->nodes[$link->source] ?? self::fail('link source index missing from the node table');
            $target = $payload->nodes[$link->target] ?? self::fail('link target index missing from the node table');

            $flows[] = [$source, $target];
        }

        return $flows;
    }
}
