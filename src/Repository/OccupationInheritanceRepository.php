<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

// The Sankey-toolkit import block below converges with the sibling
// MigrationRepository — both legitimately orchestrate the same toolkit, with no
// shared logic to extract (disjoint constructor deps and query bodies). Exempt
// only the imports from clone detection so the two method bodies stay covered.
// jscpd:ignore-start
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\Builder;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyFlowsPayload;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeySample;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Sankey\BipartiteSankeyAssembler;
use MagicSunday\Webtrees\Statistic\Support\Sankey\SankeySampleResolver;

use function count;
use function mb_strtolower;

// jscpd:ignore-end

/**
 * Aggregates parent → child occupation inheritance across the tree. Every child
 * who carries an occupation and whose resolvable father or mother also carries
 * one contributes a single weighted link from the parent's occupation to the
 * child's — the data behind the Sankey diagram on the Overview tab. Both
 * parents are considered, so a child of two working parents can feed two
 * distinct flows (one per parent trade); a child whose father and mother share
 * the same trade is counted only once for that flow, never twice.
 *
 * Only the FIRST recorded `1 OCCU` line is read per person: a person with
 * several occupations has one primary trade, and pairing every parent trade
 * against every child trade would inflate one succession into a cross-product
 * of phantom flows. Pairs are dropped whenever either side lacks an occupation
 * — the diagram answers "given both worked, did the trade carry over?", so an
 * unknown end would only add noise. Occupations are case-folded before counting
 * so spelling variants (`Smith` / `smith`) merge; the first-seen casing becomes
 * the display label.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class OccupationInheritanceRepository
{
    /**
     * Maximum number of sample children surfaced per flow on hover. Three keeps
     * the tooltip to a single line per name while still hinting at who is behind
     * a band.
     */
    private const int SAMPLES_PER_FLOW = 3;

    /**
     * @param Tree                $tree      The tree the statistics are computed for
     * @param ParentMapRepository $parentMap Shared child → [father, mother] resolver
     */
    public function __construct(
        private Tree $tree,
        private ParentMapRepository $parentMap,
    ) {
    }

    /**
     * Build the Sankey payload describing parent → child occupation flows. For
     * every individual that carries an occupation, both parents are resolved via
     * the shared parent map; for each parent that also carries an occupation the
     * (parent-occupation → child-occupation) pair is counted once. A child is
     * counted at most once per flow, so two parents sharing the same trade do
     * not double the child into a single band. Only the top-N links by weight
     * are returned so the diagram stays legible. The weighted flow map is handed
     * to {@see BipartiteSankeyAssembler}, which lays the parent and child sides
     * out as disjoint node columns (a trade that is both passed down and
     * inherited becomes two separate nodes, avoiding the d3-sankey "circular
     * link" error).
     *
     * Each link carries up to {@see SAMPLES_PER_FLOW} sample children (`name`,
     * `xref`) so the consumer's hover tooltip can surface representative people
     * behind every inheritance path.
     *
     * @param int $topLinks Maximum number of distinct flows to retain
     */
    public function occupationInheritance(int $topLinks): SankeyFlowsPayload
    {
        // ORDER BY i_id pins iteration to the (lexicographic) xref order so the
        // SAMPLES_PER_FLOW cap always picks the same representatives across
        // page loads, even after table-level events (OPTIMIZE TABLE,
        // replication, index changes).
        //
        // Restrict the loaded set to individuals carrying a `1 OCCU` line: only
        // an OCCU-bearer can be a child in a flow, and a parent without OCCU is
        // dropped anyway, so the anchored-LIKE prefilter keeps exactly the set
        // both the child iteration and the parent lookup need — but it spares
        // the whole-tree GEDCOM blob transfer (and the resident xref → blob map)
        // on the typically small OCCU-bearing subpopulation rather than every
        // individual.
        $occuPatterns = GedcomScanner::anchoredLikePatterns('OCCU');

        $rows = TreeScope::table($this->tree, 'individuals')
            ->where(static function (Builder $query) use ($occuPatterns): void {
                foreach ($occuPatterns as $pattern) {
                    $query->orWhere('i_gedcom', 'like', $pattern);
                }
            })
            ->orderBy('i_id')
            ->select(['i_id AS xref', 'i_gedcom AS gedcom'])
            ->get();

        // First pass: index every individual's GEDCOM by xref so a child's
        // parent record can be looked up without a second query.
        $gedcomByXref = [];

        foreach ($rows as $row) {
            $gedcomByXref[RowCast::string($row, 'xref')] = RowCast::string($row, 'gedcom');
        }

        $parentOf = $this->parentMap->build();

        // Count every (parent-occupation → child-occupation) pair once per
        // child, and remember up to SAMPLES_PER_FLOW representative children per
        // flow.
        $linkWeight  = [];
        $linkSamples = [];
        $display     = [];

        foreach ($rows as $row) {
            $childXref   = RowCast::string($row, 'xref');
            $childGedcom = RowCast::string($row, 'gedcom');

            $childOccupation = GedcomScanner::extractFirstTagValue($childGedcom, 'OCCU');

            if ($childOccupation === null) {
                continue;
            }

            $parents = $parentOf[$childXref] ?? null;

            if ($parents === null) {
                continue;
            }

            $childKey = mb_strtolower($childOccupation);

            // A child whose father and mother share the same trade must feed the
            // band only once: track the flow keys already counted for THIS child
            // so the second parent of an identical trade is skipped.
            $seenKeys = [];

            foreach ($parents as $parentXref) {
                if ($parentXref === null) {
                    continue;
                }

                $parentGedcom = $gedcomByXref[$parentXref] ?? null;

                if ($parentGedcom === null) {
                    continue;
                }

                $parentOccupation = GedcomScanner::extractFirstTagValue($parentGedcom, 'OCCU');

                if ($parentOccupation === null) {
                    continue;
                }

                $parentKey = mb_strtolower($parentOccupation);
                $key       = $parentKey . "\0" . $childKey;

                if (isset($seenKeys[$key])) {
                    continue;
                }

                $seenKeys[$key] = true;

                $display[$parentKey] ??= $parentOccupation;
                $display[$childKey]  ??= $childOccupation;

                $linkWeight[$key] = ($linkWeight[$key] ?? 0) + 1;
                $linkSamples[$key] ??= [];

                // Resolve the sample child through the privacy layer; a null
                // result (a record the user cannot see) is skipped and the next
                // child fills the slot — see SankeySampleResolver::resolve().
                if (count($linkSamples[$key]) < self::SAMPLES_PER_FLOW) {
                    $sample = SankeySampleResolver::resolve($this->tree, $childXref, $childGedcom);

                    if ($sample instanceof SankeySample) {
                        $linkSamples[$key][] = $sample;
                    }
                }
            }
        }

        // Occupations were case-folded for counting, so the assembler is given
        // the key → first-seen-casing map to surface readable node labels.
        return BipartiteSankeyAssembler::assemble($linkWeight, $linkSamples, $topLinks, $display);
    }
}
