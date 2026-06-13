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
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventCenturyTally;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\EventMonthTally;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\ZodiacSigns;

use function array_fill_keys;

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
     * Only births that carry a real day AND month qualify (`d_day`/`d_mon` are
     * filtered on the NATIVE columns, so a year- or month-only date — including a
     * non-Gregorian one — is excluded before conversion rather than picking up a
     * spurious sign from the import's synthesised first-of-month julian day). A
     * non-Gregorian/Julian birth is then converted to its Gregorian month/day via
     * {@see GregorianDate} so it is classified by {@see ZodiacSigns::signFor()}
     * on the same scale as a Gregorian birth.
     *
     * @return array<string, int>
     */
    public function getBirthsByZodiacSign(): array
    {
        $rows = DedupedEventDates::query($this->tree, 'BIRT')
            ->where('d_mon', '<>', 0)
            ->where('d_day', '<>', 0)
            ->select(['d_type', 'd_year', 'd_mon', 'd_day', 'd_julianday1'])
            ->get();

        $counts = array_fill_keys(ZodiacSigns::keys(), 0);

        foreach ($rows as $row) {
            [, $month, $day] = GregorianDate::fromEventRow(
                RowCast::string($row, 'd_type'),
                RowCast::int($row, 'd_year'),
                RowCast::int($row, 'd_mon'),
                RowCast::int($row, 'd_day'),
                RowCast::int($row, 'd_julianday1'),
            );

            ++$counts[ZodiacSigns::signFor($month, $day)];
        }

        return $counts;
    }
}
