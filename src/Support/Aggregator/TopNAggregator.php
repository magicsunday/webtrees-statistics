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

use function array_slice;
use function arsort;
use function is_string;
use function mb_strtolower;

/**
 * Generic Top-N counter for "iterate a row set, extract zero or more label
 * strings per row, count case-folded labels, return the top entries by
 * descending frequency". Used by the OCCU / RELI / CAUS Top-N repositories
 * which share this exact shape; consolidating the loop and the
 * case-folding-vs-display-form bookkeeping in one place means a fix here
 * propagates to every aggregator at once.
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
     * @return array<string, int> Display label => count, sorted descending by count
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

        arsort($counts);

        $out = [];

        foreach ($counts as $key => $count) {
            $out[$display[$key]] = $count;
        }

        if ($limit <= 0) {
            return $out;
        }

        return array_slice($out, 0, $limit, true);
    }
}
