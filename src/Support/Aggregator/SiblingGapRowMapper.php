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
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;

use function array_keys;
use function array_values;
use function rtrim;
use function str_ends_with;

/**
 * Converts the bucketed `siblingAgeGapDistribution()` map into the unified
 * `{categories, series}` LineChart payload. The repository emits "Ny" labels
 * for 0..max-1 and an "Ny+" overflow label for max-and-above (`SIBLING_GAP_MAX`
 * currently 10). This helper stays decoupled from the specific constant by
 * parsing the trailing "+" as the overflow marker.
 *
 * Extracted from the Family-tab view so the label-to-tooltip logic is
 * unit-testable in isolation and the view stays markup-only.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class SiblingGapRowMapper
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Map the sibling-age-gap histogram into a LineChart payload: categories
     * carry the bucket labels in display order (matches the repository's emit
     * order), the single series carries the counts plus per-point tooltip
     * overrides. Headers spell out "N-year gap" / "N or more years"; bodies
     * pluralise the "%s pairs" metric so the tooltip reads as a sentence rather
     * than a bare integer.
     *
     * @param array<string, int> $histogram Bucketed `{label: count}` map
     */
    public static function toLineChartPayload(array $histogram): LineChartPayload
    {
        $values            = array_values($histogram);
        $displayCategories = [];
        $tooltips          = [];
        $tooltipLabels     = [];

        foreach (array_keys($histogram) as $label) {
            $count               = $histogram[$label];
            $isOverflow          = str_ends_with($label, '+');
            $year                = (int) rtrim(rtrim($label, '+'), 'y');
            $displayCategories[] = $isOverflow
                ? I18N::translate('%sy+', I18N::number($year))
                : I18N::translate('%sy', I18N::number($year));
            $tooltips[]      = I18N::plural('%s pair', '%s pairs', $count, I18N::number($count));
            $tooltipLabels[] = $isOverflow
                ? I18N::translate('%s or more years', I18N::number($year))
                : I18N::plural('%s-year gap', '%s-year gaps', $year, I18N::number($year));
        }

        return new LineChartPayload(
            categories: $displayCategories,
            series: [
                new LineChartSeries(
                    name: I18N::translate('Consecutive-sibling pairs'),
                    values: $values,
                    tooltips: $tooltips,
                    tooltipLabels: $tooltipLabels,
                ),
            ],
        );
    }
}
