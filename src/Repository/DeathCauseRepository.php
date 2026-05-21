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
use Illuminate\Database\Capsule\Manager as DB;
use MagicSunday\Webtrees\Statistic\Support\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\TopNAggregator;

use function array_slice;
use function count;

/**
 * Top-N aggregation over the `2 CAUS` sub-tag within each
 * individual's `1 DEAT` block — the GEDCOM cause-of-death field.
 * Multiple deaths per INDI cannot occur, so each contributes at most
 * one value. Case-folded counting collapses spelling variants
 * (`Cholera` / `cholera`) into one bucket; the first-seen original
 * casing wins as the display label. The full aggregation is computed
 * once per instance — `topDeathCauses()` and
 * `countDistinctDeathCauses()` both read from the same cached
 * intermediate so a single LifeSpan render does not pay for two
 * independent INDI scans.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class DeathCauseRepository
{
    /**
     * Cached full aggregation (descending count). `null` until the
     * first consumer triggers the scan.
     *
     * @var array<string, int>|null
     */
    private ?array $cache = null;

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private readonly Tree $tree,
    ) {
    }

    /**
     * @param int $limit Maximum number of causes to surface (descending by count)
     *
     * @return array<string, int>
     */
    public function topDeathCauses(int $limit): array
    {
        return array_slice($this->aggregate(), 0, $limit, true);
    }

    /**
     * Number of distinct death causes (case-folded) recorded across the tree.
     */
    public function countDistinctDeathCauses(): int
    {
        return count($this->aggregate());
    }

    /**
     * Run (or replay from cache) the full aggregation. The extract
     * closure wraps {@see GedcomScanner::extractEventSubValue()} to
     * project an optional single value into a list (so it slots into
     * the same `Closure(string): list<string>` shape the aggregator
     * expects).
     *
     * @return array<string, int>
     */
    private function aggregate(): array
    {
        return $this->cache ??= TopNAggregator::topN(
            DB::table('individuals')
                ->where('i_file', '=', $this->tree->id())
                ->select(['i_gedcom AS gedcom'])
                ->get(),
            static function (string $gedcom): array {
                $cause = GedcomScanner::extractEventSubValue($gedcom, 'DEAT', 'CAUS');

                return ($cause === null) ? [] : [$cause];
            },
            0,
        );
    }
}
