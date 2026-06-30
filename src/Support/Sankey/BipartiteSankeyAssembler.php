<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Sankey;

use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyFlowsPayload;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyLink;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeySample;

use function array_slice;
use function count;
use function explode;
use function uasort;

/**
 * Turns a weighted, keyed flow map into a bipartite Sankey payload. Both
 * Sankey aggregators (birth → death migration, parent → child occupation
 * inheritance) accumulate the same intermediate shape — a `source\0target`
 * keyed weight map plus per-flow sample lists — and then need the identical
 * tail: sort by weight, keep the top-N, lay the source and target sides out as
 * DISJOINT node columns, and shift the target indices past the source column.
 * That tail lives here so the index-shift and empty-shape invariants are owned
 * in one place rather than copied per aggregator.
 *
 * Source and target sides are kept disjoint because d3-sankey only lays out
 * directed acyclic graphs: a label appearing on both ends (a country that is
 * both an origin and a destination, an occupation both passed down and
 * inherited) must become two separate nodes, one per column, or the layout
 * throws a "circular link" error.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class BipartiteSankeyAssembler
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Assemble the payload from the accumulated flow map. Each key in
     * `$linkWeight` is a `source-key "\0" target-key` pair; the matching entry
     * in `$linkSamples` carries that flow's representative individuals. Node
     * labels default to the key itself, or to `$display[$key]` when the caller
     * folded its keys (e.g. case-folded occupations) and needs the original
     * casing surfaced.
     *
     * @param array<string, int>                $linkWeight  Flow weight per `source\0target` key
     * @param array<string, list<SankeySample>> $linkSamples Sample individuals per `source\0target` key
     * @param int                               $topLinks    Maximum number of distinct flows to retain
     * @param array<string, string>             $display     Optional key → display-label map (identity when omitted)
     */
    public static function assemble(
        array $linkWeight,
        array $linkSamples,
        int $topLinks,
        array $display = [],
    ): SankeyFlowsPayload {
        if ($linkWeight === []) {
            return new SankeyFlowsPayload(nodes: [], links: []);
        }

        // Sort descending by weight, then keep the top-N flows. uasort is
        // stable on PHP 8, so equal-weight flows retain their insertion order
        // and the result stays deterministic.
        uasort($linkWeight, static fn (int $left, int $right): int => $right <=> $left);

        $topFlows = array_slice($linkWeight, 0, $topLinks, true);

        // Build SEPARATE node tables for the source and target sides so a label
        // appearing on both ends generates two distinct nodes (one per column).
        // Source nodes occupy the lower index range, target nodes the upper one
        // — the d3-sankey layout reads "source index < target index" as
        // "left of, right of".
        $sourceIndex = [];
        $sourceNodes = [];
        $targetIndex = [];
        $targetNodes = [];
        $rawLinks    = [];

        foreach ($topFlows as $key => $value) {
            $parts     = explode("\0", $key, 2);
            $sourceKey = $parts[0];
            $targetKey = $parts[1] ?? '';

            if (!isset($sourceIndex[$sourceKey])) {
                $sourceIndex[$sourceKey] = count($sourceNodes);
                $sourceNodes[]           = $display[$sourceKey] ?? $sourceKey;
            }

            if (!isset($targetIndex[$targetKey])) {
                $targetIndex[$targetKey] = count($targetNodes);
                $targetNodes[]           = $display[$targetKey] ?? $targetKey;
            }

            $rawLinks[] = [
                'source'  => $sourceIndex[$sourceKey],
                'target'  => $targetIndex[$targetKey],
                'value'   => $value,
                'samples' => $linkSamples[$key] ?? [],
            ];
        }

        // Concatenate the two columns into a single nodes array and shift every
        // target index by the source-column length so the bipartite layout
        // reads cleanly without overlap.
        $sourceColumnSize = count($sourceNodes);
        $nodes            = [...$sourceNodes, ...$targetNodes];
        $links            = [];

        foreach ($rawLinks as $link) {
            $links[] = new SankeyLink(
                source: $link['source'],
                target: $sourceColumnSize + $link['target'],
                value: $link['value'],
                samples: $link['samples'],
            );
        }

        return new SankeyFlowsPayload(nodes: $nodes, links: $links);
    }
}
