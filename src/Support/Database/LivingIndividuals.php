<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Database;

use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\Builder;

use function getdate;
use function gregoriantojd;
use function is_numeric;

/**
 * The one definition of a living individual that every living-based card
 * shares. A person is living unless a death event is recorded (any of
 * {@see Gedcom::DEATH_EVENTS}) or the birth is older than the maximum age that
 * the tree allows for a living person, the `MAX_ALIVE_AGE` preference. A birth
 * that is a range counts as old only when the whole range is old, the way core
 * judges a date. The other events of the person and the evidence that core
 * draws from relatives are not part of it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class LivingIndividuals
{
    /**
     * Ceiling used when the tree has no usable `MAX_ALIVE_AGE` preference, the
     * default that webtrees core installs for a new tree.
     */
    private const int DEFAULT_MAX_ALIVE_AGE = 120;

    /**
     * Days per year in the horizon, the same simplification that core uses, so
     * the cards agree with the individual pages.
     */
    private const int DAYS_PER_YEAR = 365;

    /**
     * Prevent instantiation, static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Maximum age in whole years that a living person may have, taken from the
     * `MAX_ALIVE_AGE` preference of the tree.
     *
     * @param Tree $tree The tree whose preference is read
     */
    public static function maxAliveAge(Tree $tree): int
    {
        $preference = $tree->getPreference('MAX_ALIVE_AGE');

        if (!is_numeric($preference)) {
            return self::DEFAULT_MAX_ALIVE_AGE;
        }

        $value = (int) $preference;

        return $value > 0 ? $value : self::DEFAULT_MAX_ALIVE_AGE;
    }

    /**
     * Today as a Julian day, the end point of every age of a living person.
     */
    public static function todayJulianDay(): int
    {
        $today = getdate();

        return gregoriantojd($today['mon'], $today['mday'], $today['year']);
    }

    /**
     * Narrow a query on the `individuals` table to the living individuals.
     *
     * @param Builder $query A query that selects from `individuals`
     * @param Tree    $tree  The tree whose maximum age applies
     */
    public static function restrict(Builder $query, Tree $tree): Builder
    {
        $horizon = self::todayJulianDay() - (self::DAYS_PER_YEAR * self::maxAliveAge($tree));

        foreach (Gedcom::DEATH_EVENTS as $tag) {
            $query->where('individuals.i_gedcom', 'NOT LIKE', "%\n1 " . $tag . '%');
        }

        // A ranged birth is stored as one row per bound. The person is old when
        // a birth row lies before the horizon and none lies after it.
        return $query->where(static function (Builder $living) use ($horizon): void {
            $living
                ->whereNotExists(static function (Builder $old) use ($horizon): void {
                    self::birthRows($old)
                        ->where('dates.d_julianday2', '>', 0)
                        ->where('dates.d_julianday2', '<=', $horizon);
                })
                ->orWhereExists(static function (Builder $young) use ($horizon): void {
                    self::birthRows($young)->where('dates.d_julianday2', '>', $horizon);
                });
        });
    }

    /**
     * Select the birth rows of the individual that the outer query reads.
     *
     * @param Builder $query The subquery to fill
     */
    private static function birthRows(Builder $query): Builder
    {
        return $query
            ->from('dates')
            ->whereColumn('dates.d_file', '=', 'individuals.i_file')
            ->whereColumn('dates.d_gid', '=', 'individuals.i_id')
            ->where('dates.d_fact', '=', 'BIRT');
    }
}
