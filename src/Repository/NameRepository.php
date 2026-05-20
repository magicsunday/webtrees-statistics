<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\StatisticsData;

use const PHP_INT_MAX;

/**
 * Total counts for surnames and given names that stay in lockstep with
 * webtrees core's Top-N aggregation. Both methods pass `PHP_INT_MAX` as
 * the limit to {@see StatisticsData::commonSurnames()} /
 * {@see StatisticsData::commonGivenNames()} so the headline number is
 * computed from the exact same aggregation the Top-N tag cloud renders.
 * Any divergence between "total" and "sum of top N" therefore comes from
 * the threshold filter alone, not from a separate tokenisation.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class NameRepository
{
    /**
     * @param StatisticsData $data Core data accessor used for the underlying name aggregation
     */
    public function __construct(
        private StatisticsData $data,
    ) {
    }

    /**
     * Number of distinct surnames in the tree, computed from the same
     * aggregation that feeds the Top-N surname list.
     *
     * @param int $threshold Lower bound on the occurrences a surname must have
     *
     * @return int
     */
    public function countDistinctSurnames(int $threshold = 1): int
    {
        return count($this->data->commonSurnames(PHP_INT_MAX, $threshold, 'count'));
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
