<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Model\Sankey;

use MagicSunday\Webtrees\Statistic\Model\Sankey\MigrationFlowsPayload;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyLink;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeySample;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function json_decode;
use function json_encode;

/**
 * Behavioural parity test for the migration-flows DTO chain. Asserts that
 * `json_encode` on a fully-populated `MigrationFlowsPayload` still produces the
 * exact wire shape every chart-lib sankey-flow consumer was built against
 * before the array-shape → DTO refactor. Any drift here would silently break
 * the JSON contract the JS widget reads, so the lock-down is per-key.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class MigrationFlowsPayloadTest extends TestCase
{
    /**
     * A populated payload round-trips through `json_encode` to the exact array
     * shape the chart-lib sankey-flow widget consumed before the refactor.
     */
    #[Test]
    public function payloadSerialisesToTheLegacyWireShape(): void
    {
        $payload = new MigrationFlowsPayload(
            nodes: [
                'Germany',
                'USA',
            ],
            links: [
                new SankeyLink(
                    source: 0,
                    target: 1,
                    value: 3,
                    samples: [
                        new SankeySample(name: 'Anna Test', xref: 'I1'),
                        new SankeySample(name: 'Berta Test', xref: 'I2'),
                    ],
                ),
            ],
        );

        $expected = [
            'nodes' => [
                ['name' => 'Germany'],
                ['name' => 'USA'],
            ],
            'links' => [
                [
                    'source'  => 0,
                    'target'  => 1,
                    'value'   => 3,
                    'samples' => [
                        ['name' => 'Anna Test', 'xref' => 'I1'],
                        ['name' => 'Berta Test', 'xref' => 'I2'],
                    ],
                ],
            ],
        ];

        self::assertSame($expected, json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR));
    }

    /**
     * An empty payload (no flows / no nodes) serialises to the `{nodes: [],
     * links: []}` shape the chart-lib widget treats as "render empty state". A
     * regression here would surface as the widget either crashing on `null` or
     * rendering a stale chart.
     */
    #[Test]
    public function emptyPayloadSerialisesToEmptyArrays(): void
    {
        $payload = new MigrationFlowsPayload(nodes: [], links: []);

        self::assertSame(
            ['nodes' => [], 'links' => []],
            json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * `SankeySample` carries name + xref so the consumer's tooltip can render
     * either. The link to the individual record is held via the xref; renaming
     * or dropping that key would silently disconnect every per-flow hover
     * surface.
     */
    #[Test]
    public function sankeySampleSerialisesToNameAndXrefKeys(): void
    {
        $sample = new SankeySample(name: '(no name)', xref: 'I42');

        self::assertSame(
            ['name' => '(no name)', 'xref' => 'I42'],
            json_decode(json_encode($sample, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * `SankeyLink` includes its samples list inline, which itself nests through
     * `SankeySample::jsonSerialize`. The nested encode is the most likely drift
     * point so an explicit empty + populated case here guards both ends of the
     * spectrum.
     */
    #[Test]
    public function sankeyLinkSerialisesWithItsNestedSampleList(): void
    {
        $linkEmpty = new SankeyLink(source: 0, target: 1, value: 5, samples: []);

        self::assertSame(
            ['source' => 0, 'target' => 1, 'value' => 5, 'samples' => []],
            json_decode(json_encode($linkEmpty, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        );

        $linkWithSamples = new SankeyLink(
            source: 2,
            target: 3,
            value: 1,
            samples: [new SankeySample(name: 'Carl Test', xref: 'I3')],
        );

        self::assertSame(
            [
                'source'  => 2,
                'target'  => 3,
                'value'   => 1,
                'samples' => [['name' => 'Carl Test', 'xref' => 'I3']],
            ],
            json_decode(json_encode($linkWithSamples, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR),
        );
    }
}
