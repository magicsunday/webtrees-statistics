<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Aggregator;

use Fisharebest\Webtrees\I18N;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Payload\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Support\Calc\CenturySpan;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;

use function array_column;

/**
 * Map the per-century child-mortality rows into the timeline chart payload.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ChildMortalityLineMapper
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * The axis runs from the first to the last century with an entry. A century
     * between them without an entry carries no value, which the line widget
     * draws as a gap and not as a rate of zero.
     *
     * @param list<array{century: int, total: int, died: int, rate: float}> $entries
     */
    public static function toLineChartPayload(array $entries): LineChartPayload
    {
        $byCentury = array_column($entries, null, 'century');

        $categories    = [];
        $values        = [];
        $tooltips      = [];
        $tooltipLabels = [];

        foreach (CenturySpan::covering(array_column($entries, 'century')) as $century) {
            $entry           = $byCentury[$century] ?? null;
            $categories[]    = CenturyName::compactLabel($century);
            $values[]        = $entry['rate'] ?? null;
            $tooltipLabels[] = CenturyName::longLabel($century);
            $tooltips[]      = $entry === null
                ? ''
                : I18N::translate(
                    '%1$s%% — %2$s of %3$s children died before age 5',
                    I18N::number($entry['rate'], 1),
                    I18N::number($entry['died']),
                    I18N::number($entry['total']),
                );
        }

        return new LineChartPayload(
            categories: $categories,
            series: [
                new LineChartSeries(
                    name: I18N::translate('Mortality rate'),
                    values: $values,
                    tooltips: $tooltips,
                    tooltipLabels: $tooltipLabels,
                ),
            ],
        );
    }
}
