<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Tree;

use JsonSerializable;

/**
 * Partnership-reach ("partnership chains" / "partnership webs") report for the Family
 * tab. Surfaces the longest unbroken chain of married people in the tree
 * alongside the single largest connected partnership group, so the viewer can see
 * both the deepest line ("A married B, whose later spouse C married D, …") and
 * the broadest cluster of interconnected partnerships at a glance.
 *
 * Carries its people as the already flattened `{xref, label, sex, birth, death,
 * url}` wire rows in `$chain` (and, via the {@see PartnershipGroupExcerpt}, in
 * the group's nodes), so the PHTML consumer can link each person and the report
 * holds no framework record. Every edge is an `[idA, idB]` xref pair — the same
 * discipline {@see GenerationDepthReport} uses for its chains.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class PartnershipReachReport implements JsonSerializable
{
    /**
     * @param int                                                                                              $longestChainLength Number of people on the longest partnership chain (the breadth component of the ratio)
     * @param list<array{xref: string, label: string, sex: string, birth: string, death: string, url: string}> $chain              The longest-chain people, ordered, as wire rows
     * @param PartnershipGroupExcerpt                                                                          $group              The largest connected partnership group (nodes, edges, hub, sizes, median year)
     * @param int                                                                                              $depthPath          Tree-wide maximum generation depth — the depth component of the depth-vs-breadth ratio
     * @param int                                                                                              $breadthChain       Longest partnership-chain length — the breadth component (mirror of `$longestChainLength`)
     * @param bool                                                                                             $chainIsApproximate Whether the group contains a partnership loop, so a longer chain than the reported one may exist
     * @param bool                                                                                             $chainIsCapped      Whether the chain reached the reconstruction bound and may be cut there
     */
    public function __construct(
        public int $longestChainLength,
        public array $chain,
        public PartnershipGroupExcerpt $group,
        public int $depthPath,
        public int $breadthChain,
        public bool $chainIsApproximate = false,
        public bool $chainIsCapped = false,
    ) {
    }

    /**
     * The JSON-encodable shape of the report: the chain rows pass through and the
     * group serialises through its own {@see PartnershipGroupExcerpt::jsonSerialize()},
     * so a JS consumer can drive the chain and the group graph.
     *
     * @return array{longestChainLength: int, chain: list<array{xref: string, label: string, sex: string, birth: string, death: string, url: string}>, group: array{nodes: list<array{xref: string, label: string, sex: string, birth: string, death: string, url: string}>, edges: list<array{0: string, 1: string}>, chainIds: list<string>, hubId: string, hubDegree: int, totalCount: int, totalEdgeCount: int, shownCount: int, medianYear: int|null}, depthPath: int, breadthChain: int, chainIsApproximate: bool, chainIsCapped: bool}
     */
    public function jsonSerialize(): array
    {
        return [
            'longestChainLength' => $this->longestChainLength,
            'chain'              => $this->chain,
            'group'              => $this->group->jsonSerialize(),
            'depthPath'          => $this->depthPath,
            'breadthChain'       => $this->breadthChain,
            'chainIsApproximate' => $this->chainIsApproximate,
            'chainIsCapped'      => $this->chainIsCapped,
        ];
    }
}
