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
use MagicSunday\Webtrees\Statistic\Normalization\Contract\OccupationNormalizerInterface;
use MagicSunday\Webtrees\Statistic\Normalization\OccupationFolding;
use MagicSunday\Webtrees\Statistic\Normalization\Support\ContentLanguage;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Sankey\BipartiteSankeyAssembler;
use MagicSunday\Webtrees\Statistic\Support\Sankey\SankeySampleResolver;

use function array_keys;
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
 * unknown end would only add noise. Occupations are folded before counting so
 * variants of one trade merge into a single flow: casing (`Smith` / `smith`)
 * always, and — when an occupation-standardization provider is installed —
 * spelling and language variants too (see {@see OccupationFolding}); the
 * first-seen label of a fold becomes its display label.
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
     * Legibility ceiling on the number of flows the Overview diagram renders. A
     * Sankey beyond roughly this many flows degrades into an unreadable hairball
     * regardless of tree size, so this is a fixed property of the medium, not of
     * the data — the adaptive part is {@see self::MIN_FLOW_WEIGHT}, which lets a
     * sparse tree show fewer flows and a dense one fill up to this cap.
     */
    private const int MAX_FLOWS = 15;

    /**
     * Minimum weight a flow must reach to appear in the Overview diagram. An
     * inheritance PATTERN requires recurrence: a single parent → child pair is
     * an anecdote, and the long tail of such one-offs would otherwise swamp the
     * diagram (in a real tree they outnumber the recurring flows by an order of
     * magnitude). Dropping everything below this weight is the data-driven floor
     * that makes the shown-flow count adapt to a tree's density; a tree with no
     * recurring occupational inheritance yields an honestly empty diagram.
     */
    private const int MIN_FLOW_WEIGHT = 2;

    /**
     * @param Tree                          $tree       The tree the statistics are computed for
     * @param ParentMapRepository           $parentMap  Shared child → [father, mother] resolver
     * @param OccupationNormalizerInterface $normalizer Resolves raw `1 OCCU` values to standardized trades so variants merge; the identity default leaves the aggregation unchanged
     */
    public function __construct(
        private Tree $tree,
        private ParentMapRepository $parentMap,
        private OccupationNormalizerInterface $normalizer,
    ) {
    }

    /**
     * Build the Sankey payload describing parent → child occupation flows. For
     * every individual that carries an occupation, both parents are resolved via
     * the shared parent map; for each parent that also carries an occupation the
     * full cross-product of their (parent-occupation → child-occupation) pairs is
     * counted, each distinct pair once per child. A child is counted at most once
     * per flow, so two parents sharing the same trade do not double the child
     * into a single band.
     *
     * Two display thresholds shape what the Overview diagram shows, both
     * defaulting to the repository's own policy constants. Flows lighter than
     * `$minWeight` are dropped first — an inheritance pattern must recur across
     * at least that many children, so the long tail of one-off pairs never reaches
     * the chart (a tree with no recurring flow yields an empty diagram). The
     * survivors are then capped at `$maxFlows` by weight so the diagram stays
     * legible. The weighted flow map is handed
     * to {@see BipartiteSankeyAssembler}, which lays the parent and child sides
     * out as disjoint node columns (a trade that is both passed down and
     * inherited becomes two separate nodes, avoiding the d3-sankey "circular
     * link" error).
     *
     * Each link carries up to {@see SAMPLES_PER_FLOW} sample children (`name`,
     * `xref`) so the consumer's hover tooltip can surface representative people
     * behind every inheritance path.
     *
     * @param int $maxFlows  Legibility ceiling on the number of flows returned
     * @param int $minWeight Minimum weight a flow must reach to be shown; 1 returns every flow unfiltered
     */
    public function occupationInheritance(
        int $maxFlows = self::MAX_FLOWS,
        int $minWeight = self::MIN_FLOW_WEIGHT,
    ): SankeyFlowsPayload {
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

        // First pass: read every individual's raw `1 OCCU` lines once, keyed by
        // xref, while collecting the tree-wide DISTINCT set of raw values.
        // Parsing here — rather than again for every child and every parent in
        // the main loop — means a parent shared by several children is parsed a
        // single time, and the resident map holds the small trade lists instead
        // of the full GEDCOM blobs.
        $occupationsByXref = [];
        $distinctRaw       = [];

        foreach ($rows as $row) {
            $occupations                                      = GedcomScanner::extractAllTagValues(RowCast::string($row, 'gedcom'), 'OCCU');
            $occupationsByXref[RowCast::string($row, 'xref')] = $occupations;

            foreach ($occupations as $occupation) {
                $distinctRaw[$occupation] = true;
            }
        }

        // Resolve the whole distinct set through the normalizer in one batch, so
        // a standardization provider initialises its data a single time. Every
        // raw value maps to its (fold key, display label); unknown values keep
        // the pre-normalization case-fold, so the identity default leaves the
        // aggregation byte-identical.
        $folds = OccupationFolding::map(array_keys($distinctRaw), $this->normalizer, ContentLanguage::tag());

        // Fold every person's raw values to their DISTINCT trades under the
        // resolved keys.
        $tradesByXref = [];

        foreach ($occupationsByXref as $xref => $occupations) {
            $tradesByXref[$xref] = $this->uniqueTrades($occupations, $folds);
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

            // The child's distinct trades were folded in the first pass; the raw
            // GEDCOM is still read below for the privacy-gated sample.
            $childTrades = $tradesByXref[$childXref] ?? [];

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

                // The parent's distinct trades were folded in the first pass, so
                // a parent shared by several children is not re-parsed per child.
                $parentTrades = $tradesByXref[$parentXref] ?? [];

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

        // Drop one-off flows below the display floor: an inheritance pattern
        // must recur across at least $minWeight children. $minWeight === 1 means
        // "every flow", so the aggregation stays byte-identical for the
        // unfiltered call the data-layer tests use. An emptied map assembles to
        // the empty payload — the honest "no recurring inheritance" diagram.
        if ($minWeight > 1) {
            foreach ($linkWeight as $key => $weight) {
                if ($weight < $minWeight) {
                    unset($linkWeight[$key], $linkSamples[$key]);
                }
            }
        }

        // Occupations were case-folded for counting, so the assembler is given
        // the key → first-seen-casing map to surface readable node labels.
        return BipartiteSankeyAssembler::assemble($linkWeight, $linkSamples, $maxFlows, $display);
    }

    /**
     * Collapse a person's raw `1 OCCU` values to their DISTINCT trades under the
     * pre-resolved fold map, mapped to the first-seen display label. Reading
     * every OCCU line means a person who records the same trade on several lines
     * would otherwise pair it into the cross-product once per duplicate; folding
     * to distinct trades here bounds the pairing to the trades a person actually
     * holds and merges variants — casing (`Smith` / `smith`), and, once a
     * standardization provider is present, spelling and language too — that
     * share a fold key.
     *
     * @param list<string>                               $occupations The raw `1 OCCU` values in recorded order
     * @param array<string, array{0: string, 1: string}> $folds       Raw value → [fold key, display label] from {@see OccupationFolding::map()}
     *
     * @return array<string, string> Fold key → first-seen display label
     */
    private function uniqueTrades(array $occupations, array $folds): array
    {
        $trades = [];

        foreach ($occupations as $occupation) {
            [$key, $label] = $folds[$occupation] ?? [mb_strtolower($occupation), $occupation];
            $trades[$key] ??= $label;
        }

        return $trades;
    }
}
