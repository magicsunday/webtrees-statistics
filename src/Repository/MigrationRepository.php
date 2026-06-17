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
use Illuminate\Database\Query\Builder;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyFlowsPayload;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeySample;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\IsoCountryMap;
use MagicSunday\Webtrees\Statistic\Support\Sankey\BipartiteSankeyAssembler;
use MagicSunday\Webtrees\Statistic\Support\Sankey\SankeySampleResolver;

use function count;

/**
 * Aggregates birth → death country movements across the tree's individuals.
 * Each individual with both a birth-place country and a death-place country
 * contributes one weighted link to the Sankey diagram drawn on the Places tab.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MigrationRepository
{
    /**
     * Maximum number of sample individuals surfaced per flow on hover. Three is
     * the acceptance-criteria minimum from issue #12 and visually fits on one
     * tooltip line per name.
     */
    private const int SAMPLES_PER_FLOW = 3;

    /**
     * @param Tree          $tree   The tree the statistics are computed for
     * @param IsoCountryMap $isoMap Name → ISO-2 resolver + localised label provider
     */
    public function __construct(
        private Tree $tree,
        private IsoCountryMap $isoMap,
    ) {
    }

    /**
     * Build the Sankey payload describing country-level migrations between
     * birth and death. Same-country movements are dropped (no migration), as
     * are individuals missing either place. Only the top-N links by weight are
     * returned so the diagram stays legible on busy trees; their incident nodes
     * are added in encounter order.
     *
     * Source and target sides are kept as DISJOINT node sets — a node that
     * appears as both an origin and a destination (e.g. Germany → USA combined
     * with USA → Germany) shows up as two separate Sankey nodes, one in each
     * column. This is required because d3-sankey only lays out directed acyclic
     * graphs; folding such counter-flows onto a single node would create a
     * 2-cycle and throw a "circular link" error at render time.
     *
     * Each link carries up to {@see SAMPLES_PER_FLOW} sample individuals
     * (`name`, `xref`) so the consumer's hover tooltip can surface
     * representative people behind every migration path — the acceptance
     * criterion from issue #12.
     *
     * @param int $topLinks Maximum number of distinct flows to retain
     */
    public function flowsByCountry(int $topLinks): SankeyFlowsPayload
    {
        // ORDER BY i_id pins iteration to the (lexicographic) xref
        // order so the SAMPLES_PER_FLOW cap always picks the same
        // representatives across page loads, even after table-level
        // events (OPTIMIZE TABLE, replication, index changes).
        //
        // Only an individual carrying BOTH a `1 BIRT` and a `1 DEAT` line can
        // contribute a birth → death flow, so anchor-LIKE both events before
        // transferring blobs. An individual lacking either event can have no
        // origin or destination place, so the prefilter drops exactly what the
        // loop would skip — sparing the whole-tree GEDCOM scan on the
        // birth-and-death-bearing subset.
        $birthPatterns = GedcomScanner::anchoredLikePatterns('BIRT');
        $deathPatterns = GedcomScanner::anchoredLikePatterns('DEAT');

        // Stream with a cursor — each blob is parsed for its birth/death place
        // inside the loop and discarded; only the per-flow tallies and resolved
        // samples are retained.
        $rows = TreeScope::table($this->tree, 'individuals')
            ->where(static function (Builder $query) use ($birthPatterns): void {
                foreach ($birthPatterns as $pattern) {
                    $query->orWhere('i_gedcom', 'like', $pattern);
                }
            })
            ->where(static function (Builder $query) use ($deathPatterns): void {
                foreach ($deathPatterns as $pattern) {
                    $query->orWhere('i_gedcom', 'like', $pattern);
                }
            })
            ->orderBy('i_id')
            ->select(['i_id AS xref', 'i_gedcom AS gedcom'])
            ->cursor();

        // Count every (origin → destination) pair once per individual,
        // and remember up to SAMPLES_PER_FLOW representatives per flow.
        $linkWeight  = [];
        $linkSamples = [];

        foreach ($rows as $row) {
            $gedcom = RowCast::string($row, 'gedcom');
            $xref   = RowCast::string($row, 'xref');

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
            $linkSamples[$key] ??= [];

            // Resolve the sample through the privacy layer; a null result (a
            // record the user cannot see) is skipped and the next contributor
            // fills the slot — see SankeySampleResolver::resolve().
            if (count($linkSamples[$key]) < self::SAMPLES_PER_FLOW) {
                $sample = SankeySampleResolver::resolve($this->tree, $xref, $gedcom);

                if ($sample instanceof SankeySample) {
                    $linkSamples[$key][] = $sample;
                }
            }
        }

        // The country labels are already display-ready, so the assembler keys
        // the nodes on the labels themselves (no separate display map).
        return BipartiteSankeyAssembler::assemble($linkWeight, $linkSamples, $topLinks);
    }

    /**
     * Resolve a webtrees place string to its ISO-canonical country label. The
     * place's country segment is mapped through {@see IsoCountryMap} so locale
     * and spelling variants ("Germany"/"Deutschland", "England"/"Great Britain")
     * collapse onto one country — keeping the same-country guard and the node
     * labels consistent with the Geographic-origin card. Returns null when the
     * segment matches no known country.
     */
    private function extractCountry(string $place): ?string
    {
        $iso2 = $this->isoMap->resolveFromPlace($place);

        if ($iso2 === null) {
            return null;
        }

        return $this->isoMap->label($iso2);
    }
}
