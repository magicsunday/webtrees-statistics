<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Tree;

use Fisharebest\Webtrees\Individual;
use JsonSerializable;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\IndividualWire;

use function array_map;

/**
 * The single largest connected partnership group, in the renderable shape the
 * Family-tab partnership-reach card consumes. Carries live {@see Individual}
 * objects in `$nodes` so the PHTML view can link each person, and — when the
 * real cluster overruns the repository's excerpt cap — a connected EXCERPT
 * (`shownCount` ≤ `totalCount`) grown outward from the longest-chain nodes.
 *
 * A webtrees `Individual` is not JSON-encodable, so {@see jsonSerialize()}
 * flattens every node to a `{xref, label, sex, birth, death, url}` row; the
 * edges already travel as `[idA, idB]` xref pairs.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class PartnershipGroupExcerpt implements JsonSerializable
{
    /**
     * @param list<Individual>                  $nodes          The group's people (the shown excerpt when capped), as live records so the view can link them
     * @param list<array{0: string, 1: string}> $edges          The partnership edges among the shown nodes, as byte-ordered `[idA, idB]` xref pairs
     * @param list<string>                      $chainIds       The longest path's xrefs, in order
     * @param string                            $hubId          The max-degree member's xref (the cluster hub)
     * @param int                               $hubDegree      The hub's real partnership degree over the WHOLE group, not just the shown excerpt
     * @param int                               $totalCount     The group's real size
     * @param int                               $totalEdgeCount The group's real internal partnership-edge count over the WHOLE group, not just the shown excerpt
     * @param int                               $shownCount     The number of nodes actually drawn (≤ `$totalCount`, ≤ the excerpt cap)
     * @param int|null                          $medianYear     The median of the group's combined birth+death years, or `null` when none are dated
     */
    public function __construct(
        public array $nodes,
        public array $edges,
        public array $chainIds,
        public string $hubId,
        public int $hubDegree,
        public int $totalCount,
        public int $totalEdgeCount,
        public int $shownCount,
        public ?int $medianYear,
    ) {
    }

    /**
     * Flatten the excerpt to a JSON-encodable shape: every node becomes a
     * `{xref, label, sex, birth, death, url}` row (an {@see Individual} is not
     * JSON-encodable); edges, chain ids and the scalar fields pass through.
     *
     * @return array{nodes: list<array{xref: string, label: string, sex: string, birth: string, death: string, url: string}>, edges: list<array{0: string, 1: string}>, chainIds: list<string>, hubId: string, hubDegree: int, totalCount: int, totalEdgeCount: int, shownCount: int, medianYear: int|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'nodes'          => array_map(IndividualWire::row(...), $this->nodes),
            'edges'          => $this->edges,
            'chainIds'       => $this->chainIds,
            'hubId'          => $this->hubId,
            'hubDegree'      => $this->hubDegree,
            'totalCount'     => $this->totalCount,
            'totalEdgeCount' => $this->totalEdgeCount,
            'shownCount'     => $this->shownCount,
            'medianYear'     => $this->medianYear,
        ];
    }
}
