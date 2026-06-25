<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use Closure;
use Illuminate\Support\Collection;

use function array_keys;
use function array_slice;
use function is_string;
use function mb_strtolower;
use function strcmp;
use function uksort;

/**
 * Shared Top-N counting and ranking helper. Two responsibilities:
 *
 * - {@see topN()} is the generic counter for "iterate a row set, extract zero
 *   or more label strings per row, count case-folded labels, return the top
 *   entries by descending frequency" — used by the OCCU / RELI / CAUS Top-N
 *   repositories which share that exact shape.
 * - {@see rankKeys()} / {@see rank()} are the single source of truth for the
 *   Top-N tie-break (count descending, then fold key ascending in PHP byte
 *   order) shared by the given-name, surname-matrix and given-name-trends
 *   aggregations, whose own counting loops differ but must order and cap their
 *   pre-counted maps identically and engine-independently.
 *
 * Consolidating the case-folding-vs-display-form bookkeeping and the tie-break
 * in one place means a fix here propagates to every aggregator at once.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class TopNAggregator
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Walk `$rows`, run `$extract` on each row's GEDCOM column, count the
     * extracted label strings case-folded so spelling variants (`Catholic` /
     * `catholic ` / `CATHOLIC`) merge into a single bucket. The display label
     * is the first-seen original casing; the count returns the merged total.
     *
     * @param Collection<int, object>       $rows    Result set from a DB::table('individuals')->...->get() call
     * @param Closure(string): list<string> $extract Returns the list of label values for one row's gedcom blob
     * @param int                           $limit   Maximum number of entries to return; 0 or negative returns the full list
     *
     * @return array<string, int> Display label => count, sorted by descending count then case-folded label
     */
    public static function topN(Collection $rows, Closure $extract, int $limit): array
    {
        $counts  = [];
        $display = [];

        foreach ($rows as $row) {
            $gedcom = (isset($row->gedcom) && is_string($row->gedcom)) ? $row->gedcom : '';

            foreach ($extract($gedcom) as $value) {
                $key          = mb_strtolower($value);
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $display[$key] ??= $value;
            }
        }

        return self::rank($counts, static fn (string $key): string => $display[$key] ?? $key, $limit);
    }

    /**
     * Order a `fold key => count` map by descending count, breaking equal-count
     * ties on the fold key ascending (byte order, engine-independent), and
     * return the surviving keys in that order. This is the single source of
     * truth for the Top-N tie-break shared by the given-name (via {@see rank()}
     * in topGivenNames), surname-matrix and given-name-trends aggregations — and,
     * indirectly through {@see topN()}, the OCCU / RELI / CAUS counters. Relying
     * on the database row order instead would diverge because the grouped value
     * columns collate differently across SQLite and MySQL; the SQL-side
     * `topSurnames` cap deliberately keeps its own collation tie-break instead
     * (see #149).
     *
     * @param array<string, int> $counts Fold key => merged count
     * @param int                $limit  Maximum number of keys to return; 0 or negative returns the full list
     *
     * @return list<string> The fold keys ordered by descending count then ascending key
     */
    public static function rankKeys(array $counts, int $limit): array
    {
        // Sort by descending count, then by the case-folded key as a stable
        // secondary tie-break so equal-frequency entries at the Top-N boundary
        // keep a deterministic order across runs (arsort alone left ties in an
        // input-dependent order).
        uksort(
            $counts,
            static function (string $a, string $b) use ($counts): int {
                $byCount = ($counts[$b] ?? 0) <=> ($counts[$a] ?? 0);

                return $byCount !== 0 ? $byCount : strcmp($a, $b);
            }
        );

        $keys = array_keys($counts);

        if ($limit <= 0) {
            return $keys;
        }

        return array_slice($keys, 0, $limit);
    }

    /**
     * Rank a `fold key => count` map with {@see rankKeys()} and map each
     * surviving key to its display label via the caller's resolution strategy
     * (first-seen casing, frequency-dominant spelling, …), preserving the
     * ranked order and the counts. The tie-break is decided on the fold key, not
     * on the resolved display label.
     *
     * @param array<string, int>      $counts  Fold key => merged count
     * @param Closure(string): string $display Resolves a fold key to its display label
     * @param int                     $limit   Maximum number of entries to return; 0 or negative returns the full list
     *
     * @return array<string, int> Display label => count, ordered by descending count then ascending fold key
     */
    public static function rank(array $counts, Closure $display, int $limit): array
    {
        $out = [];

        foreach (self::rankKeys($counts, $limit) as $key) {
            $out[$display($key)] = $counts[$key] ?? 0;
        }

        return $out;
    }
}
