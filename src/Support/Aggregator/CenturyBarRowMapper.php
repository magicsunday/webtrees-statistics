<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use Fisharebest\Webtrees\I18N;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;

use function round;

/**
 * Folds a `[century => count]` map into the row shape the chart-lib `BarChart`
 * widget consumes — `[label, value, tooltipLabel, tooltip]`. Every tab that
 * renders a `births`-, `deaths`-, `weddings`- or `divorces`-by-century
 * histogram runs the same loop with the only difference being the noun
 * pluralised in the tooltip footer. Named factory methods keep the
 * `I18N::plural(...)` calls literal for xgettext.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class CenturyBarRowMapper
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Century-histogram rows for a births distribution. `tooltip` pluralises
     * "birth / births".
     *
     * @param array<int, int> $byCentury Signed century number → count map
     *
     * @return list<array{label: string, value: int, tooltipLabel: string, tooltip: string}>
     */
    public static function births(array $byCentury): array
    {
        $rows = [];

        foreach ($byCentury as $century => $count) {
            $rows[] = [
                'label'        => CenturyName::compactLabel($century),
                'value'        => $count,
                'tooltipLabel' => CenturyName::longLabel($century),
                'tooltip'      => I18N::plural('%s birth', '%s births', $count, I18N::number($count)),
            ];
        }

        return $rows;
    }

    /**
     * Century-histogram rows for a deaths distribution. `tooltip` pluralises
     * "death / deaths".
     *
     * @param array<int, int> $byCentury
     *
     * @return list<array{label: string, value: int, tooltipLabel: string, tooltip: string}>
     */
    public static function deaths(array $byCentury): array
    {
        $rows = [];

        foreach ($byCentury as $century => $count) {
            $rows[] = [
                'label'        => CenturyName::compactLabel($century),
                'value'        => $count,
                'tooltipLabel' => CenturyName::longLabel($century),
                'tooltip'      => I18N::plural('%s death', '%s deaths', $count, I18N::number($count)),
            ];
        }

        return $rows;
    }

    /**
     * Century-histogram rows for a marriages distribution. `tooltip` pluralises
     * "marriage / marriages".
     *
     * @param array<int, int> $byCentury
     *
     * @return list<array{label: string, value: int, tooltipLabel: string, tooltip: string}>
     */
    public static function marriages(array $byCentury): array
    {
        $rows = [];

        foreach ($byCentury as $century => $count) {
            $rows[] = [
                'label'        => CenturyName::compactLabel($century),
                'value'        => $count,
                'tooltipLabel' => CenturyName::longLabel($century),
                'tooltip'      => I18N::plural('%s marriage', '%s marriages', $count, I18N::number($count)),
            ];
        }

        return $rows;
    }

    /**
     * Century-histogram rows for a divorces distribution. `tooltip` pluralises
     * "divorce / divorces".
     *
     * @param array<int, int> $byCentury
     *
     * @return list<array{label: string, value: int, tooltipLabel: string, tooltip: string}>
     */
    public static function divorces(array $byCentury): array
    {
        $rows = [];

        foreach ($byCentury as $century => $count) {
            $rows[] = [
                'label'        => CenturyName::compactLabel($century),
                'value'        => $count,
                'tooltipLabel' => CenturyName::longLabel($century),
                'tooltip'      => I18N::plural('%s divorce', '%s divorces', $count, I18N::number($count)),
            ];
        }

        return $rows;
    }

    /**
     * Century-histogram rows for the per-century source-citation coverage
     * breakdown. Each input entry carries the raw counts (`total`, `sourced`)
     * plus the pre-computed `percentage`; the bar's `value` is the rounded
     * percentage so the y-axis reads as a 0..100 scale without decimals, and
     * the tooltip carries the full "X% — N of M individuals sourced" prose for
     * context.
     *
     * The source-coverage repository returns the signed 1-based integer century;
     * {@see CenturyName::compactLabel()} / {@see CenturyName::longLabel()} build
     * the "19th cent." / "20th Century" labels (and the BCE era marker, composed
     * last) directly from it, matching every sibling per-century chart.
     *
     * @param list<array{century: int, total: int, sourced: int, percentage: float}> $perCentury Repository output
     *
     * @return list<array{label: string, value: int, tooltipLabel: string, tooltip: string}>
     */
    public static function sourceCoverage(array $perCentury): array
    {
        $rows = [];

        foreach ($perCentury as $entry) {
            $percentageRounded = (int) round($entry['percentage']);
            $rows[]            = [
                'label'        => CenturyName::compactLabel($entry['century']),
                'value'        => $percentageRounded,
                'tooltipLabel' => CenturyName::longLabel($entry['century']),
                'tooltip'      => I18N::translate(
                    '%1$s%% — %2$s of %3$s individuals sourced',
                    I18N::number($percentageRounded),
                    I18N::number($entry['sourced']),
                    I18N::number($entry['total']),
                ),
            ];
        }

        return $rows;
    }
}
