<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\MonthName;

use function ksort;

/**
 * Counts the distinct records of a GEDCOM fact per calendar month, keyed by the
 * GEDCOM three-letter month code (`JAN`..`DEC`) in calendar order. Replaces
 * webtrees core's `StatisticsData::countEventsByMonth()` for the births /
 * deaths / divorces by-month cards and the death-seasonality (winter-peak)
 * score: core counts raw `dates` rows, so a month-spanning range date
 * (`BET DEC … AND JAN …`) is double-counted and split across two months.
 * Sourcing the count from {@see DedupedEventDates} collapses each record to its
 * lower-bound row first, and {@see GregorianDate} converts a non-Gregorian/Julian
 * date to its Gregorian month so it is counted in the month it actually occurred
 * in rather than being excluded or read in the foreign calendar's month index.
 *
 * Month-less records (year-only / `ABT` dates carry `d_mon = 0`) are dropped, so
 * the result keys are exactly the months that actually occur — matching the
 * `JAN`..`DEC` whitelist every month consumer already applies. The output folds
 * straight onto {@see MonthName::byAbbreviation()}.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class EventMonthTally
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Distinct-record count per month for the given fact, calendar-ordered,
     * keyed by the GEDCOM three-letter month code. Month-less records are
     * excluded.
     *
     * @param Tree   $tree The tree whose events to count
     * @param string $fact The GEDCOM fact tag (e.g. `BIRT`, `DEAT`, `DIV`)
     *
     * @return array<string, int>
     */
    public static function countByMonth(Tree $tree, string $fact): array
    {
        $rows = DedupedEventDates::query($tree, $fact)
            ->where('d_mon', '<>', 0)
            ->select(['d_type', 'd_year', 'd_mon', 'd_day', 'd_julianday1'])
            ->get();

        $byMonth = [];

        foreach ($rows as $row) {
            [, $month] = GregorianDate::fromEventRow(
                RowCast::string($row, 'd_type'),
                RowCast::int($row, 'd_year'),
                RowCast::int($row, 'd_mon'),
                RowCast::int($row, 'd_day'),
                RowCast::int($row, 'd_julianday1'),
            );

            $byMonth[$month] = ($byMonth[$month] ?? 0) + 1;
        }

        ksort($byMonth);

        $codes = MonthName::codes();
        $out   = [];

        foreach ($byMonth as $month => $count) {
            $code = $codes[$month] ?? null;

            // A malformed out-of-range month column (the canonical codes only
            // cover 1–12) carries no calendar month and is dropped, matching the
            // month-less exclusion the query already applies.
            if ($code === null) {
                continue;
            }

            $out[$code] = $count;
        }

        return $out;
    }
}
