<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use Fisharebest\Webtrees\I18N;

use function rtrim;
use function str_ends_with;

/**
 * Converts the bucketed `siblingAgeGapDistribution()` map into the
 * `{x, y, tooltip, tooltipLabel}` row shape the chart-lib
 * AreaDensity widget consumes. The repository emits "Ny" labels
 * for 0..max-1 and an "Ny+" overflow label for max-and-above
 * (`SIBLING_GAP_MAX` currently 10). This helper stays decoupled
 * from the specific constant by parsing the trailing "+" as the
 * overflow marker and the numeric prefix as the x position.
 *
 * Extracted from the Family-tab view so the label-to-x logic is
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
     * Map the sibling-age-gap histogram into AreaDensity-ready rows.
     * Each row carries the numeric x position (year), the count,
     * a localised tooltip body ("N pairs"), and a localised
     * tooltip header ("N-year gap" or "N or more years" on the
     * overflow row).
     *
     * @param array<string, int> $histogram Bucketed `{label: count}` map
     *
     * @return list<array{x: int, y: int, tooltip: string, tooltipLabel: string}>
     */
    public static function toRows(array $histogram): array
    {
        $rows = [];

        foreach ($histogram as $label => $count) {
            $isOverflow = str_ends_with($label, '+');
            $x          = (int) rtrim(rtrim($label, '+'), 'y');

            $rows[] = [
                'x'            => $x,
                'y'            => $count,
                'tooltip'      => I18N::plural('%s pair', '%s pairs', $count, I18N::number($count)),
                'tooltipLabel' => $isOverflow
                    ? I18N::translate('%s or more years', I18N::number($x))
                    : I18N::plural('%s-year gap', '%s-year gaps', $x, I18N::number($x)),
            ];
        }

        return $rows;
    }
}
