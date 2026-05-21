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
 * Top-N aggregation over the `1 RELI` (religion / confession) facts
 * attached to individuals. Multiple RELI lines per INDI all
 * contribute. Case-folded counting collapses spelling variants
 * (`Katholisch` / `katholisch` / `KATH.`) into one bucket; the
 * first-seen original casing wins as the display label. The full
 * aggregation is computed once per instance — `topReligions()` and
 * `countDistinctReligions()` both read from the same cached
 * intermediate so a single Overview render does not pay for two
 * independent INDI scans.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ReligionRepository
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
     * @param int $limit Maximum number of religions to surface (descending by count)
     *
     * @return array<string, int>
     */
    public function topReligions(int $limit): array
    {
        return array_slice($this->aggregate(), 0, $limit, true);
    }

    /**
     * Number of distinct religions (case-folded) recorded across the tree.
     */
    public function countDistinctReligions(): int
    {
        return count($this->aggregate());
    }

    /**
     * Run (or replay from cache) the full aggregation.
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
            static fn (string $gedcom): array => GedcomScanner::extractAllTagValues($gedcom, 'RELI'),
            0,
        );
    }
}
