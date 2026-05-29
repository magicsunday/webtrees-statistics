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
use MagicSunday\Webtrees\Statistic\Support\Locale\DecadeName;

use function round;

/**
 * Folds a `[cohort => rate]` divorce-rate distribution (rate as a 0..1
 * fraction) into the three parallel `categories` / `values` / `tooltips` lists
 * the chart-lib LineChart widget consumes when fed the raw-array payload shape.
 * Each rate is rounded to a whole percentage so the cohort-line reads as `48 %`
 * instead of `47.8312 %` — the cohort sample size rarely supports finer
 * resolution and the chart axis would be unreadable otherwise.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DivorceCohortRowMapper
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * @param array<int|string, float> $cohortRates Cohort → divorce-rate (0..1)
     *
     * @return array{categories: list<string>, values: list<int>, tooltips: list<string>, tooltipLabels: list<string>}
     */
    public static function toLineSeries(array $cohortRates): array
    {
        $categories    = [];
        $values        = [];
        $tooltips      = [];
        $tooltipLabels = [];

        foreach ($cohortRates as $cohort => $rate) {
            $decadeStart     = (int) $cohort;
            $percent         = (int) round($rate * 100);
            $categories[]    = DecadeName::for($decadeStart);
            $values[]        = $percent;
            $tooltips[]      = I18N::translate('%s%% divorced', I18N::number($percent));
            $tooltipLabels[] = DecadeName::longLabel($decadeStart);
        }

        return [
            'categories'    => $categories,
            'values'        => $values,
            'tooltips'      => $tooltips,
            'tooltipLabels' => $tooltipLabels,
        ];
    }
}
