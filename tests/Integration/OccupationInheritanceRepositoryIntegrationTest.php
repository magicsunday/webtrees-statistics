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
use MagicSunday\Webtrees\Statistic\Normalization\NormalizedOccupation;
use MagicSunday\Webtrees\Statistic\Normalization\OccupationFolding;
use MagicSunday\Webtrees\Statistic\Normalization\RawOccupationNormalizer;
use MagicSunday\Webtrees\Statistic\Normalization\Support\StringList;
use MagicSunday\Webtrees\Statistic\Repository\OccupationInheritanceRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RecordName;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Sankey\BipartiteSankeyAssembler;
use MagicSunday\Webtrees\Statistic\Support\Sankey\SankeySampleResolver;
use MagicSunday\Webtrees\Statistic\Test\Support\Narrowing\PayloadNarrowing;
use MagicSunday\Webtrees\Statistic\Test\Support\Normalization\StubOccupationNormalizer;
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
 * (every trade counts, so the secondary Mayor → Carpenter flow surfaces), a
 * father → daughter flow and a mother → daughter flow (both parents are
 * considered, regardless of sex), a child of two working parents that feeds two
 * distinct flows, and four kinds of pair that must be dropped — a parent without
 * an occupation, a child without an occupation, a child with no resolvable
 * parent, and a child whose only recorded parent lacks a trade. A dedicated
 * fixture exercises the full parent × child cross-product when both sides carry
 * several trades.
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
#[UsesClass(RawOccupationNormalizer::class)]
#[UsesClass(NormalizedOccupation::class)]
#[UsesClass(OccupationFolding::class)]
#[UsesClass(StubOccupationNormalizer::class)]
#[UsesClass(StringList::class)]
final class OccupationInheritanceRepositoryIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * Aggregates the fixture into the bipartite Sankey payload. Eight flows
     * survive: Farmer → Farmer (×5, four direct children plus the case-folded
     * `farmer`/`FARMER` pair), Carpenter → Carpenter (×1), Mayor → Carpenter
     * (×1, the multi-occupation father's secondary trade), Weaver → Weaver (×1,
     * a father → daughter), Seamstress → Seamstress (×1, a mother → daughter),
     * Mason → Glazier plus Potter → Glazier (×1 each, the two trades of a child's
     * working father and mother) and Blacksmith → Farmer (×1). Every dropped pair
     * stays out of the result.
     */
    #[Test]
    public function occupationInheritanceReturnsTheExpectedAggregation(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

        // Eight distinct flows survive.
        self::assertCount(8, $result->links);

        // Source column then target column, each in encounter order over the
        // weight-sorted flows. Equal-weight flows keep their insertion order,
        // which follows the lexicographic xref scan (I1, I10, …, I2, …, I9): the
        // multi-occupation father I10 is scanned via his son I11 first, so
        // Carpenter then Mayor lead the weight-1 sources, and the single
        // Blacksmith → Farmer pair (child I9) lands last.
        self::assertSame(
            [
                'Farmer', 'Carpenter', 'Mayor', 'Weaver', 'Seamstress', 'Mason', 'Potter', 'Blacksmith',
                'Farmer', 'Carpenter', 'Weaver', 'Seamstress', 'Glazier',
            ],
            $result->nodes,
        );

        // Heaviest flow leads — Farmer (source idx 0) → Farmer (target idx 0,
        // shifted by sourceColumnSize=8 to absolute idx 8). Its weight of 5
        // proves the case-folded `farmer` / `FARMER` pair merged into the four
        // direct Farmer → Farmer children.
        $heaviest = $result->links[0];
        self::assertSame(0, $heaviest->source);
        self::assertSame(8, $heaviest->target);
        self::assertSame(5, $heaviest->value);

        // The seven remaining flows weigh 1 each.
        $tailValues = array_map(
            static fn (SankeyLink $link): int => $link->value,
            array_slice($result->links, 1),
        );
        self::assertSame([1, 1, 1, 1, 1, 1, 1], $tailValues);

        // Every link respects the bipartite invariant: source index in the
        // source column [0, 8), target index in the target column [8, 13).
        foreach ($result->links as $link) {
            self::assertLessThan(8, $link->source, 'source index must be in the source column');
            self::assertGreaterThanOrEqual(8, $link->target, 'target index must be in the target column');
        }
    }

    /**
     * When a standardization provider is present, spelling and language variants
     * of one trade fold into a single flow. The fixture records two separate
     * father → son Arzt lines, one spelled `Arzt` and one `Doctor`: with the
     * identity default they stay two distinct weight-1 flows, but a provider that
     * maps both spellings to the same grouping key collapses them into one
     * `Arzt → Arzt` flow of weight 2. Asserting both halves pins the merge as the
     * behavioural difference the normalizer makes — a regression that ignored the
     * provider would leave two flows and fail the second assertion.
     */
    #[Test]
    public function occupationInheritanceMergesLanguageVariantsThroughTheNormalizer(): void
    {
        $tree = $this->importFixtureTree('occupation-inheritance-language-variants.ged');

        // Identity default: the two spellings never merge.
        $rawResult = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

        self::assertSame(
            [['Arzt', 'Arzt'], ['Doctor', 'Doctor']],
            $this->flowLabels($rawResult),
            'without a provider the two spellings stay separate flows',
        );

        // A provider that folds `Arzt` and `Doctor` onto one trade merges the two
        // father → son flows into a single weight-2 flow.
        $arzt       = new NormalizedOccupation('de:Arzt', 'Arzt');
        $normalizer = new StubOccupationNormalizer([
            'Arzt'   => $arzt,
            'Doctor' => $arzt,
        ]);

        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), $normalizer))
            ->occupationInheritance(10, 1);

        self::assertCount(1, $result->links, 'the two variant flows collapse into one');
        self::assertSame(['Arzt', 'Arzt'], $result->nodes, 'the merged flow shows the provider display label');
        self::assertSame(2, $result->links[0]->value, 'the merged flow carries the combined weight');
        self::assertSame(1, $normalizer->batchCalls(), 'the whole distinct trade set is resolved in one batch');
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
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

        $flows = $this->flowLabels($result);

        self::assertContains(['Weaver', 'Weaver'], $flows, 'a father → daughter trade must be counted');
        self::assertContains(['Seamstress', 'Seamstress'], $flows, 'a mother → daughter trade must be counted');
    }

    /**
     * A child whose father and mother both carry a (different) trade feeds TWO
     * distinct flows — one per parent occupation. The fixture's Nina has a Mason
     * father and a Potter mother and is herself a Glazier, so both Mason →
     * Glazier and Potter → Glazier surface, each weighing one.
     *
     * The SAME child is the sample on both flows: the repository resolves a
     * child through the privacy layer once and reuses the memoised sample across
     * its flows. Pinning Nina in BOTH sample lists guards that reuse — a
     * regression that dropped the cached sample from the second flow (or scoped
     * the memo wrongly) would leave the labels, weights and link count unchanged
     * and otherwise slip through green.
     */
    #[Test]
    public function occupationInheritanceCountsBothParentsOfOneChild(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

        $flows = $this->flowLabels($result);

        self::assertContains(['Mason', 'Glazier'], $flows, 'the father trade of a two-parent child must be counted');
        self::assertContains(['Potter', 'Glazier'], $flows, 'the mother trade of a two-parent child must be counted');

        // The memoised child sample must surface in BOTH of Nina's flows.
        self::assertSame(
            ['Nina Both'],
            $this->sampleNamesForFlow($result, 'Mason', 'Glazier'),
            'the resolved child sample surfaces in the father flow',
        );
        self::assertSame(
            ['Nina Both'],
            $this->sampleNamesForFlow($result, 'Potter', 'Glazier'),
            'the same memoised child sample is reused in the mother flow',
        );
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
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

        self::assertCount(1, $result->links, 'two same-trade parents feed one flow, not two');
        self::assertSame(['Baker', 'Baker'], $result->nodes);

        $flow = PayloadNarrowing::sankeyLinkAt($result->links, 0);
        self::assertSame(1, $flow->value, 'the child is counted once, not once per parent');
        self::assertCount(1, $flow->samples, 'the child surfaces a single sample, not a duplicate');
    }

    /**
     * A parent carrying several `1 OCCU` lines contributes EVERY recorded trade,
     * not just the first: each parent occupation is paired against the child's
     * occupation. The fixture's multi-occupation father lists Carpenter before
     * Mayor and his son is a Carpenter, so both Carpenter → Carpenter and
     * Mayor → Carpenter surface — the secondary trade is no longer dropped.
     */
    #[Test]
    public function occupationInheritanceReadsEveryParentOccupation(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

        $flows = $this->flowLabels($result);

        self::assertContains(['Carpenter', 'Carpenter'], $flows, 'the primary parent trade is counted');
        self::assertContains(['Mayor', 'Carpenter'], $flows, 'the secondary parent trade is counted too');
    }

    /**
     * A parent and a child that each record several `1 OCCU` lines produce the
     * full cross-product of (parent trade → child trade): every parent
     * occupation is paired against every child occupation, so a secondary trade
     * surfaces both as a source and as a target. The fixture's father and son
     * are each a Farmer AND a Carter, yielding all four combinations —
     * Farmer → Farmer, Farmer → Carter, Carter → Farmer and Carter → Carter —
     * each weighing one. The former first-OCCU-only behaviour produced only the
     * single Farmer → Farmer flow.
     */
    #[Test]
    public function occupationInheritanceCountsTheFullCrossProductOfMultipleOccupations(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance-multi-occupation.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

        self::assertCount(4, $result->links, 'two 2-trade people yield the 2×2 cross-product');

        $flows = $this->flowLabels($result);

        self::assertContains(['Farmer', 'Farmer'], $flows);
        self::assertContains(['Farmer', 'Carter'], $flows);
        self::assertContains(['Carter', 'Farmer'], $flows);
        self::assertContains(['Carter', 'Carter'], $flows);

        foreach ($result->links as $link) {
            self::assertSame(1, $link->value, 'each distinct cross-product flow is counted once');
        }
    }

    /**
     * A trade repeated across several of a person's own `1 OCCU` lines counts as
     * one distinct trade, not once per duplicate line: the repository folds each
     * person's occupations to their distinct trades before pairing, so neither
     * the flow count nor a flow's weight inflates. The fixture's father records
     * Farmer three times plus Carter, and his son records Farmer twice; the
     * result is exactly two flows — Farmer → Farmer and Carter → Farmer — each
     * weighing one, never Farmer → Farmer ×3 or a duplicate band.
     */
    #[Test]
    public function occupationInheritanceFoldsRepeatedOccupationLinesToDistinctTrades(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance-duplicate-occupation.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

        self::assertCount(2, $result->links, 'repeated occupation lines must not create duplicate flows');
        self::assertSame(
            [['Farmer', 'Farmer'], ['Carter', 'Farmer']],
            $this->flowLabels($result),
        );

        foreach ($result->links as $link) {
            self::assertSame(1, $link->value, 'a repeated trade must not inflate the flow weight');
        }
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
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

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
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

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
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(1, 1);

        self::assertCount(1, $result->links);
        self::assertSame(5, $result->links[0]->value);
        self::assertSame(['Farmer', 'Farmer'], $result->nodes);
    }

    /**
     * The Overview display policy keeps only recurring inheritance patterns. The
     * curated fixture has one recurring flow (Farmer → Farmer ×5) and seven
     * one-off (weight-1) flows; called with its default policy the aggregator
     * drops every one-off, so exactly the recurring flow survives. This data
     * floor lets the shown-flow count adapt to a tree's density instead of a
     * hard-coded cap.
     */
    #[Test]
    public function occupationInheritanceDropsOneOffFlowsBelowTheMinimumWeight(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance();

        self::assertCount(1, $result->links, 'only the recurring flow survives the min-weight floor');
        self::assertSame(['Farmer', 'Farmer'], $result->nodes);
        self::assertSame(5, $result->links[0]->value);
    }

    /**
     * A tree whose every occupation flow occurs exactly once carries no
     * recurring inheritance pattern, so the Overview policy returns an
     * honestly empty diagram rather than a wall of one-off noise. The
     * multi-occupation fixture yields four weight-1 cross-product flows, all of
     * which fall below the minimum weight.
     */
    #[Test]
    public function occupationInheritanceReturnsEmptyWhenNoFlowRecursUnderTheDisplayPolicy(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance-multi-occupation.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance();

        self::assertSame([], $result->nodes);
        self::assertSame([], $result->links);
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
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

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
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

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

        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);
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

        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

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
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new RawOccupationNormalizer()))
            ->occupationInheritance(10, 1);

        return array_map(
            static fn (SankeySample $sample): string => $sample->name,
            PayloadNarrowing::sankeyLinkAt($result->links, 0)->samples,
        );
    }

    /**
     * Resolve the sample names attached to the flow whose source and target
     * trades match the given labels. Fails the test when no such flow exists so
     * a typo in the expected trade cannot pass as an empty sample list.
     *
     * @param SankeyFlowsPayload $payload     The aggregated payload to search
     * @param string             $sourceTrade The expected source-node label
     * @param string             $targetTrade The expected target-node label
     *
     * @return list<string>
     */
    private function sampleNamesForFlow(SankeyFlowsPayload $payload, string $sourceTrade, string $targetTrade): array
    {
        foreach ($payload->links as $link) {
            $source = $payload->nodes[$link->source] ?? self::fail('link source index missing from the node table');
            $target = $payload->nodes[$link->target] ?? self::fail('link target index missing from the node table');

            if (($source === $sourceTrade) && ($target === $targetTrade)) {
                return array_map(
                    static fn (SankeySample $sample): string => $sample->name,
                    $link->samples,
                );
            }
        }

        self::fail($sourceTrade . ' → ' . $targetTrade . ' flow missing');
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
