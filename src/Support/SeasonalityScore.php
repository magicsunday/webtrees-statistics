<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use MagicSunday\Webtrees\Statistic\Model\Dto\Metric\WinterPeakScore;

use function array_sum;
use function in_array;
use function round;
use function strtoupper;

/**
 * Generic month-count → seasonality-score calculator. Given a
 * 12-bucket map keyed by GEDCOM month abbreviation (JAN, FEB, ...,
 * DEC) and a list of "season months" (e.g. winter = DEC+JAN+FEB),
 * compute the relative-density score the season carries compared
 * to an evenly-distributed baseline.
 *
 * Formula: score = (seasonCount / seasonMonths) / (totalCount / 12).
 * 1.0 means perfectly even (no seasonality), > 1.0 means the
 * season carries proportionally more events than baseline,
 * < 1.0 means it carries fewer.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class SeasonalityScore
{
    /**
     * Conventional Northern-Hemisphere winter as a GEDCOM-abbrev
     * triple — December, January, February.
     */
    public const array NORTHERN_WINTER = ['DEC', 'JAN', 'FEB'];

    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Compute the season-density score for the given month-keyed
     * count map. The map keys are matched case-insensitively
     * against the season list, so a caller working with
     * translated month labels can still drive this helper by
     * mapping back to GEDCOM abbreviations before calling.
     *
     * Returns `null` when the total count is below `$minSample` —
     * scores derived from very few samples are dominated by
     * noise and would mislead the consumer.
     *
     * @param array<string, int> $monthCounts Map of month-abbrev → count (any case)
     * @param list<string>       $season      GEDCOM abbreviations of the season's months
     * @param int                $minSample   Minimum total count for the score to be meaningful
     */
    public static function score(array $monthCounts, array $season, int $minSample = 12): ?WinterPeakScore
    {
        $total       = array_sum($monthCounts);
        $seasonCount = 0;

        foreach ($monthCounts as $key => $count) {
            if (in_array(strtoupper($key), $season, true)) {
                $seasonCount += $count;
            }
        }

        if ($total < $minSample) {
            return null;
        }

        if ($season === []) {
            return null;
        }

        $baseline = $total / 12;

        if ($baseline <= 0.0) {
            return null;
        }

        $perMonthInSeason = $seasonCount / count($season);
        $score            = round($perMonthInSeason / $baseline, 2);

        return new WinterPeakScore(
            score: $score,
            seasonCount: $seasonCount,
            total: $total,
        );
    }
}
