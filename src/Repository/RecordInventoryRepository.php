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
use Illuminate\Database\Query\Expression;
use MagicSunday\Webtrees\Statistic\Model\Metric\RecordInventory;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

/**
 * Counts the tree's records by GEDCOM type for the Tree-health tab's data-set
 * inventory: how many core person/family records the tree holds against how
 * many enrichment records (sources, media objects, shared notes, repositories,
 * shared locations), plus the breakdown of media files by their recorded
 * source-media type (photo, tombstone, map, …).
 *
 * All counts come straight from the normalised webtrees tables — `individuals`,
 * `families`, `sources`, `media` and the `other` catch-all keyed by `o_type` —
 * so the whole inventory is a handful of indexed aggregate queries regardless of
 * tree size. Media types are read from `media_file.source_media_type`, where the
 * GEDCOM type lives, and returned as the raw type tokens; the view maps each
 * token to a translated label at the call site.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class RecordInventoryRepository
{
    /**
     * @param Tree $tree The tree the inventory is computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Count the tree's records by type and bundle them into the core-versus-
     * enrichment inventory. The enrichment record types (shared notes,
     * repositories, shared locations) all live in the `other` table keyed by
     * `o_type`, so a single grouped pass yields every count. Top-level NOTE and
     * GEDCOM 7 SNOTE records are both shared notes in webtrees, so their two
     * tokens fold into one shared-note count.
     */
    public function getRecordInventory(): RecordInventory
    {
        $other = $this->otherCountsByType();

        return new RecordInventory(
            TreeScope::table($this->tree, 'individuals')->count(),
            TreeScope::table($this->tree, 'families')->count(),
            TreeScope::table($this->tree, 'sources')->count(),
            TreeScope::table($this->tree, 'media')->count(),
            ($other['NOTE'] ?? 0) + ($other['SNOTE'] ?? 0),
            $other['REPO'] ?? 0,
            $other['_LOC'] ?? 0,
        );
    }

    /**
     * Media files grouped by their recorded source-media type, most-frequent
     * first. The key is the raw GEDCOM type token (e.g. `photo`, `tombstone`);
     * an empty token marks media without a recorded type. Ties on the count
     * resolve alphabetically by token so the ordering is deterministic.
     *
     * @return array<string, int> Source-media-type token → media-file count
     */
    public function getMediaByType(): array
    {
        $rows = TreeScope::table($this->tree, 'media_file')
            ->select(['source_media_type AS media_type', new Expression('COUNT(*) AS total')])
            ->groupBy(['source_media_type'])
            ->orderBy(new Expression('COUNT(*)'), 'desc')
            ->orderBy('source_media_type')
            ->get();

        $byType = [];

        foreach ($rows as $row) {
            $byType[RowCast::string($row, 'media_type')] = RowCast::int($row, 'total');
        }

        return $byType;
    }

    /**
     * Record counts in the `other` table grouped by `o_type`, used to pull out
     * the enrichment record types (NOTE, SNOTE, REPO, _LOC) in one query.
     *
     * @return array<string, int> `o_type` token → record count
     */
    private function otherCountsByType(): array
    {
        $rows = TreeScope::table($this->tree, 'other')
            ->select(['o_type', new Expression('COUNT(*) AS total')])
            ->groupBy(['o_type'])
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $counts[RowCast::string($row, 'o_type')] = RowCast::int($row, 'total');
        }

        return $counts;
    }
}
