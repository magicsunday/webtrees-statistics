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
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

/**
 * Direct distinct-count queries for surnames and given names. These bypass
 * StatisticsData::commonSurnames/commonGivenNames because both take an
 * `int $limit` argument that is fed straight into SQL `LIMIT` (or
 * `Collection::slice`), so the natural call "count everything" via limit 0
 * silently returns 0.
 *
 * All queries restrict to the primary name row (`n_num = 0`) so that AKA
 * and other alternate-name entries do not inflate distinct totals beyond
 * what the paired top-N lists report.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class NameRepository
{
    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * @return int Count of distinct primary surnames, excluding the unknown sentinel and married names
     */
    public function countDistinctSurnames(): int
    {
        return DB::table('name')
            ->where('n_file', '=', $this->tree->id())
            ->where('n_num', '=', 0)
            ->where('n_type', '<>', '_MARNM')
            ->whereNotIn('n_surn', ['', Individual::NOMEN_NESCIO])
            ->distinct()
            ->count('n_surn');
    }

    /**
     * @param string $sex GEDCOM sex token, e.g. 'M' or 'F'
     *
     * @return int Count of distinct primary given names for the given sex
     */
    public function countDistinctGivenNames(string $sex): int
    {
        return DB::table('name')
            ->join('individuals', static function (JoinClause $join): void {
                $join
                    ->on('i_file', '=', 'n_file')
                    ->on('i_id', '=', 'n_id');
            })
            ->where('n_file', '=', $this->tree->id())
            ->where('n_num', '=', 0)
            ->where('n_type', '<>', '_MARNM')
            ->where('n_givn', '<>', Individual::PRAENOMEN_NESCIO)
            ->where(new Expression('LENGTH(n_givn)'), '>', 1)
            ->where('i_sex', '=', $sex)
            ->distinct()
            ->count('n_givn');
    }
}
