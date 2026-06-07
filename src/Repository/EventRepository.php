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
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventCenturyTally;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventMonthTally;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\ZodiacSigns;

use function implode;
use function is_numeric;
use function sprintf;

/**
 * Event groupings the module renders as charts. Zodiac-sign grouping is the one
 * stat webtrees core's StatisticsData does not expose; the per-century births /
 * deaths histograms run a deduplicated count so a range date counts once,
 * rather than core's raw `dates` row count. Month / country groupings delegate
 * to StatisticsData via the Statistic aggregator.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class EventRepository
{
    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Distinct-individual count of the given event per century, keyed by the
     * signed 1-based century number (negative for BCE). Used for the births- and
     * deaths-by-century histograms; deduplicates the two-row range-date
     * encoding so each individual counts once.
     *
     * @param string $fact The GEDCOM fact tag (`BIRT` or `DEAT`)
     *
     * @return array<int, int>
     */
    public function eventsByCentury(string $fact): array
    {
        return EventCenturyTally::countByCentury($this->tree, $fact);
    }

    /**
     * Distinct-record count of the given event per calendar month, keyed by the
     * GEDCOM three-letter month code. Used for the births- and deaths-by-month
     * cards; deduplicates the two-row range-date encoding so a month-spanning
     * range counts once in its lower-bound month.
     *
     * @param string $fact The GEDCOM fact tag (`BIRT` or `DEAT`)
     *
     * @return array<string, int>
     */
    public function eventsByMonth(string $fact): array
    {
        return EventMonthTally::countByMonth($this->tree, $fact);
    }

    /**
     * Group birth events by zodiac sign. Returns all 12 keys even when the
     * dataset has none of a given sign so the chart layout stays stable.
     *
     * Counts run over the deduplicated lower-bound representative row per
     * individual, so a day-precise range birth (`BET 10 JAN AND 25 JAN`) — two
     * stored rows that can fall in different signs — is tallied once in its
     * lower-bound sign rather than in both. The sign needs the month and day of
     * the same row; the lower-bound row supplies both coherently for the common
     * range case, where the two bounds carry distinct julian days.
     *
     * @return array<string, int>
     */
    public function getBirthsByZodiacSign(): array
    {
        $columns = [];

        foreach (ZodiacSigns::ranges() as $name => $range) {
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

        $row = (array) DB::connection()
            ->query()
            ->fromSub(DedupedEventDates::query($this->tree, 'BIRT'), 'birth_dates')
            ->selectRaw(implode(', ', $columns))
            ->first();

        $out = [];

        foreach (ZodiacSigns::keys() as $name) {
            $value      = $row[$name] ?? 0;
            $out[$name] = is_numeric($value) ? (int) $value : 0;
        }

        return $out;
    }
}
