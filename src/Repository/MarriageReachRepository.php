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
use MagicSunday\Webtrees\Statistic\Model\Tree\MarriageGroupExcerpt;
use MagicSunday\Webtrees\Statistic\Model\Tree\MarriageReachReport;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Calc\MarriageChains;
use MagicSunday\Webtrees\Statistic\Support\Database\ChunkedWhereIn;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Database\GedcomByXref;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function array_fill_keys;
use function array_unique;
use function array_values;
use function count;
use function strcmp;

/**
 * Marriage-reach surfacing for the Family tab. Orchestrates the shared
 * marriage-graph adjacency ({@see MarriageMapRepository}) and the pure
 * connected-component / longest-path calculator ({@see MarriageChains}) into a
 * renderable {@see MarriageReachReport}: the longest unbroken marriage chain,
 * the single largest connected marriage group (with its internal edges, its
 * max-degree hub, and a median birth+death year), and the depth-vs-breadth
 * ratio components.
 *
 * Person resolution goes through ONE bulk gedcom fetch ({@see
 * ChunkedWhereIn}) — exactly the {@see GenerationDepthRepository::summary()}
 * discipline — so the report's xref-to-{@see Individual} step issues a single
 * chunked query rather than one `IndividualFactory::make()` round-trip per
 * person. Privacy follows the same convention: every node stays in place and
 * `Individual::fullName()` substitutes the "Private" placeholder for a
 * non-visible person, so the group/chain counts never shift by viewer.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MarriageReachRepository
{
    /**
     * Maximum number of people drawn into the rendered marriage-group graph. A
     * real intermarriage web can run to hundreds of nodes, which neither renders
     * legibly nor serialises cheaply; beyond the cap the report shows a connected
     * EXCERPT grown outward from the longest-chain nodes, while `totalCount`
     * still reports the group's real size so the reader knows the cluster is
     * larger than the drawing.
     */
    public const int NETWORK_CAP = 70;

    /**
     * @param Tree                      $tree                      The tree the statistics are computed for
     * @param MarriageMapRepository     $marriageMapRepository     Shared marriage-graph adjacency provider (FAMS spouse pairs)
     * @param GenerationDepthRepository $generationDepthRepository Provides the tree-wide max generation depth for the ratio
     */
    public function __construct(
        private Tree $tree,
        private MarriageMapRepository $marriageMapRepository,
        private GenerationDepthRepository $generationDepthRepository,
    ) {
    }

    /**
     * Tree-wide marriage-reach summary: the longest marriage chain resolved to
     * ordered {@see Individual} objects, the largest connected marriage group
     * (its people — excerpted to {@see NETWORK_CAP} when the real cluster is
     * larger — its internal edges, the longest path's xrefs, the max-degree hub,
     * its real and shown sizes, and the median birth+death year), and the
     * depth-vs-breadth ratio components. Returns `null` when no marriage group
     * reaches {@see MarriageChains::MIN_GROUP_SIZE} people, since there is then no
     * chain to report.
     */
    public function summary(): ?MarriageReachReport
    {
        $adjacency = $this->marriageMapRepository->build();
        $largest   = MarriageChains::largestGroup($adjacency);

        if ($largest === null) {
            return null;
        }

        // largestGroup() sorts largest-first with a deterministic tie-break, so
        // this is the largest connected marriage group together with its REAL
        // internal marriage-edge count over the whole group — the figure the foot
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
        $longestChain   = MarriageChains::longestChain($groupAdjacency);
        $hub            = $this->highestDegreeMember($adjacency, $members);

        // Excerpt the rendered group when the real cluster overruns the cap: keep
        // the longest-chain nodes (always shown), grow outward in deterministic
        // smallest-xref order until the cap, and keep only the edges among the
        // shown nodes. Below the cap this is a no-op — every member is shown with
        // every internal edge. The selection is pure graph logic, so it lives in
        // the unit-tested {@see MarriageChains::excerpt()} calc, not here.
        $excerpt      = MarriageChains::excerpt($adjacency, $members, $longestChain, self::NETWORK_CAP);
        $shownMembers = $excerpt['members'];
        $shownEdges   = $excerpt['edges'];

        $medianYear = MarriageChains::medianYear(
            $this->birthDeathYears($members),
        );

        // Resolve every shown node AND every chain member from a single bulk
        // gedcom fetch — never one IndividualFactory::make() per id. The union
        // keeps the fetched row set (and the placeholder budget) minimal when the
        // chain runs through the shown excerpt, as it normally does.
        $gedcomByXref = GedcomByXref::fetch(
            $this->tree,
            array_values(array_unique([...$shownMembers, ...$longestChain])),
        );

        return new MarriageReachReport(
            longestChainLength: count($longestChain),
            chain: $this->resolveIndividuals($longestChain, $gedcomByXref),
            group: new MarriageGroupExcerpt(
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
     * Restrict the full marriage adjacency to one member set: every member keeps
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
     * The group member with the highest marriage degree (most distinct
     * spouses) — the hub of the cluster — together with that real degree over the
     * WHOLE group. The degree travels with the xref so the hub label and the
     * "%s marriages" tooltip report the cluster-wide figure, never the lower
     * edge count of a capped excerpt. Ties are broken by the byte-order-smallest
     * xref so the pick is deterministic across reloads.
     *
     * @param array<array-key, list<string>> $adjacency Symmetric `xref → [spouse-xref, …]` map
     * @param list<string>                   $members   The group's people, byte-order sorted
     *
     * @return array{id: string, degree: int} The hub xref and its real marriage degree
     */
    private function highestDegreeMember(array $adjacency, array $members): array
    {
        $hubId     = $members[0];
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
     * The combined multiset of birth AND death years of the given members,
     * fed to {@see MarriageChains::medianYear()}. Each member contributes at most
     * ONE birth and ONE death year — the lower-bound representative of an
     * imprecise date, not both encoded rows — and the value is converted to the
     * Gregorian scale via {@see GregorianDate::year()}, so a non-Gregorian
     * calendar contributes a comparable year rather than its native one. A member
     * with neither a dated birth nor death simply contributes nothing.
     *
     * @param list<string> $members The group's people whose years to collect
     *
     * @return list<int>
     */
    private function birthDeathYears(array $members): array
    {
        return [
            ...$this->factYears($members, 'BIRT'),
            ...$this->factYears($members, 'DEAT'),
        ];
    }

    /**
     * The Gregorian years of one dated fact for the given members — exactly ONE
     * representative year per person. webtrees writes TWO `dates` rows for a
     * genuine two-bound range (`BET … AND …`, `FROM … TO …`): a lower-bound row
     * and an upper-bound row carrying DISTINCT years (a `BET 1889 AND 1891` birth
     * stores 1889 and 1891). Feeding both into the multiset would DOUBLE-WEIGHT
     * that person in this per-person "median life year" statistic. (A year-only /
     * `ABT` / month-only date keeps a single row, so it is unaffected either way.)
     * Each member is therefore collapsed to its lower-bound row via the shared
     * {@see DedupedEventDates} query — the minimum-`d_julianday1` representative,
     * exactly the {@see BirthDeathPairsQuery} pin — and the year is derived from
     * that pinned row's `d_type` / `d_year` so the value stays calendar-coherent.
     * The deduped query already emits one row per `d_gid`; {@see ChunkedWhereIn}
     * only narrows it to the requested members.
     *
     * @param list<string> $members The people whose years to read
     * @param string       $fact    The GEDCOM fact tag (`BIRT` / `DEAT`)
     *
     * @return list<int>
     */
    private function factYears(array $members, string $fact): array
    {
        if ($members === []) {
            return [];
        }

        $rows = ChunkedWhereIn::get(DedupedEventDates::query($this->tree, $fact), 'd_gid', $members);

        $years = [];

        foreach ($rows as $row) {
            $year = GregorianDate::year(
                RowCast::string($row, 'd_type'),
                RowCast::int($row, 'd_year'),
                RowCast::int($row, 'd_julianday1'),
            );

            if ($year === 0) {
                continue;
            }

            $years[] = $year;
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
