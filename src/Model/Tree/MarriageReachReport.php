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
 * Marriage-reach ("marriage chains" / "marriage webs") report for the Family
 * tab. Surfaces the longest unbroken chain of married people in the tree
 * alongside the single largest connected marriage group, so the viewer can see
 * both the deepest line ("A married B, whose later spouse C married D, …") and
 * the broadest cluster of intermarriage at a glance.
 *
 * Carries live {@see Individual} instances in `$chain` (and, via the
 * {@see MarriageGroupExcerpt}, in the group's nodes) so the PHTML consumer can
 * render each person as a link to their individual page. A webtrees
 * `Individual` is not JSON-encodable, so {@see jsonSerialize()} flattens every
 * person down to a `{xref, label, sex, birth, death, url}` row and every edge
 * down to an `[idA, idB]` xref pair — the same discipline
 * {@see GenerationDepthReport} uses when it flattens its chains to xref lists.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MarriageReachReport implements JsonSerializable
{
    /**
     * @param int                  $longestChainLength Number of people on the longest marriage chain (the breadth component of the ratio)
     * @param list<Individual>     $chain              The longest-chain people, ordered, as live records so the view can link them
     * @param MarriageGroupExcerpt $group              The largest connected marriage group (nodes, edges, hub, sizes, median year)
     * @param int                  $depthPath          Tree-wide maximum generation depth — the depth component of the depth-vs-breadth ratio
     * @param int                  $breadthChain       Longest marriage-chain length — the breadth component (mirror of `$longestChainLength`)
     */
    public function __construct(
        public int $longestChainLength,
        public array $chain,
        public MarriageGroupExcerpt $group,
        public int $depthPath,
        public int $breadthChain,
    ) {
    }

    /**
     * Flatten the report to a JSON-encodable shape: every chain person becomes a
     * `{xref, label, sex, birth, death, url}` row (an {@see Individual} is not
     * JSON-encodable) and the group serialises through its own
     * {@see MarriageGroupExcerpt::jsonSerialize()}, so a JS consumer can drive
     * the chain and the group graph without an Individual proxy on the wire.
     *
     * @return array{longestChainLength: int, chain: list<array{xref: string, label: string, sex: string, birth: string, death: string, url: string}>, group: array{nodes: list<array{xref: string, label: string, sex: string, birth: string, death: string, url: string}>, edges: list<array{0: string, 1: string}>, chainIds: list<string>, hubId: string, hubDegree: int, totalCount: int, totalEdgeCount: int, shownCount: int, medianYear: int|null}, depthPath: int, breadthChain: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'longestChainLength' => $this->longestChainLength,
            'chain'              => array_map(IndividualWire::row(...), $this->chain),
            'group'              => $this->group->jsonSerialize(),
            'depthPath'          => $this->depthPath,
            'breadthChain'       => $this->breadthChain,
        ];
    }
}
