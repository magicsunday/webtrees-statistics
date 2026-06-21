<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Database;

use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

/**
 * Bulk-fetch the raw gedcom for a set of individual xrefs in a single chunked
 * query, returning an `[xref => gedcom]` map. Lets a chain/graph resolution hand
 * the pre-fetched gedcom straight to {@see \Fisharebest\Webtrees\Registry::individualFactory()}
 * instead of issuing one `SELECT i_gedcom` per resolved person — the N+1 a
 * per-id resolution loop otherwise scales one query per chain member or graph
 * node (GH-154). {@see ChunkedWhereIn} keeps the id filter within the
 * placeholder budget on a pathologically large id set.
 *
 * Extracted from the repositories that resolve a person sequence to live
 * records (generation-depth chains, marriage-reach webs) so the bulk-fetch idiom
 * lives once.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class GedcomByXref
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Fetch the `[xref => gedcom]` map for the given xrefs in the given tree.
     * Returns an empty map for an empty id list; an xref with no row is simply
     * absent from the result.
     *
     * @param Tree         $tree  The tree whose individuals to fetch
     * @param list<string> $xrefs The individual xrefs to resolve
     *
     * @return array<array-key, string>
     */
    public static function fetch(Tree $tree, array $xrefs): array
    {
        if ($xrefs === []) {
            return [];
        }

        $query = TreeScope::table($tree, 'individuals')
            ->select(['i_id', 'i_gedcom']);

        $rows = ChunkedWhereIn::get($query, 'i_id', $xrefs);

        $out = [];

        foreach ($rows as $row) {
            $id = RowCast::string($row, 'i_id');

            if ($id === '') {
                continue;
            }

            $out[$id] = RowCast::string($row, 'i_gedcom');
        }

        return $out;
    }
}
