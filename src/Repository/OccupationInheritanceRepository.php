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
 * one contributes one or more weighted links from a parent's occupation to the
 * child's — the data behind the Sankey diagram on the Overview tab. Both
 * parents are considered, so a child of two working parents feeds each parent's
 * trades; a child whose father and mother share a trade is counted only once
 * for that flow, never twice.
 *
 * EVERY recorded `1 OCCU` line is read per person and the full cross-product of
 * (parent trade → child trade) is counted, so a person's secondary trade
 * surfaces as both a source and a target — a father who was a Farmer AND a
 * Carter feeds both trades to a child recorded the same way. Each distinct pair
 * is counted once per child, so two parents sharing a trade (or the same trade
 * repeated across a person's own `1 OCCU` lines) never doubles a band. Composite
 * `1 OCCU` values (`Trade A und Trade B` on one line) are NOT split — the whole
 * string is one trade; recording distinct trades on separate lines is the
 * supported form. Pairs are dropped whenever either side lacks an occupation —
 * the diagram answers "given both worked, did the trade carry over?", so an
 * unknown end would only add noise. Occupations are case-folded before counting
 * so spelling variants (`Smith` / `smith`) merge; the first-seen casing becomes
 * the display label.
 *
 * Privacy contract (matching the sibling migration Sankey): occupation node
 * labels are AGGREGATE facts and are not individually privacy-gated — a trade
 * practised by a privacy-restricted parent or child can appear as a node label,
 * exactly as the statistics module surfaces aggregate counts everywhere else.
 * Only the per-flow sample children are routed through the privacy layer (see
 * {@see SankeySampleResolver}): a child the current user may not see is dropped
 * from the tooltip, while the flow weight still counts them.
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
     * full cross-product of their (parent-occupation → child-occupation) pairs is
     * counted, each distinct pair once per child. A child is counted at most once
     * per flow, so two parents sharing the same trade do not double the child
     * into a single band. Only the top-N links by weight
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

            // Collapse the child's `1 OCCU` lines to their DISTINCT case-folded
            // trades up front (folded key → first-seen casing): a person who
            // repeats a trade across several lines must not iterate the
            // cross-product below redundantly, which bounds the pairing to the
            // handful of trades a person actually has rather than the raw line
            // count.
            $childTrades = $this->uniqueTrades(
                GedcomScanner::extractAllTagValues($childGedcom, 'OCCU'),
            );

            if ($childTrades === []) {
                continue;
            }

            $parents = $parentOf[$childXref] ?? null;

            if ($parents === null) {
                continue;
            }

            // Track the flow keys already counted for THIS child so a given
            // (parent-trade → child-trade) pair is counted once even when both
            // the father and the mother carry that same trade.
            $seenKeys = [];

            // The sample is always the child, so a child feeding several flows
            // (two differently-employed parents) resolves through the privacy
            // layer at most once; the result (a SankeySample or a null the user
            // may not see) is memoised and reused across that child's flows.
            $childSample   = null;
            $childResolved = false;

            foreach ($parents as $parentXref) {
                if ($parentXref === null) {
                    continue;
                }

                $parentGedcom = $gedcomByXref[$parentXref] ?? null;

                if ($parentGedcom === null) {
                    continue;
                }

                $parentTrades = $this->uniqueTrades(
                    GedcomScanner::extractAllTagValues($parentGedcom, 'OCCU'),
                );

                if ($parentTrades === []) {
                    continue;
                }

                // Cross-product: pair every DISTINCT parent trade against every
                // distinct child trade so a secondary occupation surfaces as both
                // a source and a target. Each pair is still counted once per
                // child via $seenKeys, since the father and mother may share a
                // trade.
                foreach ($parentTrades as $parentKey => $parentOccupation) {
                    foreach ($childTrades as $childKey => $childOccupation) {
                        $key = $parentKey . "\0" . $childKey;

                        if (isset($seenKeys[$key])) {
                            continue;
                        }

                        $seenKeys[$key] = true;

                        $display[$parentKey] ??= $parentOccupation;
                        $display[$childKey]  ??= $childOccupation;

                        $linkWeight[$key] = ($linkWeight[$key] ?? 0) + 1;
                        $linkSamples[$key] ??= [];

                        // Resolve the sample child through the privacy layer; a
                        // null result (a record the user cannot see) is skipped
                        // and the next child fills the slot — see
                        // SankeySampleResolver::resolve().
                        if (count($linkSamples[$key]) < self::SAMPLES_PER_FLOW) {
                            if (!$childResolved) {
                                $childSample   = SankeySampleResolver::resolve($this->tree, $childXref, $childGedcom);
                                $childResolved = true;
                            }

                            if ($childSample instanceof SankeySample) {
                                $linkSamples[$key][] = $childSample;
                            }
                        }
                    }
                }
            }
        }

        // Occupations were case-folded for counting, so the assembler is given
        // the key → first-seen-casing map to surface readable node labels.
        return BipartiteSankeyAssembler::assemble($linkWeight, $linkSamples, $topLinks, $display);
    }

    /**
     * Collapse a person's raw `1 OCCU` values to their DISTINCT trades, keyed by
     * the case-folded trade and mapped to the first-seen display casing. Reading
     * every OCCU line means a person who records the same trade on several lines
     * would otherwise pair it into the cross-product once per duplicate; folding
     * to distinct trades here bounds the pairing to the trades a person actually
     * holds and merges casing variants (`Smith` / `smith`) in one place.
     *
     * @param list<string> $occupations The raw `1 OCCU` values in recorded order
     *
     * @return array<string, string> Case-folded trade key → first-seen display casing
     */
    private function uniqueTrades(array $occupations): array
    {
        $trades = [];

        foreach ($occupations as $occupation) {
            $trades[mb_strtolower($occupation)] ??= $occupation;
        }

        return $trades;
    }
}
