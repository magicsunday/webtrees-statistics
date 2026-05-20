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
use MagicSunday\Webtrees\Statistic\Support\GedcomScanner;

use function array_slice;
use function count;
use function end;
use function explode;
use function is_string;
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
     * @param int $topLinks Maximum number of distinct flows to retain
     *
     * @return array{
     *     nodes: list<array{name: string}>,
     *     links: list<array{source: int, target: int, value: int}>
     * }
     */
    public function flowsByCountry(int $topLinks): array
    {
        $rows = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->select(['i_gedcom AS gedcom'])
            ->get();

        // Count every (origin → destination) pair once per individual.
        $linkWeight = [];

        foreach ($rows as $row) {
            $gedcom = is_string($row->gedcom) ? $row->gedcom : '';

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
        }

        if ($linkWeight === []) {
            return ['nodes' => [], 'links' => []];
        }

        // Sort descending by weight, then keep the top-N flows.
        uasort($linkWeight, static fn (int $left, int $right): int => $right <=> $left);

        $topFlows = array_slice($linkWeight, 0, $topLinks, true);

        // Build node table in first-seen order — d3-sankey needs nodes
        // referenced by index, and a stable order keeps the colour map
        // deterministic across re-renders.
        $nodeIndex = [];
        $nodes     = [];
        $links     = [];

        foreach ($topFlows as $key => $value) {
            [$origin, $destination] = explode("\0", $key, 2);

            if (!isset($nodeIndex[$origin])) {
                $nodeIndex[$origin] = count($nodes);
                $nodes[]            = ['name' => $origin];
            }

            if (!isset($nodeIndex[$destination])) {
                $nodeIndex[$destination] = count($nodes);
                $nodes[]                 = ['name' => $destination];
            }

            $links[] = [
                'source' => $nodeIndex[$origin],
                'target' => $nodeIndex[$destination],
                'value'  => $value,
            ];
        }

        return [
            'nodes' => $nodes,
            'links' => $links,
        ];
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
