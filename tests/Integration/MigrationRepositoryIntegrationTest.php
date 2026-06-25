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
use MagicSunday\Webtrees\Statistic\Repository\MigrationRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RecordName;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\IsoCountryMap;
use MagicSunday\Webtrees\Statistic\Support\Sankey\BipartiteSankeyAssembler;
use MagicSunday\Webtrees\Statistic\Support\Sankey\SankeySampleResolver;
use MagicSunday\Webtrees\Statistic\Test\Support\Narrowing\PayloadNarrowing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_count_values;
use function array_map;
use function array_slice;
use function array_unique;

/**
 * End-to-end test of the birth → death country flow aggregator. The curated
 * fixture covers the four behaviours that matter at the data layer: a country
 * pair with four individuals (Germany → United States, used to exercise the
 * SAMPLES_PER_FLOW cap), a one-off pair (Vienna → Paris), a same-country
 * trajectory that must be dropped (Austria → Austria), and individuals missing
 * either BIRT or DEAT place (must be silently skipped).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(MigrationRepository::class)]
#[UsesClass(SankeyFlowsPayload::class)]
#[UsesClass(SankeyLink::class)]
#[UsesClass(SankeySample::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(GedcomScanner::class)]
#[UsesClass(RowCast::class)]
#[UsesClass(BipartiteSankeyAssembler::class)]
#[UsesClass(IsoCountryMap::class)]
#[UsesClass(SankeySampleResolver::class)]
#[UsesClass(RecordName::class)]
final class MigrationRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Reset the IsoCountryMap lookup-map cache before each test so the
     * locale-seeded country resolution stays deterministic.
     */
    protected function setUp(): void
    {
        parent::setUp();

        IsoCountryMap::clearCache();
    }

    /**
     * Aggregates the fixture into the bipartite Sankey payload: the Germany →
     * United States flow leads with weight 4, Germany → United Kingdom, Austria →
     * France, and Germany → Canada each weigh 1. The same-country Vienna→Vienna
     * individual and the two with a missing BIRT or DEAT place do not contribute.
     * Source and target sides occupy disjoint node-index ranges.
     */
    #[Test]
    public function flowsByCountryReturnsTheExpectedAggregation(): void
    {
        $tree   = $this->importFixtureTree('migration-flows.ged');
        $result = (new MigrationRepository($tree, new IsoCountryMap()))->flowsByCountry(10);

        // Four flows survive: Germany→United States (×4), Germany→United Kingdom
        // (×1), Austria→France (×1), Germany→Canada (×1).
        self::assertCount(4, $result->links);

        // Two source nodes (Germany, Austria) then four target nodes
        // (United States, United Kingdom, France, Canada) in insertion order;
        // the raw "USA"/"England" place segments resolve to their ISO-canonical
        // labels.
        self::assertSame(
            ['Germany', 'Austria', 'United States', 'United Kingdom', 'France', 'Canada'],
            $result->nodes,
        );

        // Heaviest flow leads — Germany (source idx 0) → United States (target
        // idx 0, shifted by sourceColumnSize=2 to absolute idx 2).
        $heaviest = $result->links[0];
        self::assertSame(0, $heaviest->source);
        self::assertSame(2, $heaviest->target);
        self::assertSame(4, $heaviest->value);
        self::assertNotEmpty($heaviest->samples);

        // The remaining three flows are weighted 1.
        $remainingValues = array_map(static fn (SankeyLink $link): int => $link->value, array_slice($result->links, 1));
        self::assertSame([1, 1, 1], $remainingValues);

        // Every link respects the bipartite invariant: source index is
        // in the source column [0, 2), target index is in the target
        // column [2, 6).
        foreach ($result->links as $link) {
            self::assertLessThan(2, $link->source, 'source index must be in the source column');
            self::assertGreaterThanOrEqual(2, $link->target, 'target index must be in the target column');
        }
    }

    /**
     * A small top-N limit drops the tail of the link table while still
     * surfacing the heaviest flow. Nodes referenced only by dropped links also
     * disappear from the node table.
     */
    #[Test]
    public function flowsByCountryRespectsTheTopLinksLimit(): void
    {
        $tree   = $this->importFixtureTree('migration-flows.ged');
        $result = (new MigrationRepository($tree, new IsoCountryMap()))->flowsByCountry(1);

        self::assertCount(1, $result->links);
        self::assertSame(4, $result->links[0]->value);

        // With only the Germany → United States flow surviving, the node table
        // shrinks to one source and one target.
        self::assertCount(2, $result->nodes);
        self::assertSame('Germany', $result->nodes[0]);
        self::assertSame('United States', $result->nodes[1]);
    }

    /**
     * A tree where every individual was born and died in the same country
     * produces no flows — the same-country guard drops every contribution and
     * the aggregator returns its empty shape. The fixture also pairs locale and
     * spelling variants of one country (born "…, Germany" / died "…, Deutschland",
     * and "…, England" / "…, Great Britain"): these must resolve to the same
     * ISO-canonical country and therefore raise no false migration flow.
     */
    #[Test]
    public function flowsByCountryReturnsEmptyWhenEveryTrajectoryIsSameCountry(): void
    {
        $tree   = $this->importFixtureTree('migration-same-country.ged');
        $result = (new MigrationRepository($tree, new IsoCountryMap()))->flowsByCountry(10);

        self::assertSame([], $result->nodes);
        self::assertSame([], $result->links);
    }

    /**
     * Every link carries up to three sample individuals so the hover tooltip
     * can surface representative names per flow. Issue #12 spelled this out as
     * an acceptance criterion. Samples are name + xref pairs; the names come
     * straight from the GEDCOM 1 NAME line with the slashes stripped.
     *
     * The fixture has FOUR Germany→United States contributors (Anna, Berta, Carl, Dieter
     * Test) precisely to exercise the cap: the sample list must hold exactly
     * three distinct names drawn from the four candidates and the underlying
     * flow value must still reflect all four contributors. WHICH three survive
     * is pinned by the repository's ORDER BY i_id, but the test stays
     * order-agnostic so renumbering the fixture xrefs would not cascade into
     * noisy churn.
     */
    #[Test]
    public function flowsByCountryAttachesSampleIndividualsPerLink(): void
    {
        $tree   = $this->importFixtureTree('migration-flows.ged');
        $result = (new MigrationRepository($tree, new IsoCountryMap()))->flowsByCountry(10);

        $heaviest = PayloadNarrowing::sankeyLinkAt($result->links, 0);
        // Germany → United States carries 4 contributors; samples cap at 3.
        self::assertSame(4, $heaviest->value, 'flow weight reflects all 4 contributors');
        self::assertCount(3, $heaviest->samples, 'sample list caps at SAMPLES_PER_FLOW=3');

        $names = array_map(
            static fn (SankeySample $sample): string => $sample->name,
            $heaviest->samples,
        );
        // Every surfaced sample must be one of the four known Germany→United States
        // contributors — proves the cap picks from the right population.
        $candidates = ['Anna Test', 'Berta Test', 'Carl Test', 'Dieter Test'];

        foreach ($names as $name) {
            self::assertContains($name, $candidates, 'sample names must come from the fixture pool');
        }

        // No duplicates: every cap slot held by a distinct individual.
        self::assertCount(3, array_unique($names), 'cap picks 3 distinct individuals');

        // Every sample also carries its source xref so the tooltip
        // could link to the individual page if the consumer wants.
        foreach ($heaviest->samples as $sample) {
            self::assertStringStartsWith('I', $sample->xref);
        }

        // The thin Germany → Canada flow has exactly one contributor;
        // proves the sample list size scales with the flow weight, not
        // a fixed length.
        $canada = null;

        foreach ($result->links as $link) {
            $targetNode = $result->nodes[$link->target] ?? self::fail('link target index missing from the node table');

            if (($link->value === 1)
                && ($targetNode === 'Canada')
            ) {
                $canada = $link;

                break;
            }
        }

        self::assertNotNull($canada);
        self::assertCount(1, $canada->samples);
    }

    /**
     * A contributor whose `1 NAME` line yields nothing after the slash strip
     * (e.g. `1 NAME / /` — empty given AND empty surname) still surfaces a
     * meaningful placeholder rather than an empty entry that visually
     * disappears. Because the sample is now resolved through the record
     * factory, the placeholder is webtrees' own unknown-name rendering (the
     * "Unknown given name" / "Unknown surname" ellipsis) instead of a
     * module-local string — so the tooltip stays consistent with how the rest
     * of the UI names a person with no recorded name.
     */
    #[Test]
    public function flowsByCountryFallsBackToPlaceholderForBlankNames(): void
    {
        $tree   = $this->importFixtureTree('migration-noname.ged');
        $result = (new MigrationRepository($tree, new IsoCountryMap()))->flowsByCountry(10);

        self::assertCount(1, $result->links);
        self::assertCount(1, $result->links[0]->samples);

        // webtrees renders an empty name as its translated unknown-name
        // placeholder; en-US resolves both the unknown given name and the
        // unknown surname to a horizontal ellipsis.
        self::assertSame('…', $result->links[0]->samples[0]->name);
    }

    /**
     * A country that appears both as an origin AND as a destination shows up as
     * two distinct nodes — one in the source column, one in the target column.
     * Without this split d3-sankey would throw a "circular link" error on the
     * bidirectional flow.
     */
    #[Test]
    public function flowsByCountryKeepsBidirectionalCountriesOnDisjointSides(): void
    {
        $tree   = $this->importFixtureTree('migration-bidirectional.ged');
        $result = (new MigrationRepository($tree, new IsoCountryMap()))->flowsByCountry(10);

        // Two flows: Germany → United States (×2) and United States → Germany
        // (×1). Both countries appear on both sides.
        self::assertCount(2, $result->links);

        $names = $result->nodes;

        // Germany and United States each appear twice: once on the source side,
        // once on the target side.
        $nodeCounts = array_count_values($names);
        PayloadNarrowing::assertValueAt(2, $nodeCounts, 'Germany');
        PayloadNarrowing::assertValueAt(2, $nodeCounts, 'United States');

        // No link can reference a source-index that equals its
        // target-index — would indicate a fold-back.
        foreach ($result->links as $link) {
            self::assertNotSame($link->source, $link->target);
        }
    }

    /**
     * Hover samples are resolved through the record factory, so a sample whose
     * individual the current user cannot see is dropped and the next
     * deterministic contributor takes its slot — the private name never reaches
     * the tooltip. The fixture has FOUR Germany → United States contributors in
     * xref order (Anton, Berta, Carl, Dieter); Berta carries `1 RESN
     * confidential`. As the importing admin all four are visible, so the cap of
     * three holds the first three (Anton, Berta, Carl) — this proves Berta sits
     * inside the cap window, making the visitor assertion a real discriminator
     * rather than a fixture-ordering artefact. As an anonymous visitor Berta is
     * skipped and Dieter is promoted into the freed slot, while the flow weight
     * stays at four because the weight is counted independently of the samples.
     */
    #[Test]
    public function flowsByCountryDropsSamplesTheUserCannotSeeAndPromotesTheNext(): void
    {
        $tree = $this->importFixtureTree('migration-flows-privacy.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        // Control: as the importing admin every contributor is visible, so the
        // confidential Berta occupies a cap slot.
        $adminNames = $this->germanyToUnitedStatesSampleNames($tree);
        self::assertContains('Berta Secret', $adminNames, 'the confidential sample sits inside the cap window for an admin');

        // Drop to an anonymous visitor so the `1 RESN confidential` marker
        // actually restricts visibility.
        Auth::logout();

        $result   = (new MigrationRepository($tree, new IsoCountryMap()))->flowsByCountry(10);
        $heaviest = PayloadNarrowing::sankeyLinkAt($result->links, 0);

        // The flow weight still reflects all four contributors — dropping a
        // private sample must not distort the aggregate.
        self::assertSame(4, $heaviest->value, 'the flow weight is counted independently of the samples');
        self::assertCount(3, $heaviest->samples, 'the cap still holds three samples after the private one is skipped');

        $names = array_map(static fn (SankeySample $sample): string => $sample->name, $heaviest->samples);

        self::assertNotContains('Berta Secret', $names, 'a sample the visitor cannot see must not leak into the tooltip');
        self::assertContains('Dieter Public', $names, 'the next deterministic contributor is promoted into the freed slot');
        self::assertSame(['Anton Public', 'Carl Public', 'Dieter Public'], $names);
    }

    /**
     * A flow whose every contributor is hidden from the current user keeps its
     * full weight but surfaces no sample names — the weight is counted from the
     * raw row scan, independent of whether any representative can be shown. The
     * fixture's Austria → France flow has two contributors, both `1 RESN
     * confidential`, so an anonymous visitor sees a weight of two with an empty
     * sample list rather than a leaked name or a dropped flow.
     */
    #[Test]
    public function flowsByCountryKeepsTheWeightButOmitsSamplesWhenEveryContributorIsPrivate(): void
    {
        $tree = $this->importFixtureTree('migration-flows-privacy.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        Auth::logout();

        $result = (new MigrationRepository($tree, new IsoCountryMap()))->flowsByCountry(10);

        $austriaToFrance = null;

        foreach ($result->links as $link) {
            $targetNode = $result->nodes[$link->target] ?? self::fail('link target index missing from the node table');

            if ($targetNode === 'France') {
                $austriaToFrance = $link;

                break;
            }
        }

        self::assertNotNull($austriaToFrance, 'the all-private flow is still present');
        self::assertSame(2, $austriaToFrance->value, 'the weight counts both private contributors');
        self::assertSame([], $austriaToFrance->samples, 'no private name leaks into the sample list');
    }

    /**
     * Resolve the Germany → United States flow's sample names for the current
     * user. Helper for the privacy discriminator above so the admin control and
     * the visitor assertion read the same flow the same way.
     *
     * @return list<string>
     */
    private function germanyToUnitedStatesSampleNames(Tree $tree): array
    {
        $result = (new MigrationRepository($tree, new IsoCountryMap()))->flowsByCountry(10);

        return array_map(
            static fn (SankeySample $sample): string => $sample->name,
            PayloadNarrowing::sankeyLinkAt($result->links, 0)->samples,
        );
    }
}
