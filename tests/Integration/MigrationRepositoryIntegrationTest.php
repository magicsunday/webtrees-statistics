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

/**
 * End-to-end test of the birth → death country flow aggregator. The
 * curated fixture covers the four behaviours that matter at the data
 * layer: a country pair with three individuals (Germany → USA), a
 * one-off pair (Vienna → Paris), a same-country trajectory that must
 * be dropped (Austria → Austria), and individuals missing either
 * BIRT or DEAT place (must be silently skipped).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class MigrationRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Aggregates the fixture into the bipartite Sankey payload: the
     * Germany → USA flow leads with weight 3, Germany → England,
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

        // Four flows survive: Germany→USA (×3), Germany→England (×1),
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
        self::assertSame(['source' => 0, 'target' => 2, 'value' => 3], $result['links'][0]);

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
        self::assertSame(3, $result['links'][0]['value']);

        // With only the Germany → USA flow surviving, the node table
        // shrinks to one source and one target.
        self::assertCount(2, $result['nodes']);
        self::assertSame('Germany', $result['nodes'][0]['name']);
        self::assertSame('USA', $result['nodes'][1]['name']);
    }
}
