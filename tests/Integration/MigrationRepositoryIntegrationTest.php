<?php

declare(strict_types=1);

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\MigrationRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_count_values;
use function array_map;
use function array_slice;
use function array_unique;

/**
 * End-to-end test of the birth → death country flow aggregator. The
 * curated fixture covers the four behaviours that matter at the data
 * layer: a country pair with four individuals (Germany → USA, used
 * to exercise the SAMPLES_PER_FLOW cap), a one-off pair (Vienna →
 * Paris), a same-country trajectory that must be dropped (Austria →
 * Austria), and individuals missing either BIRT or DEAT place (must
 * be silently skipped).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class MigrationRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Aggregates the fixture into the bipartite Sankey payload: the
     * Germany → USA flow leads with weight 4, Germany → England,
     * Austria → France, and Germany → Canada each weigh 1. The
     * same-country Vienna→Vienna individual and the two with a
     * missing BIRT or DEAT place do not contribute. Source and target
     * sides occupy disjoint node-index ranges.
     */
    #[Test]
    public function flowsByCountryReturnsTheExpectedAggregation(): void
    {
        $tree   = $this->importFixtureTree('migration-flows.ged');
        $result = (new MigrationRepository($tree))->flowsByCountry(10);

        // Four flows survive: Germany→USA (×4), Germany→England (×1),
        // Austria→France (×1), Germany→Canada (×1).
        self::assertCount(4, $result['links']);

        // Two source nodes (Germany, Austria) then four target nodes
        // (USA, England, France, Canada) in insertion order.
        self::assertSame(
            ['Germany', 'Austria', 'USA', 'England', 'France', 'Canada'],
            array_map(static fn (array $node): string => $node['name'], $result['nodes']),
        );

        // Heaviest flow leads — Germany (source idx 0) → USA (target
        // idx 0, shifted by sourceColumnSize=2 to absolute idx 2).
        $heaviest = $result['links'][0];
        self::assertSame(0, $heaviest['source']);
        self::assertSame(2, $heaviest['target']);
        self::assertSame(4, $heaviest['value']);
        self::assertIsArray($heaviest['samples']);

        // The remaining three flows are weighted 1.
        $remainingValues = array_map(static fn (array $link): int => $link['value'], array_slice($result['links'], 1));
        self::assertSame([1, 1, 1], $remainingValues);

        // Every link respects the bipartite invariant: source index is
        // in the source column [0, 2), target index is in the target
        // column [2, 6).
        foreach ($result['links'] as $link) {
            self::assertLessThan(2, $link['source'], 'source index must be in the source column');
            self::assertGreaterThanOrEqual(2, $link['target'], 'target index must be in the target column');
        }
    }

    /**
     * A small top-N limit drops the tail of the link table while still
     * surfacing the heaviest flow. Nodes referenced only by dropped
     * links also disappear from the node table.
     */
    #[Test]
    public function flowsByCountryRespectsTheTopLinksLimit(): void
    {
        $tree   = $this->importFixtureTree('migration-flows.ged');
        $result = (new MigrationRepository($tree))->flowsByCountry(1);

        self::assertCount(1, $result['links']);
        self::assertSame(4, $result['links'][0]['value']);

        // With only the Germany → USA flow surviving, the node table
        // shrinks to one source and one target.
        self::assertCount(2, $result['nodes']);
        self::assertSame('Germany', $result['nodes'][0]['name']);
        self::assertSame('USA', $result['nodes'][1]['name']);
    }

    /**
     * A tree where every individual was born and died in the same
     * country produces no flows — the same-country guard drops every
     * contribution and the aggregator returns its empty shape.
     */
    #[Test]
    public function flowsByCountryReturnsEmptyWhenEveryTrajectoryIsSameCountry(): void
    {
        $tree   = $this->importFixtureTree('migration-same-country.ged');
        $result = (new MigrationRepository($tree))->flowsByCountry(10);

        self::assertSame([], $result['nodes']);
        self::assertSame([], $result['links']);
    }

    /**
     * Every link carries up to three sample individuals so the
     * hover tooltip can surface representative names per flow.
     * Issue #12 spelled this out as an acceptance criterion.
     * Samples are name + xref pairs; the names come straight from
     * the GEDCOM 1 NAME line with the slashes stripped.
     *
     * The fixture has FOUR Germany→USA contributors (Anna, Berta,
     * Carl, Dieter Test) precisely to exercise the cap: the sample
     * list must hold exactly three distinct names drawn from the
     * four candidates and the underlying flow value must still
     * reflect all four contributors. WHICH three survive is pinned
     * by the repository's ORDER BY i_id, but the test stays
     * order-agnostic so renumbering the fixture xrefs would not
     * cascade into noisy churn.
     */
    #[Test]
    public function flowsByCountryAttachesSampleIndividualsPerLink(): void
    {
        $tree   = $this->importFixtureTree('migration-flows.ged');
        $result = (new MigrationRepository($tree))->flowsByCountry(10);

        $heaviest = $result['links'][0];
        // Germany → USA carries 4 contributors; samples cap at 3.
        self::assertSame(4, $heaviest['value'], 'flow weight reflects all 4 contributors');
        self::assertCount(3, $heaviest['samples'], 'sample list caps at SAMPLES_PER_FLOW=3');

        $names = array_map(
            static fn (array $sample): string => $sample['name'],
            $heaviest['samples'],
        );
        // Every surfaced sample must be one of the four known Germany→USA
        // contributors — proves the cap picks from the right population.
        $candidates = ['Anna Test', 'Berta Test', 'Carl Test', 'Dieter Test'];
        foreach ($names as $name) {
            self::assertContains($name, $candidates, 'sample names must come from the fixture pool');
        }
        // No duplicates: every cap slot held by a distinct individual.
        self::assertCount(3, array_unique($names), 'cap picks 3 distinct individuals');

        // Every sample also carries its source xref so the tooltip
        // could link to the individual page if the consumer wants.
        foreach ($heaviest['samples'] as $sample) {
            self::assertArrayHasKey('xref', $sample);
            self::assertStringStartsWith('I', $sample['xref']);
        }

        // The thin Germany → Canada flow has exactly one contributor;
        // proves the sample list size scales with the flow weight, not
        // a fixed length.
        $canada = null;

        foreach ($result['links'] as $link) {
            if (($link['value'] === 1)
                && ($result['nodes'][$link['target']]['name'] === 'Canada')
            ) {
                $canada = $link;
                break;
            }
        }

        self::assertNotNull($canada);
        self::assertCount(1, $canada['samples']);
    }

    /**
     * `extractPrimaryName`'s "(no name)" fallback fires when the
     * raw GEDCOM `1 NAME` line yields nothing after the slash strip
     * (e.g. `1 NAME / /` — empty given AND empty surname). The
     * tooltip must still surface a meaningful placeholder rather
     * than an empty entry that visually disappears.
     */
    #[Test]
    public function flowsByCountryFallsBackToPlaceholderForBlankNames(): void
    {
        $tree   = $this->importFixtureTree('migration-noname.ged');
        $result = (new MigrationRepository($tree))->flowsByCountry(10);

        self::assertCount(1, $result['links']);
        self::assertCount(1, $result['links'][0]['samples']);
        self::assertSame('(no name)', $result['links'][0]['samples'][0]['name']);
    }

    /**
     * A country that appears both as an origin AND as a destination
     * shows up as two distinct nodes — one in the source column, one
     * in the target column. Without this split d3-sankey would throw
     * a "circular link" error on the bidirectional flow.
     */
    #[Test]
    public function flowsByCountryKeepsBidirectionalCountriesOnDisjointSides(): void
    {
        $tree   = $this->importFixtureTree('migration-bidirectional.ged');
        $result = (new MigrationRepository($tree))->flowsByCountry(10);

        // Two flows: Germany → USA (×2) and USA → Germany (×1). Both
        // countries appear on both sides.
        self::assertCount(2, $result['links']);

        $names = array_map(static fn (array $node): string => $node['name'], $result['nodes']);

        // Germany and USA each appear twice: once on the source side,
        // once on the target side.
        self::assertSame(2, array_count_values($names)['Germany']);
        self::assertSame(2, array_count_values($names)['USA']);

        // No link can reference a source-index that equals its
        // target-index — would indicate a fold-back.
        foreach ($result['links'] as $link) {
            self::assertNotSame($link['source'], $link['target']);
        }
    }
}
