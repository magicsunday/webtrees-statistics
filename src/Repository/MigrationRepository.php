<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use MagicSunday\Webtrees\Statistic\Model\Dto\Sankey\MigrationFlowsPayload;
use MagicSunday\Webtrees\Statistic\Model\Dto\Sankey\SankeyLink;
use MagicSunday\Webtrees\Statistic\Model\Dto\Sankey\SankeyNode;
use MagicSunday\Webtrees\Statistic\Model\Dto\Sankey\SankeySample;
use MagicSunday\Webtrees\Statistic\Support\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\RowCast;

use function array_slice;
use function count;
use function end;
use function explode;
use function trim;
use function uasort;

/**
 * Aggregates birth → death country movements across the tree's
 * individuals. Each individual with both a birth-place country and a
 * death-place country contributes one weighted link to the Sankey
 * diagram drawn on the Places tab.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MigrationRepository
{
    /**
     * Maximum number of sample individuals surfaced per flow on
     * hover. Three is the acceptance-criteria minimum from issue
     * #12 and visually fits on one tooltip line per name.
     */
    private const int SAMPLES_PER_FLOW = 3;

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Build the Sankey payload describing country-level migrations
     * between birth and death. Same-country movements are dropped (no
     * migration), as are individuals missing either place. Only the
     * top-N links by weight are returned so the diagram stays legible
     * on busy trees; their incident nodes are added in encounter order.
     *
     * Source and target sides are kept as DISJOINT node sets — a node
     * that appears as both an origin and a destination (e.g. Germany
     * → USA combined with USA → Germany) shows up as two separate
     * Sankey nodes, one in each column. This is required because
     * d3-sankey only lays out directed acyclic graphs; folding such
     * counter-flows onto a single node would create a 2-cycle and
     * throw a "circular link" error at render time.
     *
     * Each link carries up to {@see SAMPLES_PER_FLOW} sample
     * individuals (`name`, `xref`) so the consumer's hover tooltip
     * can surface representative people behind every migration
     * path — the acceptance criterion from issue #12.
     *
     * @param int $topLinks Maximum number of distinct flows to retain
     */
    public function flowsByCountry(int $topLinks): MigrationFlowsPayload
    {
        // ORDER BY i_id pins iteration to the (lexicographic) xref
        // order so the SAMPLES_PER_FLOW cap always picks the same
        // representatives across page loads, even after table-level
        // events (OPTIMIZE TABLE, replication, index changes).
        $rows = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->orderBy('i_id')
            ->select(['i_id AS xref', 'i_gedcom AS gedcom'])
            ->get();

        // Count every (origin → destination) pair once per individual,
        // and remember up to SAMPLES_PER_FLOW representatives per flow.
        $linkWeight  = [];
        $linkSamples = [];

        foreach ($rows as $row) {
            $gedcom = RowCast::string($row, 'gedcom');
            $xref   = RowCast::string($row, 'xref');

            $birthPlace = GedcomScanner::extractEventPlace($gedcom, 'BIRT');
            $deathPlace = GedcomScanner::extractEventPlace($gedcom, 'DEAT');

            if ($birthPlace === null) {
                continue;
            }

            if ($deathPlace === null) {
                continue;
            }

            $origin      = $this->extractCountry($birthPlace);
            $destination = $this->extractCountry($deathPlace);

            if ($origin === null) {
                continue;
            }

            if ($destination === null) {
                continue;
            }

            if ($origin === $destination) {
                continue;
            }

            $key              = $origin . "\0" . $destination;
            $linkWeight[$key] = ($linkWeight[$key] ?? 0) + 1;
            $linkSamples[$key] ??= [];

            if (count($linkSamples[$key]) < self::SAMPLES_PER_FLOW) {
                $linkSamples[$key][] = new SankeySample(
                    name: GedcomScanner::extractPrimaryName($gedcom),
                    xref: $xref,
                );
            }
        }

        if ($linkWeight === []) {
            return new MigrationFlowsPayload(nodes: [], links: []);
        }

        // Sort descending by weight, then keep the top-N flows.
        uasort($linkWeight, static fn (int $left, int $right): int => $right <=> $left);

        $topFlows = array_slice($linkWeight, 0, $topLinks, true);

        // Build SEPARATE node tables for the source and the target
        // sides so a country appearing on both ends generates two
        // distinct nodes (one per Sankey column). Source nodes occupy
        // the lower index range, target nodes the upper one — the
        // d3-sankey layout reads "source index < target index" as
        // "left of, right of".
        $sourceIndex = [];
        $sourceNodes = [];
        $targetIndex = [];
        $targetNodes = [];
        $rawLinks    = [];

        foreach ($topFlows as $key => $value) {
            [$origin, $destination] = explode("\0", $key, 2);

            if (!isset($sourceIndex[$origin])) {
                $sourceIndex[$origin] = count($sourceNodes);
                $sourceNodes[]        = new SankeyNode(name: $origin);
            }

            if (!isset($targetIndex[$destination])) {
                $targetIndex[$destination] = count($targetNodes);
                $targetNodes[]             = new SankeyNode(name: $destination);
            }

            $rawLinks[] = [
                'source'  => $sourceIndex[$origin],
                'target'  => $targetIndex[$destination],
                'value'   => $value,
                'samples' => $linkSamples[$key],
            ];
        }

        // Concatenate the two columns into a single nodes array and
        // shift every target index by the source-column length so the
        // bipartite layout reads cleanly without overlap.
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

        return new MigrationFlowsPayload(nodes: $nodes, links: $links);
    }

    /**
     * Pull the country segment out of a webtrees place string. Standard
     * GEDCOM PLAC ordering is "<most specific>, ..., <country>", so the
     * last comma-separated segment carries the country (or a single
     * standalone token when no comma is present, which we accept as
     * the country itself — common in small or hand-authored trees).
     */
    private function extractCountry(string $place): ?string
    {
        $segments = explode(',', $place);
        $country  = trim(end($segments));

        return ($country === '') ? null : $country;
    }
}
