<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Model\Ranking\RankingEntry;
use MagicSunday\Webtrees\Statistic\Model\Record\FamilyCountRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualCountRecord;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RecordName;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

/**
 * Entity rankings and single-record holders for the Family tab — the top-N
 * largest families, the top-N families by grandchild count, and the
 * individual / family record holders. These share the
 * {@see RankingEntry}/record-recount idiom and a raw-rank privacy stance (rank
 * the candidates, resolve via the record factory, render the full name), kept
 * apart from the per-family distribution and chart-payload aggregations in
 * {@see ChildrenRepository}.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class FamilyRankingRepository
{
    /**
     * @param Tree           $tree The tree the statistics are computed for
     * @param StatisticsData $data Core accessor (familiesWithTheMostChildren)
     */
    public function __construct(
        private Tree $tree,
        private StatisticsData $data,
    ) {
    }

    /**
     * Top-N largest families ranked by f_numchil. Each row carries the family
     * XREF so two families that share a display label stay distinct in the
     * podium.
     *
     * @param int $limit Maximum number of rows.
     *
     * @return list<RankingEntry>
     */
    public function topLargestFamilies(int $limit): array
    {
        $entries = [];

        foreach ($this->data->familiesWithTheMostChildren($limit) as $entry) {
            $family   = $entry->family ?? null;
            $children = $entry->children ?? 0;

            if (!$family instanceof Family) {
                continue;
            }

            $plainName = RecordName::plain($family->fullName());
            $entries[] = new RankingEntry($family->xref(), $plainName, $children);
        }

        return $entries;
    }

    /**
     * Top-N families ranked by their number of grandchildren — the children of
     * the family's own children. Replicates webtrees core's `topTenGrandFamily`
     * statistic so the figures match the built-in module: candidates are ranked
     * by the families → CHIL → FAMS → CHIL link count, and the displayed count
     * is then recomputed over the record layer (every child's spouse-families'
     * children), exactly as core does. Each row carries the family XREF so two
     * families sharing a display label stay distinct in the podium.
     *
     * @param int $limit Maximum number of rows
     *
     * @return list<RankingEntry>
     */
    public function topGrandchildFamilies(int $limit): array
    {
        $rows = TreeScope::table($this->tree, 'families')
            ->join('link AS children', static function (JoinClause $join): void {
                $join
                    ->on('children.l_from', '=', 'f_id')
                    ->on('children.l_file', '=', 'f_file')
                    ->where('children.l_type', '=', 'CHIL');
            })
            ->join('link AS mchildren', static function (JoinClause $join): void {
                $join
                    ->on('mchildren.l_file', '=', 'children.l_file')
                    ->on('mchildren.l_from', '=', 'children.l_to')
                    ->where('mchildren.l_type', '=', 'FAMS');
            })
            ->join('link AS gchildren', static function (JoinClause $join): void {
                $join
                    ->on('gchildren.l_file', '=', 'mchildren.l_file')
                    ->on('gchildren.l_from', '=', 'mchildren.l_to')
                    ->where('gchildren.l_type', '=', 'CHIL');
            })
            ->groupBy(['f_id', 'f_file'])
            ->orderBy(new Expression('COUNT(*)'), 'DESC')
            // Deterministic tie-break so the limit picks a stable subset when
            // several families share the same grandchild-link count.
            ->orderBy('f_id')
            ->select(['families.*'])
            ->limit($limit)
            ->get();

        $entries = [];

        foreach ($rows as $row) {
            $family = Registry::familyFactory()->make(
                RowCast::string($row, 'f_id'),
                $this->tree,
                RowCast::string($row, 'f_gedcom'),
            );

            if (!$family instanceof Family) {
                continue;
            }

            // Re-count over the record layer exactly as core does: every child's
            // spouse-families' children. This is the figure shown to the user;
            // the SQL COUNT(*) above only orders the candidates for the limit.
            $grandchildren = 0;

            foreach ($family->children() as $child) {
                foreach ($child->spouseFamilies() as $spouseFamily) {
                    $grandchildren += $spouseFamily->children()->count();
                }
            }

            $entries[] = new RankingEntry(
                $family->xref(),
                RecordName::plain($family->fullName()),
                $grandchildren,
            );
        }

        return $entries;
    }

    /**
     * Single individual with the highest aggregated child count across every
     * family they participated in. Different from {@see largestFamilyRecord()}
     * because a man married three times with 5+4+3 children wins here (12
     * children total) but not there (largest single family was 5). Counts each
     * FAM's `f_numchil` exactly once per spouse, so the same child does not
     * contribute to both parents' totals across remarriages.
     */
    public function mostChildrenPerPersonRecord(): ?IndividualCountRecord
    {
        // Use the raw prefixed table name (wt_families) inside
        // `Expression` and `orderByRaw` — Eloquent only auto-prefixes
        // table aliases in `from` / `join`, not strings inside raw
        // SQL fragments, so the SUM here must reference the actual
        // physical table.
        $familiesTable = DB::connection()->getTablePrefix() . 'families';

        $row = TreeScope::table($this->tree, 'link')
            ->where('l_type', '=', 'FAMS')
            ->join('families', static function (JoinClause $join): void {
                $join
                    ->on('families.f_file', '=', 'link.l_file')
                    ->on('families.f_id', '=', 'link.l_to');
            })
            ->where('families.f_numchil', '>', 0)
            ->groupBy('link.l_from')
            ->orderByRaw('SUM(' . $familiesTable . '.f_numchil) DESC')
            ->select([
                'link.l_from AS xref',
                new Expression('SUM(' . $familiesTable . '.f_numchil) AS total_children'),
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        $xref  = RowCast::string($row, 'xref');
        $total = RowCast::int($row, 'total_children');

        if (($xref === '') || ($total <= 0)) {
            return null;
        }

        $individual = Registry::individualFactory()->make($xref, $this->tree);

        if (!$individual instanceof Individual) {
            return null;
        }

        return new IndividualCountRecord(individual: $individual, count: $total);
    }

    /**
     * Single largest-family record holder: the family with the highest
     * `f_numchil` count. Returns null when the tree has no family with at least
     * one child.
     */
    public function largestFamilyRecord(): ?FamilyCountRecord
    {
        foreach ($this->data->familiesWithTheMostChildren(1) as $entry) {
            $family   = $entry->family ?? null;
            $children = $entry->children ?? 0;

            if (!$family instanceof Family) {
                continue;
            }

            if ($children <= 0) {
                continue;
            }

            return new FamilyCountRecord(family: $family, count: $children);
        }

        return null;
    }
}
