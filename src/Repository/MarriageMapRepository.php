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
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

/**
 * Shared repository that builds the tree-wide marriage-graph adjacency map —
 * `individual-id → [spouse-id, …]` — from the `families` table's FAMS pairs.
 * Each family that records both a husband and a wife contributes an undirected
 * edge, stored from both sides so the relation is symmetric; a partner that
 * recurs across several families collapses to a single entry. Individuals who
 * are only one side of an incomplete couple (a lone HUSB or WIFE) are absent
 * from the map.
 *
 * This is the FAMS analogue of {@see ParentMapRepository} (which builds the
 * FAMC parent map): centralising the family-spouse scan here keeps every
 * consumer aligned on the same partner-resolution semantics and avoids a
 * separate full-table scan per consumer per dashboard render.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class MarriageMapRepository
{
    /**
     * Per-instance memo of the marriage map. The repository is shared across
     * multiple statistics-chart consumers within a single request, so the
     * underlying SQL + map construction run once instead of once per caller.
     *
     * @var array<array-key, list<string>>|null
     */
    private ?array $cache = null;

    /**
     * @param Tree $tree The tree the marriage map is computed for
     */
    public function __construct(
        private readonly Tree $tree,
    ) {
    }

    /**
     * Build an individual-id → spouse-id list map for the configured tree. Only
     * families recording both spouses contribute; each pair is added in both
     * directions and de-duplicated, so the result is a symmetric adjacency map
     * with no repeated spouse XREFs. Result is cached for the lifetime of the
     * repository instance.
     *
     * The key type is `array-key`, not `string`: a digit-only XREF ("54") is
     * silently coerced to an int the moment it indexes the array, so callers
     * that read a key back out must cast it to string before passing it to a
     * string-typed sink.
     *
     * @return array<array-key, list<string>>
     */
    public function build(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $familyRows = TreeScope::table($this->tree, 'families')
            ->select(['f_husb AS husb', 'f_wife AS wife'])
            ->get();

        $adjacency = [];

        // Parallel `xref => [spouse-xref => true]` set used purely for O(1)
        // de-duplication membership tests, so a partner that recurs across
        // several families is appended to the public list only once without
        // re-scanning the growing list on every family row.
        $seen = [];

        foreach ($familyRows as $row) {
            $husb = RowCast::string($row, 'husb');
            $wife = RowCast::string($row, 'wife');

            if ($husb === '') {
                continue;
            }

            if ($wife === '') {
                continue;
            }

            // A family pointing the same individual at both HUSB and WIFE is
            // malformed data; skip it so the map never grows a self-edge that a
            // downstream chain walk would read as someone married to themselves.
            if ($husb === $wife) {
                continue;
            }

            if (!isset($adjacency[$husb])) {
                $adjacency[$husb] = [];
            }

            if (!isset($adjacency[$wife])) {
                $adjacency[$wife] = [];
            }

            if (!isset($seen[$husb][$wife])) {
                $adjacency[$husb][] = $wife;
                $seen[$husb][$wife] = true;
            }

            if (!isset($seen[$wife][$husb])) {
                $adjacency[$wife][] = $husb;
                $seen[$wife][$husb] = true;
            }
        }

        $this->cache = $adjacency;

        return $adjacency;
    }
}
