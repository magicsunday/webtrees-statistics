<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Database;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use stdClass;

use function array_chunk;
use function array_values;
use function max;

/**
 * Runs a query whose id filter would otherwise bind one prepared-statement
 * placeholder per id — a count that scales with the tree and overruns the
 * database's variable ceiling on large trees (MySQL/MariaDB abort at 65 535
 * placeholders, which crashed the whole statistics page in issue #82).
 *
 * The id list is sliced into bounded chunks; the base query is cloned and
 * constrained with `whereIn($column, $chunk)` once per slice, so no single
 * round-trip exceeds the chunk-sized placeholder budget. The rows from every
 * slice are concatenated into one collection, identical to what a single
 * unbounded `whereIn` would have returned.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ChunkedWhereIn
{
    /**
     * Ids — and therefore placeholders — per round-trip. Sits well below the
     * lowest ceiling we target (MySQL/MariaDB's 65 535) with head-room for the
     * base query's own bindings, while large enough that realistic trees need
     * only a handful of round-trips.
     */
    public const int DEFAULT_CHUNK_SIZE = 10000;

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Fetch every row matching `$column IN ($ids)` for the prepared base query,
     * issuing one round-trip per `$chunkSize`-sized slice of `$ids` so the
     * bound placeholder count per statement never exceeds `$chunkSize`.
     *
     * The base query is cloned per slice, so its shared select/where state is
     * reused without successive slices' `whereIn` clauses accumulating onto a
     * single builder.
     *
     * @param Builder            $query     Fully-built base query minus the id filter
     * @param string             $column    Column the id list constrains
     * @param array<int, string> $ids       Id list of any size (the caller deduplicates if required)
     * @param int                $chunkSize Maximum ids — and placeholders — per round-trip
     *
     * @return Collection<int, stdClass> Concatenated rows across every slice
     */
    public static function get(
        Builder $query,
        string $column,
        array $ids,
        int $chunkSize = self::DEFAULT_CHUNK_SIZE,
    ): Collection {
        // Accumulate plain rows and wrap once at the end. Folding each slice in
        // with Collection::concat would re-copy the whole accumulator per chunk
        // (O(n²) across a large id list); a flat array append stays O(n). An
        // empty id list yields an empty array_chunk, so no query is issued.
        $rows = [];

        foreach (array_chunk(array_values($ids), max(1, $chunkSize)) as $chunk) {
            foreach ((clone $query)->whereIn($column, $chunk)->get() as $row) {
                $rows[] = $row;
            }
        }

        return new Collection($rows);
    }
}
