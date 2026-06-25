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
use function array_unique;

/**
 * End-to-end test of the father → son occupation-inheritance aggregator. The
 * curated fixture exercises every data-layer behaviour that matters: a trade
 * passed down to four sons (cap on samples), a case-folded variant that merges
 * into that flow, a changed trade, a father with several occupations (only the
 * first counts), and four kinds of pair that must be dropped — father without
 * an occupation, son without an occupation, a daughter, a son with no
 * resolvable father, and a son whose family records only a mother.
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
     * Aggregates the fixture into the bipartite Sankey payload. Three flows
     * survive: Farmer → Farmer (×5, four direct sons plus the case-folded
     * `farmer`/`FARMER` pair), Carpenter → Carpenter (×1) and Blacksmith →
     * Farmer (×1). Every dropped pair — father or son missing an occupation,
     * the daughter, the fatherless son, and the mother-only family — stays out
     * of the result.
     */
    #[Test]
    public function occupationInheritanceReturnsTheExpectedAggregation(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        // Three distinct flows survive.
        self::assertCount(3, $result->links);

        // Source column [Farmer, Carpenter, Blacksmith] then target
        // column [Farmer, Carpenter] in encounter order.
        self::assertSame(
            ['Farmer', 'Carpenter', 'Blacksmith', 'Farmer', 'Carpenter'],
            $result->nodes,
        );

        // Heaviest flow leads — Farmer (source idx 0) → Farmer (target
        // idx 0, shifted by sourceColumnSize=3 to absolute idx 3). Its
        // weight of 5 proves the case-folded `farmer` / `FARMER` pair
        // merged into the four direct Farmer → Farmer sons.
        $heaviest = $result->links[0];
        self::assertSame(0, $heaviest->source);
        self::assertSame(3, $heaviest->target);
        self::assertSame(5, $heaviest->value);

        // The two remaining flows weigh 1 each.
        $tailValues = array_map(
            static fn (SankeyLink $link): int => $link->value,
            [$result->links[1], $result->links[2]],
        );
        self::assertSame([1, 1], $tailValues);

        // Every link respects the bipartite invariant: source index in
        // the source column [0, 3), target index in the target column
        // [3, 5).
        foreach ($result->links as $link) {
            self::assertLessThan(3, $link->source, 'source index must be in the source column');
            self::assertGreaterThanOrEqual(3, $link->target, 'target index must be in the target column');
        }
    }

    /**
     * A father carrying several `1 OCCU` lines contributes only his FIRST
     * recorded trade — pairing every father trade against the son would inflate
     * one succession into a cross-product. The fixture's multi-occupation
     * father lists Carpenter before Mayor, so Carpenter is the source node and
     * Mayor never appears.
     */
    #[Test]
    public function occupationInheritanceReadsOnlyThePrimaryFatherOccupation(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        self::assertContains('Carpenter', $result->nodes);
        self::assertNotContains('Mayor', $result->nodes, 'only the first father OCCU counts');
    }

    /**
     * Pairs where either side lacks an occupation, a daughter, a son with no
     * resolvable father and a son whose family records only a mother are all
     * dropped. None of their occupations leak into the node table.
     */
    #[Test]
    public function occupationInheritanceDropsIncompleteAndNonSonPairs(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        // Tailor: son's trade, but his father has no occupation.
        // Miller: father's trade, but his son has none.
        // Weaver: only carried by a daughter.
        // Baker:  son has no FAMC at all (no resolvable father).
        // Cooper: son's family records only a mother (no husband).
        foreach (['Tailor', 'Miller', 'Weaver', 'Baker', 'Cooper'] as $occupation) {
            self::assertNotContains($occupation, $result->nodes, $occupation . ' pair must be dropped');
        }
    }

    /**
     * Every link carries up to three sample sons so the hover tooltip can
     * surface representative people behind the band. The Farmer → Farmer flow
     * has five contributing sons precisely to exercise the cap: the sample list
     * holds exactly three distinct names drawn from the five, while the flow
     * value still reflects all five. WHICH three survive is pinned by the
     * repository's ORDER BY i_id, but the assertion stays order-agnostic so
     * renumbering the fixture xrefs would not cascade into noisy churn.
     */
    #[Test]
    public function occupationInheritanceAttachesSampleSonsPerLink(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        $heaviest = PayloadNarrowing::sankeyLinkAt($result->links, 0);
        self::assertSame(5, $heaviest->value, 'flow weight reflects all 5 contributing sons');
        self::assertCount(3, $heaviest->samples, 'sample list caps at SAMPLES_PER_FLOW=3');

        $names = array_map(
            static fn (SankeySample $sample): string => $sample->name,
            $heaviest->samples,
        );

        // Every surfaced sample must be one of the five known Farmer →
        // Farmer sons — proves the cap picks from the right population.
        $candidates = ['Anton Farmer', 'Bernd Farmer', 'Carl Farmer', 'Dirk Farmer', 'Emil Upper'];

        foreach ($names as $name) {
            self::assertContains($name, $candidates, 'sample names must come from the fixture pool');
        }

        // No duplicates: every cap slot held by a distinct son.
        self::assertCount(3, array_unique($names), 'cap picks 3 distinct sons');

        // Each sample carries its son xref so the tooltip could link to
        // the individual page.
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
     * A man in the middle of a three-generation chain contributes to TWO
     * distinct flows: as the SON of his own father (grandfather → father) and
     * as the FATHER of his son (father → son). The single-pass design resolves
     * each role independently, so his occupation surfaces once on the target
     * side of the upper-generation flow and once on the source side of the
     * lower-generation flow — guarding the dual-role behaviour against a future
     * refactor that might couple the two.
     */
    #[Test]
    public function occupationInheritanceCountsAManAsBothSonAndFatherAcrossGenerations(): void
    {
        $tree   = $this->importFixtureTree('occupation-inheritance-multigeneration.ged');
        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);

        // Two flows: Smith → Carpenter (grandfather → father) and Carpenter →
        // Mason (father → son). Carpenter appears once per column.
        self::assertCount(2, $result->links);
        self::assertSame(['Smith', 'Carpenter', 'Carpenter', 'Mason'], $result->nodes);

        // Grandfather → father: the middle man is the son-sample here.
        $upperFlow = PayloadNarrowing::sankeyLinkAt($result->links, 0);
        self::assertSame(0, $upperFlow->source);
        self::assertSame(2, $upperFlow->target);
        self::assertSame('Vater Schmidt', PayloadNarrowing::sankeySampleAt($upperFlow->samples, 0)->name);

        // Father → son: the SAME middle man is now the source occupation,
        // and his son is the sample.
        $lowerFlow = PayloadNarrowing::sankeyLinkAt($result->links, 1);
        self::assertSame(1, $lowerFlow->source);
        self::assertSame(3, $lowerFlow->target);
        self::assertSame('Sohn Schmidt', PayloadNarrowing::sankeySampleAt($lowerFlow->samples, 0)->name);
    }

    /**
     * A tree of father → son lineages that carry no occupation at all produces
     * no flows — every pair is dropped for want of a trade on both ends and the
     * aggregator returns its empty shape.
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
     * Hover samples are resolved through the record factory, so a sample son
     * the current user cannot see is dropped and the next deterministic son
     * takes its slot — the private name never reaches the tooltip. The fixture
     * has one Blacksmith → Blacksmith flow with FOUR sons in xref order (Anton,
     * Bernd, Carl, Dirk); Bernd carries `1 RESN confidential`. As the importing
     * admin all four are visible, so the cap of three holds the first three
     * (Anton, Bernd, Carl) — this proves Bernd sits inside the cap window,
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

        // Control: as the importing admin every son is visible, so the
        // confidential Bernd occupies a cap slot.
        $adminNames = $this->blacksmithFlowSampleNames($tree);
        self::assertContains('Bernd Secret', $adminNames, 'the confidential sample sits inside the cap window for an admin');

        // Drop to an anonymous visitor so the `1 RESN confidential` marker
        // actually restricts visibility.
        Auth::logout();

        $result = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree)))
            ->occupationInheritance(10);
        $heaviest = PayloadNarrowing::sankeyLinkAt($result->links, 0);

        // The flow weight still reflects all four sons — dropping a private
        // sample must not distort the aggregate.
        self::assertSame(4, $heaviest->value, 'the flow weight is counted independently of the samples');
        self::assertCount(3, $heaviest->samples, 'the cap still holds three samples after the private one is skipped');

        $names = array_map(static fn (SankeySample $sample): string => $sample->name, $heaviest->samples);

        self::assertNotContains('Bernd Secret', $names, 'a sample the visitor cannot see must not leak into the tooltip');
        self::assertContains('Dirk Public', $names, 'the next deterministic son is promoted into the freed slot');
        self::assertSame(['Anton Public', 'Carl Public', 'Dirk Public'], $names);
    }

    /**
     * A flow whose every contributing son is hidden from the current user keeps
     * its full weight but surfaces no sample names — the weight is counted from
     * the son scan, independent of whether any representative can be shown. The
     * fixture's Tailor → Tailor flow has two sons, both `1 RESN confidential`,
     * so an anonymous visitor sees a weight of two with an empty sample list
     * rather than a leaked name or a dropped flow.
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
}
