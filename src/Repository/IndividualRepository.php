<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;

/**
 * A repository providing methods for individual related statistics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class IndividualRepository
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
     * @return int
     */
    public function getTotalIndividuals(): int
    {
        return $this->getTotalIndividualsQuery();
    }

    /**
     * @return int
     */
    public function getTotalSexMale(): int
    {
        return $this->getTotalSexQuery('M');
    }

    /**
     * @return int
     */
    public function getTotalSexFemale(): int
    {
        return $this->getTotalSexQuery('F');
    }

    /**
     * @return int
     */
    public function getTotalSexUnknown(): int
    {
        return $this->getTotalSexQuery('U');
    }

    /**
     * Returns how many individuals exist in the tree.
     *
     * @return int
     */
    protected function getTotalIndividualsQuery(): int
    {
        return DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->count();
    }

    /**
     * Returns the total count of a specific sex.
     *
     * @param string $sex The sex to query
     *
     * @return int
     */
    protected function getTotalSexQuery(string $sex): int
    {
        return DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->where('i_sex', '=', $sex)
            ->count();
    }

    /**
     * Count the number of living individuals.
     *
     * @return int
     */
    public function getTotalLiving(): int
    {
        return $this->getTotalLivingQuery();
    }

    /**
     * Count the number of dead individuals.
     *
     * @return int
     */
    public function getTotalDeceased(): int
    {
        return $this->getTotalDeceasedQuery();
    }

    /**
     * Count the number of living individuals.
     *
     * The totalLiving/totalDeceased queries assume that every dead person will
     * have a DEAT record. It will not include individuals who were born more
     * than MAX_ALIVE_AGE years ago, and who have no DEAT record.
     * A good reason to run the “Add missing DEAT records” batch-update!
     *
     * @return int
     */
    protected function getTotalLivingQuery(): int
    {
        $query = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id());

        foreach (Gedcom::DEATH_EVENTS as $death_event) {
            $query->where('i_gedcom', 'NOT LIKE', "%\n1 " . $death_event . '%');
        }

        return $query->count();
    }

    /**
     * Count the number of dead individuals.
     *
     * @return int
     */
    protected function getTotalDeceasedQuery(): int
    {
        return DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->where(static function (Builder $query): void {
                foreach (Gedcom::DEATH_EVENTS as $death_event) {
                    $query->orWhere('i_gedcom', 'LIKE', "%\n1 " . $death_event . '%');
                }
            })
            ->count();
    }

    /**
     * Returns how many individuals exist in the tree.
     *
     * @return Collection
     */
    public function getAllIndividuals()
    {
        //        $individuals = DB::table('individuals')
        //            ->where('i_file', '=', $this->tree->id())
        //            ->get()
        //            ->map(Registry::individualFactory()->mapper($this->tree))
        //            ->all();
        //
        //        $count = 0;
        //
        //        /** @var Individual $individual */
        //        foreach ($individuals as $individual) {
        //            /** @var Family $family */
        //            foreach ($individual->childFamilies() as $family) {
        //                if ($family->getMarriage() === null) {
        //                    ++$count;
        //                }
        //            }
        //        }
        //
        // var_dump($count);
    }
}
