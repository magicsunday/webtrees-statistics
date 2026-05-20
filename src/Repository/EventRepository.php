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

use function array_keys;
use function implode;
use function is_numeric;
use function sprintf;

/**
 * Zodiac-sign grouping for birth events — the one stat in this module that
 * webtrees core's StatisticsData does not expose. Month / century / country
 * groupings delegate to StatisticsData via the Statistic aggregator.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class EventRepository
{
    private const array ZODIAC_SIGNS = [
        'Aries'       => ['from' => [3, 21], 'to' => [4, 21]],
        'Taurus'      => ['from' => [4, 22], 'to' => [5, 21]],
        'Gemini'      => ['from' => [5, 22], 'to' => [6, 21]],
        'Cancer'      => ['from' => [6, 22], 'to' => [7, 22]],
        'Leo'         => ['from' => [7, 23], 'to' => [8, 22]],
        'Virgo'       => ['from' => [8, 23], 'to' => [9, 22]],
        'Libra'       => ['from' => [9, 23], 'to' => [10, 22]],
        'Scorpio'     => ['from' => [10, 23], 'to' => [11, 22]],
        'Sagittarius' => ['from' => [11, 23], 'to' => [12, 20]],
        'Capricornus' => ['from' => [12, 21], 'to' => [1, 19]],
        'Aquarius'    => ['from' => [1, 20], 'to' => [2, 18]],
        'Pisces'      => ['from' => [2, 19], 'to' => [3, 20]],
    ];

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Group birth events by zodiac sign. Returns all 12 keys even when the
     * dataset has none of a given sign so the chart layout stays stable.
     *
     * @return array<string, int>
     */
    public function getBirthsByZodiacSign(): array
    {
        $columns = [];

        foreach (self::ZODIAC_SIGNS as $name => $range) {
            [$fromMonth, $fromDay] = $range['from'];
            [$toMonth,   $toDay]   = $range['to'];
            $columns[]             = sprintf(
                'COUNT(CASE WHEN (d_day != 0 AND d_mon != 0 AND ((d_mon = %d AND d_day >= %d) OR (d_mon = %d AND d_day <= %d))) THEN 1 END) AS %s',
                $fromMonth,
                $fromDay,
                $toMonth,
                $toDay,
                $name,
            );
        }

        $row = (array) DB::table('dates')
            ->selectRaw(implode(', ', $columns))
            ->where('d_file', '=', $this->tree->id())
            ->where('d_fact', '=', 'BIRT')
            ->whereIn('d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
            ->first();

        $out = [];

        foreach (array_keys(self::ZODIAC_SIGNS) as $name) {
            $value      = $row[$name] ?? 0;
            $out[$name] = is_numeric($value) ? (int) $value : 0;
        }

        return $out;
    }
}
