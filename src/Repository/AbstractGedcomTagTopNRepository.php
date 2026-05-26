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
use MagicSunday\Webtrees\Statistic\Support\Aggregator\TopNAggregator;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;

use function array_slice;
use function count;

/**
 * Shared scaffolding for Top-N aggregations over an individual-level
 * GEDCOM tag. Subclasses declare which values to harvest from each
 * INDI record (one occupation tag → many possible OCCU lines, one
 * religion tag → both `1 RELI` plus `2 RELI` sub-tags, etc.); the
 * base class owns the iteration over the tree, the case-folded
 * frequency rollup, the descending sort, and the per-instance
 * memoisation that lets `top()` and `countDistinct()` share a single
 * INDI scan.
 *
 * The memoised aggregation lives in a single nullable cache field
 * — the contract is "first call computes, subsequent calls reuse".
 * Repository instances are short-lived (constructed once per
 * Statistic facade per request), so the cache is naturally
 * request-scoped without needing explicit invalidation.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
abstract class AbstractGedcomTagTopNRepository
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
     * Harvest all relevant values for this tag from a single INDI
     * GEDCOM record. Empty list when the record carries no matching
     * value; multiple entries when one INDI carries the tag more
     * than once (e.g. a person with two recorded occupations). The
     * case-folding + frequency rollup is the base class's job —
     * subclasses just return the raw list of values per INDI.
     *
     * @param string $gedcom The raw INDI GEDCOM record to scan
     *
     * @return list<string>
     */
    abstract protected function extract(string $gedcom): array;

    /**
     * Top-N values by descending frequency. The case-folded
     * aggregation collapses spelling variants under the first-seen
     * casing; the limit caps how many keys make it into the
     * returned slice.
     *
     * @param int $limit Maximum number of rows to return
     *
     * @return array<string, int>
     */
    final public function top(int $limit): array
    {
        return array_slice($this->aggregate(), 0, $limit, true);
    }

    /**
     * Number of distinct values (case-folded) recorded across the
     * tree — independent of how `top()` is sliced.
     */
    final public function countDistinct(): int
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
            TreeScope::individualGedcoms($this->tree),
            $this->extract(...),
            0,
        );
    }
}
