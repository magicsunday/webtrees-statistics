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
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;

use function ksort;

/**
 * Counts the distinct records of a GEDCOM fact per century, keyed by the
 * signed 1-based century number ({@see CenturyName::fromYear()}). The view layer
 * renders the label via {@see CenturyName::compactLabel()} / {@see
 * CenturyName::longLabel()} so the BCE era marker composes after the century
 * noun. Replaces webtrees core's `StatisticsData::countEventsByCentury()`
 * for the births / deaths / weddings / divorces histograms: core counts raw
 * `dates` rows, so a range date (`BET..AND` / `FROM..TO`) is double-counted and
 * may split across two centuries. Sourcing the count from
 * {@see DedupedEventDates} collapses each record to its lower-bound row first,
 * and {@see GregorianDate} converts a non-Gregorian/Julian date (French
 * Republican, Hebrew, …) to its Gregorian year so it lands in the century it
 * actually occurred in rather than being excluded.
 *
 * The per-century fold reuses {@see CenturyName::fromYear()} — the module's
 * single source of truth for the year→century convention — so these histograms
 * bucket identically to the sibling per-century cards (source coverage, child
 * mortality). The convention matches core's own banding for every positive
 * year; negative (BCE) years fold toward negative infinity, landing in a
 * negative century the view layer labels as "%s BCE".
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class EventCenturyTally
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Distinct-record count per century for the given fact, century-ascending,
     * keyed by the signed 1-based century number (negative for BCE).
     *
     * @param Tree   $tree The tree whose events to count
     * @param string $fact The GEDCOM fact tag (e.g. `BIRT`, `DEAT`, `MARR`, `DIV`)
     *
     * @return array<int, int>
     */
    public static function countByCentury(Tree $tree, string $fact): array
    {
        $rows = DedupedEventDates::query($tree, $fact)
            ->select(['d_type', 'd_year', 'd_julianday1'])
            ->get();

        $byCentury = [];

        foreach ($rows as $row) {
            $year = GregorianDate::year(
                RowCast::string($row, 'd_type'),
                RowCast::int($row, 'd_year'),
                RowCast::int($row, 'd_julianday1'),
            );

            $century = CenturyName::fromYear($year);

            $byCentury[$century] = ($byCentury[$century] ?? 0) + 1;
        }

        ksort($byCentury);

        return $byCentury;
    }
}
