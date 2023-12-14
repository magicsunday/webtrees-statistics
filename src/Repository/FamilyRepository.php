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

/**
 * A repository providing methods for family-related statistics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class FamilyRepository
{
    /**
     * @var Tree
     */
    private Tree $tree;

    /**
     * Constructor.
     *
     * @param Tree $tree
     */
    public function __construct(Tree $tree)
    {
        $this->tree = $tree;
    }

    /**
     * Number of married husbands.
     *
     * @return int
     */
    public function getTotalMarriedMales(): int
    {
        return DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->where('f_gedcom', 'LIKE', "%\n1 MARR%")
            ->distinct()
            ->count('f_husb');
    }

    /**
     * Number of married wives.
     *
     * @return int
     */
    public function getTotalMarriedFemales(): int
    {
        return DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->where('f_gedcom', 'LIKE', "%\n1 MARR%")
            ->distinct()
            ->count('f_wife');
    }

    /**
     * Number of married husbands.
     *
     * @return int
     */
    public function getTotalNotMarriedMales(): int
    {
        return DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->where('f_gedcom', 'NOT LIKE', "%\n1 MARR%")
            ->distinct()
            ->count('f_husb');
    }

    /**
     * Number of married husbands.
     *
     * @return int
     */
    public function getTotalNotMarriedFemales(): int
    {
        return DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->where('f_gedcom', 'NOT LIKE', "%\n1 MARR%")
            ->distinct()
            ->count('f_wife');
    }
}
