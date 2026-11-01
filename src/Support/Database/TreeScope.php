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
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use stdClass;

use function array_keys;
use function implode;

/**
 * Helper that builds an Eloquent query against one of the webtrees core tables
 * already scoped to the active tree. Every repository starts with the same
 * two-line pattern — `DB::table($table)` followed by `->where('X_file', '=',
 * $this->tree->id())` — across thirty-plus queries. Centralising both lines
 * means a future schema-column rename or a multi-tree-scoping change lands in
 * one place; the per-table `*_file` column convention is owned by the helper
 * rather than scattered across every repository.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class TreeScope
{
    /**
     * Webtrees schema convention: every per-tree table carries a
     * single-letter-prefixed `<x>_file` column referencing the `gedcom` table's
     * primary key. The map is the only thing in the module that owns that
     * convention.
     */
    private const array FILE_COLUMNS = [
        'individuals' => 'i_file',
        'families'    => 'f_file',
        'dates'       => 'd_file',
        'link'        => 'l_file',
        'places'      => 'p_file',
        'sources'     => 's_file',
        'media'       => 'm_file',
        'media_file'  => 'm_file',
        'other'       => 'o_file',
    ];

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Open a tree-scoped query against the given webtrees core table. When
     * `$alias` is supplied the helper builds the `<table> AS <alias>` clause
     * and emits the `<alias>.<file>` qualifier on the tree-id filter so the
     * caller can chain `join('… AS other', …)` without prefix collisions.
     *
     * @throws InvalidArgumentException When `$table` is not a known per-tree table
     */
    public static function table(Tree $tree, string $table, ?string $alias = null): Builder
    {
        if (!isset(self::FILE_COLUMNS[$table])) {
            throw new InvalidArgumentException(
                'Unknown per-tree table "' . $table . '"; expected one of: '
                . implode(', ', array_keys(self::FILE_COLUMNS)),
            );
        }

        $fileColumn = self::FILE_COLUMNS[$table];

        if ($alias === null) {
            return DB::table($table)->where($fileColumn, '=', $tree->id());
        }

        return DB::table($table . ' AS ' . $alias)
            ->where($alias . '.' . $fileColumn, '=', $tree->id());
    }

    /**
     * Convenience overload for the most-repeated repository pattern, loading
     * every individual's `i_id` and `i_gedcom` blob for the active tree as a
     * `Collection<int, stdClass{xref: string, gedcom: string}>`. The raw blob is
     * not privacy filtered. The tag aggregations (Religion, DeathCause,
     * Occupation) reduce each row to the facts the viewer may see through
     * {@see \MagicSunday\Webtrees\Statistic\Support\Gedcom\VisibleGedcomRow}
     * before they scan it, while PlaceDispersion reads the raw blob as it is.
     *
     * With `$likePatterns` only the rows whose blob matches at least one of the
     * LIKE patterns are loaded, which spares the transfer and the later work for
     * rows that cannot carry the scanned tag. `null` loads every row.
     *
     * @param Tree              $tree         The tree whose individuals to load
     * @param list<string>|null $likePatterns LIKE patterns on `i_gedcom`, a row matching any one is loaded, null or an empty list loads every row
     *
     * @return Collection<int, stdClass> Rows with the `xref` and `gedcom` columns
     */
    public static function individualGedcoms(Tree $tree, ?array $likePatterns = null): Collection
    {
        $query = self::table($tree, 'individuals')
            ->select(['i_id AS xref', 'i_gedcom AS gedcom']);

        if ($likePatterns !== null) {
            $query->where(static function (Builder $group) use ($likePatterns): void {
                foreach ($likePatterns as $pattern) {
                    $group->orWhere('i_gedcom', 'like', $pattern);
                }
            });
        }

        return $query->get();
    }
}
