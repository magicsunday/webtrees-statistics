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
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyFlowsPayload;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeySample;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Sankey\BipartiteSankeyAssembler;

use function count;
use function mb_strtolower;

/**
 * Aggregates father → son occupation inheritance across the tree. Every male
 * child who carries an occupation and whose resolvable father also carries one
 * contributes a single weighted link from the father's occupation to the son's
 * — the data behind the Sankey diagram on the Overview tab.
 *
 * Only the FIRST recorded `1 OCCU` line is read per person: a person with
 * several occupations has one primary trade, and pairing every father trade
 * against every son trade would inflate one biological succession into a
 * cross-product of phantom flows. Pairs are dropped whenever either side lacks
 * an occupation — the diagram answers "given both worked, did the trade carry
 * over?", so an unknown end would only add noise. Occupations are case-folded
 * before counting so spelling variants (`Smith` / `smith`) merge; the first-
 * seen casing becomes the display label.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class OccupationInheritanceRepository
{
    /**
     * Maximum number of sample sons surfaced per flow on hover. Three keeps the
     * tooltip to a single line per name while still hinting at who is behind a
     * band.
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
     * Build the Sankey payload describing father → son occupation flows. For
     * every male individual that carries an occupation, the father is resolved
     * via the shared parent map; when the father also carries an occupation the
     * (father-occupation → son-occupation) pair is counted once. Only the
     * top-N links by weight are returned so the diagram stays legible. The
     * weighted flow map is handed to {@see BipartiteSankeyAssembler}, which lays
     * the father and son sides out as disjoint node columns (a trade that is
     * both passed down and inherited becomes two separate nodes, avoiding the
     * d3-sankey "circular link" error).
     *
     * Each link carries up to {@see SAMPLES_PER_FLOW} sample sons (`name`,
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
        $rows = TreeScope::table($this->tree, 'individuals')
            ->orderBy('i_id')
            ->select(['i_id AS xref', 'i_gedcom AS gedcom'])
            ->get();

        // First pass: index every individual's GEDCOM by xref so a son's father
        // record can be looked up without a second query.
        $gedcomByXref = [];

        foreach ($rows as $row) {
            $gedcomByXref[RowCast::string($row, 'xref')] = RowCast::string($row, 'gedcom');
        }

        $parentOf = $this->parentMap->build();

        // Count every (father-occupation → son-occupation) pair once per son,
        // and remember up to SAMPLES_PER_FLOW representative sons per flow.
        $linkWeight  = [];
        $linkSamples = [];
        $display     = [];

        foreach ($rows as $row) {
            $sonXref   = RowCast::string($row, 'xref');
            $sonGedcom = RowCast::string($row, 'gedcom');

            if (GedcomScanner::extractFirstTagValue($sonGedcom, 'SEX') !== 'M') {
                continue;
            }

            $sonOccupation = GedcomScanner::extractFirstTagValue($sonGedcom, 'OCCU');

            if ($sonOccupation === null) {
                continue;
            }

            $fatherXref = $parentOf[$sonXref][0] ?? null;

            if ($fatherXref === null) {
                continue;
            }

            $fatherGedcom = $gedcomByXref[$fatherXref] ?? null;

            if ($fatherGedcom === null) {
                continue;
            }

            $fatherOccupation = GedcomScanner::extractFirstTagValue($fatherGedcom, 'OCCU');

            if ($fatherOccupation === null) {
                continue;
            }

            $fatherKey = mb_strtolower($fatherOccupation);
            $sonKey    = mb_strtolower($sonOccupation);

            $display[$fatherKey] ??= $fatherOccupation;
            $display[$sonKey]    ??= $sonOccupation;

            $key              = $fatherKey . "\0" . $sonKey;
            $linkWeight[$key] = ($linkWeight[$key] ?? 0) + 1;
            $linkSamples[$key] ??= [];

            if (count($linkSamples[$key]) < self::SAMPLES_PER_FLOW) {
                $linkSamples[$key][] = new SankeySample(
                    name: GedcomScanner::extractPrimaryName($sonGedcom),
                    xref: $sonXref,
                );
            }
        }

        // Occupations were case-folded for counting, so the assembler is given
        // the key → first-seen-casing map to surface readable node labels.
        return BipartiteSankeyAssembler::assemble($linkWeight, $linkSamples, $topLinks, $display);
    }
}
