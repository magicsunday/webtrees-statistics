<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Expression;

use const PHP_INT_MAX;

/**
 * Total counts for surnames and given names that stay in lockstep with
 * webtrees core's Top-N aggregation. The given-name path still defers
 * to {@see StatisticsData::commonGivenNames()} because the tokenisation
 * (multi-name splits, initial filter) lives in core. The surname path
 * resolves to a single COUNT(DISTINCT) query — calling
 * {@see StatisticsData::commonSurnames()} with PHP_INT_MAX would fire
 * one extra COUNT per distinct surname, blowing the headline number
 * into an N+1 storm (Sonntag tree: 257 ms for a single count).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class NameRepository
{
    /**
     * @param Tree           $tree The tree whose names to aggregate
     * @param StatisticsData $data Core data accessor used for the underlying name aggregation
     */
    public function __construct(
        private Tree $tree,
        private StatisticsData $data,
    ) {
    }

    /**
     * Number of distinct surnames in the tree, mirroring the filter
     * stack of {@see StatisticsData::commonSurnames()}: exclude
     * `_MARNM` entries plus empty / `NOMEN_NESCIO` values, then
     * count distinct surname tokens whose occurrence ≥ `$threshold`.
     *
     * @param int $threshold Lower bound on the occurrences a surname must have
     */
    public function countDistinctSurnames(int $threshold = 1): int
    {
        $query = DB::table('name')
            ->where('n_file', '=', $this->tree->id())
            ->where('n_type', '<>', '_MARNM')
            ->whereNotIn('n_surn', ['', Individual::NOMEN_NESCIO])
            ->groupBy('n_surn');

        if ($threshold > 1) {
            $query->having(new Expression('COUNT(n_surn)'), '>=', $threshold);
        }

        // After the GROUP BY each row is one distinct surname; the
        // count of those rows is exactly the headline number.
        return $query->get()->count();
    }

    /**
     * Number of distinct given names for a sex, computed from the same
     * aggregation that feeds the Top-N given-name list.
     *
     * @param string $sex       GEDCOM sex token: 'M', 'F', 'X' or 'ALL'
     * @param int    $threshold Lower bound on the occurrences a given name must have
     *
     * @return int
     */
    public function countDistinctGivenNames(string $sex, int $threshold = 1): int
    {
        return $this->data->commonGivenNames($sex, $threshold, PHP_INT_MAX)->count();
    }
}
