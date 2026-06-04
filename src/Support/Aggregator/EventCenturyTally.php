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
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;

use function ksort;

/**
 * Counts the distinct records of a GEDCOM fact per century, keyed by the
 * localised ordinal label ("19th", "20th", …) the chart-lib `BarChart` widget
 * renders. Replaces webtrees core's `StatisticsData::countEventsByCentury()`
 * for the births / deaths / weddings / divorces histograms: core counts raw
 * `dates` rows, so a range date (`BET..AND` / `FROM..TO`) is double-counted and
 * may split across two centuries. Sourcing the count from
 * {@see DedupedEventDates} collapses each record to its lower-bound row first.
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
     * keyed by the localised ordinal label.
     *
     * @param Tree   $tree The tree whose events to count
     * @param string $fact The GEDCOM fact tag (e.g. `BIRT`, `DEAT`, `MARR`, `DIV`)
     *
     * @return array<string, int>
     */
    public static function countByCentury(Tree $tree, string $fact): array
    {
        $rows = DedupedEventDates::query($tree, $fact)
            ->selectRaw('d_year, COUNT(*) AS total')
            ->groupBy('d_year')
            ->get();

        $byCentury = [];

        foreach ($rows as $row) {
            $century = CenturyName::fromYear(RowCast::int($row, 'd_year'));

            $byCentury[$century] = ($byCentury[$century] ?? 0) + RowCast::int($row, 'total');
        }

        ksort($byCentury);

        $out = [];

        foreach ($byCentury as $century => $count) {
            $out[CenturyName::for($century)] = $count;
        }

        return $out;
    }
}
