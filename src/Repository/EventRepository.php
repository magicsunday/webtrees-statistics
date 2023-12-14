<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * A repository providing methods for event-related statistics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class EventRepository
{
    /**
     * Event facts.
     */
    private const EVENT_BIRTH = 'BIRT';
    private const EVENT_DEATH = 'DEAT';

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
    public function getTotalBirths(): int
    {
        return $this->getEventCount(self::EVENT_BIRTH);
    }

    /**
     * @return int
     */
    public function getTotalDeaths(): int
    {
        return $this->getEventCount(self::EVENT_DEATH);
    }

    /**
     * Returns the total number of events (with dates).
     *
     * @param string ...$events The list of events to count (e.g., BIRT, DEAT, ...)
     *
     * @return int
     */
    protected function getEventCount(string ...$events): int
    {
var_dump($events);
        $query = DB::table('dates')
            ->where('d_file', '=', $this->tree->id());

        $no_types = [
            'HEAD',
            'CHAN',
        ];

        if ($events !== []) {
            $types = [];

            foreach ($events as $type) {
                if (strncmp($type, '!', 1) === 0) {
                    $no_types[] = substr($type, 1);
                } else {
                    $types[] = $type;
                }
            }

            if ($types !== []) {
                $query->whereIn('d_fact', $types);
            }
        }

        return $query
            ->whereNotIn('d_fact', $no_types)
            ->count();
    }
}
