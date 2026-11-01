<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Tree\PartnershipGroupExcerpt;
use MagicSunday\Webtrees\Statistic\Model\Tree\PartnershipReachReport;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Calc\PartnershipChains;
use MagicSunday\Webtrees\Statistic\Support\Database\GedcomByXref;

use function array_fill_keys;
use function array_push;
use function count;
use function strcmp;

/**
 * Partnership-reach surfacing for the Family tab. Orchestrates the shared
 * partnership-graph adjacency ({@see PartnershipMapRepository}) and the pure
 * connected-component / longest-path calculator ({@see PartnershipChains}) into a
 * renderable {@see PartnershipReachReport}: the longest unbroken partnership chain,
 * the single largest connected partnership group (with its internal edges, its
 * max-degree hub, and a median birth+death year), and the depth-vs-breadth
 * ratio components.
 *
 * Person resolution goes through ONE bulk gedcom fetch ({@see GedcomByXref}),
 * exactly the {@see GenerationDepthRepository::summary()} discipline, so the
 * report's xref-to-{@see Individual} step issues a single chunked query rather
 * than one `IndividualFactory::make()` round-trip per person. Privacy follows
 * the same convention: every node stays in place and `Individual::fullName()`
 * substitutes the "Private" placeholder for a non-visible person, so the
 * group and chain counts never shift by viewer. The median year is the
 * exception that follows the viewer on purpose. It is formed over the years of
 * the birth and death facts (BIRT and DEAT) that the viewer can see on the
 * resolved people, so it does not reflect a year that the wire rows of the same
 * payload withhold.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class PartnershipReachRepository
{
    /**
     * Maximum number of people drawn into the rendered partnership-group graph. A
     * real partnership web can run to hundreds of nodes, which neither renders
     * legibly nor serialises cheaply; beyond the cap the report shows a connected
     * EXCERPT grown outward from the longest-chain nodes, while `totalCount`
     * still reports the group's real size so the reader knows the cluster is
     * larger than the drawing.
     */
    public const int NETWORK_CAP = 70;

    /**
     * @param Tree                      $tree                      The tree the statistics are computed for
     * @param PartnershipMapRepository  $partnershipMapRepository  Shared partnership-graph adjacency provider (FAMS spouse pairs)
     * @param GenerationDepthRepository $generationDepthRepository Provides the tree-wide max generation depth for the ratio
     */
    public function __construct(
        private Tree $tree,
        private PartnershipMapRepository $partnershipMapRepository,
        private GenerationDepthRepository $generationDepthRepository,
    ) {
    }

    /**
     * Tree-wide partnership-reach summary: the longest partnership chain resolved to
     * ordered {@see Individual} objects, the largest connected partnership group
     * (its people — excerpted to {@see NETWORK_CAP} when the real cluster is
     * larger — its internal edges, the longest path's xrefs, the max-degree hub,
     * its real and shown sizes, and the median birth+death year), and the
     * depth-vs-breadth ratio components. Returns `null` when no partnership group
     * reaches {@see PartnershipChains::MIN_GROUP_SIZE} people, since there is then no
     * chain to report.
     */
    public function summary(): ?PartnershipReachReport
    {
        $adjacency = $this->partnershipMapRepository->build();
        $largest   = PartnershipChains::largestGroup($adjacency);

        if ($largest === null) {
            return null;
        }

        // largestGroup() sorts largest-first with a deterministic tie-break, so
        // this is the largest connected partnership group together with its REAL
        // internal partnership-edge count over the whole group — the figure the foot
        // legend reports, independent of the excerpt the drawing is capped to.
        $members        = $largest['members'];
        $totalEdgeCount = $largest['edges'];

        // The reported chain MUST lie inside the reported group: compute the
        // longest path on an adjacency restricted to the largest group's members,
        // not graph-wide. A graph-wide longest path could land in a different
        // (smaller-node-count but more linear) component, which would leave
        // `chainIds` outside `nodes` and — worse — seed the over-cap excerpt BFS
        // with chain nodes absent from the group, collapsing the shown excerpt to
        // empty. Scoping to `$members` keeps the chain a sub-path of the group.
        $groupAdjacency = $this->subAdjacency($adjacency, $members);
        $longestChain   = PartnershipChains::longestChain($groupAdjacency);
        $hub            = $this->highestDegreeMember($adjacency, $members);

        // Excerpt the rendered group when the real cluster overruns the cap: keep
        // the longest-chain nodes (always shown), grow outward in deterministic
        // smallest-xref order until the cap, and keep only the edges among the
        // shown nodes. Below the cap this is a no-op — every member is shown with
        // every internal edge. The selection is pure graph logic, so it lives in
        // the unit-tested {@see PartnershipChains::excerpt()} calc, not here.
        $excerpt      = PartnershipChains::excerpt($adjacency, $members, $longestChain, self::NETWORK_CAP);
        $shownMembers = $excerpt['members'];
        $shownEdges   = $excerpt['edges'];

        // Resolve every group member from a single bulk gedcom fetch, never one
        // IndividualFactory::make() per id. The members are a superset of the
        // shown excerpt and of the chain, so the same fetch serves all of them.
        $gedcomByXref = GedcomByXref::fetch($this->tree, $members);

        // The median is formed over the years the viewer can see on the resolved
        // people, the same host accessors the wire rows use, so it never reflects
        // a year that the rows of the same payload withhold.
        $visibleYears = [];

        foreach ($this->resolveIndividuals($members, $gedcomByXref) as $member) {
            array_push($visibleYears, ...$this->visibleLifeYears($member));
        }

        $medianYear = PartnershipChains::medianYear($visibleYears);

        return new PartnershipReachReport(
            longestChainLength: count($longestChain),
            chain: $this->resolveIndividuals($longestChain, $gedcomByXref),
            group: new PartnershipGroupExcerpt(
                nodes: $this->resolveIndividuals($shownMembers, $gedcomByXref),
                edges: $shownEdges,
                chainIds: $longestChain,
                hubId: $hub['id'],
                hubDegree: $hub['degree'],
                totalCount: count($members),
                totalEdgeCount: $totalEdgeCount,
                shownCount: count($shownMembers),
                medianYear: $medianYear,
            ),
            depthPath: $this->generationDepthRepository->summary()->maxDepth,
            breadthChain: count($longestChain),
        );
    }

    /**
     * Restrict the full partnership adjacency to one member set: every member keeps
     * only the neighbours that are themselves members. Since `$members` is a
     * connected component, no internal edge is lost — the restriction merely
     * drops edges to nodes outside the set (there are none for a whole
     * component), giving a self-contained sub-graph whose longest path is
     * guaranteed to lie within the set.
     *
     * @param array<array-key, list<string>> $adjacency Symmetric `xref → [spouse-xref, …]` map
     * @param list<string>                   $members   The people to keep
     *
     * @return array<array-key, list<string>>
     */
    private function subAdjacency(array $adjacency, array $members): array
    {
        $memberSet = array_fill_keys($members, true);

        $sub = [];

        foreach ($members as $member) {
            $neighbours = [];

            foreach ($adjacency[$member] ?? [] as $neighbour) {
                if (isset($memberSet[$neighbour])) {
                    $neighbours[] = $neighbour;
                }
            }

            $sub[$member] = $neighbours;
        }

        return $sub;
    }

    /**
     * The group member with the highest partnership degree (most distinct
     * spouses) — the hub of the cluster — together with that real degree over the
     * WHOLE group. The degree travels with the xref so the hub label and the
     * "%s partnerships" tooltip report the cluster-wide figure, never the lower
     * edge count of a capped excerpt. Ties are broken by the byte-order-smallest
     * xref so the pick is deterministic across reloads.
     *
     * @param array<array-key, list<string>> $adjacency Symmetric `xref → [spouse-xref, …]` map
     * @param list<string>                   $members   The group's people, byte-order sorted
     *
     * @return array{id: string, degree: int} The hub xref and its real partnership degree
     */
    private function highestDegreeMember(array $adjacency, array $members): array
    {
        $hubId     = $members[0] ?? '';
        $maxDegree = -1;

        foreach ($members as $member) {
            $degree = count($adjacency[$member] ?? []);

            // A strictly greater degree always wins; an equal degree keeps the
            // byte-order-smaller xref. The members arrive byte-order sorted, so
            // the first-seen of an equal-degree run is already the smallest, but
            // the explicit tie-break keeps the rule order-independent.
            if (
                ($degree > $maxDegree)
                || (($degree === $maxDegree) && (strcmp($member, $hubId) < 0))
            ) {
                $maxDegree = $degree;
                $hubId     = $member;
            }
        }

        return [
            'id'     => $hubId,
            'degree' => $maxDegree,
        ];
    }

    /**
     * The Gregorian birth and death years of one person, as far as the current
     * viewer may see them. The years come from the birth and death facts (BIRT
     * and DEAT), read through the privacy-aware fact path, so a record the viewer
     * cannot see and a restricted BIRT or DEAT fact contribute nothing. The
     * fallback events of the host date accessors (christening, burial and the
     * like) are no source. Each person gives at most ONE birth and ONE death
     * year, the lower bound over the visible facts of that tag (the date with the
     * smallest minimum Julian day), converted to the Gregorian scale via
     * {@see GregorianDate::year()} so a non-Gregorian calendar stays comparable.
     * An undated fact contributes nothing.
     *
     * @param Individual $individual The resolved group member
     *
     * @return list<int>
     */
    private function visibleLifeYears(Individual $individual): array
    {
        $years = [];

        foreach (['BIRT', 'DEAT'] as $tag) {
            $lowerBound = null;

            foreach ($individual->facts([$tag]) as $fact) {
                $date = $fact->date();

                if (!$date->isOK()) {
                    continue;
                }

                $candidate = $date->minimumDate();

                if (
                    ($lowerBound === null)
                    || ($candidate->minimumJulianDay() < $lowerBound->minimumJulianDay())
                ) {
                    $lowerBound = $candidate;
                }
            }

            if ($lowerBound !== null) {
                $years[] = GregorianDate::year($lowerBound->format('%@'), $lowerBound->year, $lowerBound->minimumJulianDay());
            }
        }

        return $years;
    }

    /**
     * Resolve an ordered xref list to live {@see Individual} objects, handing
     * each the pre-fetched gedcom so the factory skips its own `SELECT i_gedcom`.
     * A non-visible person stays in the list (their `fullName()` privatises to
     * "Private"); an xref that resolves to no record is dropped.
     *
     * @param list<string>             $xrefs        The xrefs to resolve, in order
     * @param array<array-key, string> $gedcomByXref Pre-fetched `xref → gedcom` map
     *
     * @return list<Individual>
     */
    private function resolveIndividuals(array $xrefs, array $gedcomByXref): array
    {
        $resolved = [];

        foreach ($xrefs as $xref) {
            $individual = Registry::individualFactory()->make($xref, $this->tree, $gedcomByXref[$xref] ?? null);

            if ($individual instanceof Individual) {
                $resolved[] = $individual;
            }
        }

        return $resolved;
    }
}
