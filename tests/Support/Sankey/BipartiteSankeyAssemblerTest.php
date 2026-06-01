<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Sankey;

use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyFlowsPayload;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyLink;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeySample;
use MagicSunday\Webtrees\Statistic\Support\Sankey\BipartiteSankeyAssembler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Unit test for the shared bipartite-Sankey assembler. Exercises the
 * sort/top-N/disjoint-column/index-shift tail in isolation — invariants that
 * were previously only covered indirectly through the migration and occupation
 * repository integration tests. A synthetic `source\0target` weighted flow map
 * pins the layout maths directly.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(BipartiteSankeyAssembler::class)]
#[UsesClass(SankeyFlowsPayload::class)]
#[UsesClass(SankeyLink::class)]
#[UsesClass(SankeySample::class)]
final class BipartiteSankeyAssemblerTest extends TestCase
{
    /**
     * An empty flow map returns the empty payload shape rather than a malformed
     * structure — the consumer's empty-state guard depends on `nodes === []`.
     */
    #[Test]
    public function assembleReturnsTheEmptyShapeForNoFlows(): void
    {
        $payload = BipartiteSankeyAssembler::assemble([], [], 10);

        self::assertSame([], $payload->nodes);
        self::assertSame([], $payload->links);
    }

    /**
     * A populated map lays the source side out in the lower index range and the
     * target side in the upper range, with every target index shifted past the
     * source column. Flows are ordered by descending weight.
     */
    #[Test]
    public function assembleLaysOutDisjointColumnsAndShiftsTargetIndices(): void
    {
        $linkWeight = [
            "Germany\0USA"     => 4,
            "Germany\0England" => 1,
            "Austria\0France"  => 2,
        ];
        $linkSamples = [
            "Germany\0USA"     => [new SankeySample(name: 'Anna', xref: 'I1')],
            "Germany\0England" => [new SankeySample(name: 'Doris', xref: 'I4')],
            "Austria\0France"  => [new SankeySample(name: 'Egon', xref: 'I5')],
        ];

        $payload = BipartiteSankeyAssembler::assemble($linkWeight, $linkSamples, 10);

        // Source column [Germany, Austria] then target column [USA, France,
        // England] in descending-weight encounter order.
        self::assertSame(['Germany', 'Austria', 'USA', 'France', 'England'], $payload->nodes);

        // Heaviest flow leads: Germany (0) → USA (target idx 0, shifted by
        // sourceColumnSize=2 → absolute 2).
        self::assertSame(0, $payload->links[0]->source);
        self::assertSame(2, $payload->links[0]->target);
        self::assertSame(4, $payload->links[0]->value);

        // Every link respects the bipartite invariant: source in [0, 2),
        // target in [2, 5), never equal.
        foreach ($payload->links as $link) {
            self::assertLessThan(2, $link->source);
            self::assertGreaterThanOrEqual(2, $link->target);
            self::assertNotSame($link->source, $link->target);
        }
    }

    /**
     * A label that appears on both the source and the target side becomes TWO
     * distinct nodes — one per column — so d3-sankey never sees a self-cycle.
     */
    #[Test]
    public function assembleKeepsBidirectionalLabelsOnDisjointSides(): void
    {
        $linkWeight = [
            "Germany\0USA" => 2,
            "USA\0Germany" => 1,
        ];
        $linkSamples = [
            "Germany\0USA" => [],
            "USA\0Germany" => [],
        ];

        $payload = BipartiteSankeyAssembler::assemble($linkWeight, $linkSamples, 10);

        // Germany and USA each appear twice: once as a source, once as a
        // target. No link folds onto a single node.
        self::assertSame(['Germany', 'USA', 'USA', 'Germany'], $payload->nodes);

        foreach ($payload->links as $link) {
            self::assertNotSame($link->source, $link->target);
        }
    }

    /**
     * The top-N cap keeps only the heaviest flows; nodes referenced solely by
     * dropped links disappear from the node table.
     */
    #[Test]
    public function assembleRespectsTheTopLinksLimit(): void
    {
        $linkWeight = [
            "Germany\0USA"    => 4,
            "Austria\0France" => 1,
        ];
        $linkSamples = [
            "Germany\0USA"    => [],
            "Austria\0France" => [],
        ];

        $payload = BipartiteSankeyAssembler::assemble($linkWeight, $linkSamples, 1);

        self::assertCount(1, $payload->links);
        self::assertSame(4, $payload->links[0]->value);
        self::assertSame(['Germany', 'USA'], $payload->nodes);
    }

    /**
     * When the caller folded its keys (e.g. case-folded occupations), the
     * optional display map supplies the readable node labels; keys absent from
     * the map fall back to the key itself.
     */
    #[Test]
    public function assembleUsesTheDisplayMapForNodeLabels(): void
    {
        $linkWeight = [
            "farmer\0farmer" => 3,
        ];
        $linkSamples = [
            "farmer\0farmer" => [new SankeySample(name: 'Anton', xref: 'I2')],
        ];
        $display = [
            'farmer' => 'Farmer',
        ];

        $payload = BipartiteSankeyAssembler::assemble($linkWeight, $linkSamples, 10, $display);

        // Both columns surface the first-seen casing from the display map.
        self::assertSame(['Farmer', 'Farmer'], $payload->nodes);
        self::assertSame(3, $payload->links[0]->value);
        self::assertCount(1, $payload->links[0]->samples);
    }

    /**
     * Equal-weight flows retain their insertion order — uasort is stable on
     * PHP 8, and the assembler must not reorder ties. The two flows SHARE a
     * source and differ only in target, so the target column order is governed
     * solely by tie-stability: an unstable sort would surface them in the wrong
     * order, while the foreach insertion order alone cannot account for it.
     */
    #[Test]
    public function assemblePreservesInsertionOrderForEqualWeights(): void
    {
        $linkWeight = [
            "Germany\0USA"    => 1,
            "Germany\0France" => 1,
        ];
        $linkSamples = [
            "Germany\0USA"    => [],
            "Germany\0France" => [],
        ];

        $payload = BipartiteSankeyAssembler::assemble($linkWeight, $linkSamples, 10);

        // Single source, then the two equal-weight targets in their original
        // insertion order — USA before France. A non-stable sort of the tie
        // would flip these.
        self::assertSame(['Germany', 'USA', 'France'], $payload->nodes);
    }
}
