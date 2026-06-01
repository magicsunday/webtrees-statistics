<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use MagicSunday\Webtrees\Statistic\Model\Pyramid\PopulationPyramidPayload;

/**
 * Wraps the two-sided couple-age-gap distribution
 * ({@see \MagicSunday\Webtrees\Statistic\Repository\MarriageRepository::ageGapDistribution()})
 * into the {@see PopulationPyramidPayload} the chart-lib diverging-bar widget
 * consumes: a single group whose rows are the shared magnitude bands, husband
 * counts on the left, wife counts on the right. An all-zero distribution yields
 * the empty payload so the caller renders the empty placeholder.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class CoupleAgeGapRowMapper
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Fold the two-sided distribution into a single-group pyramid payload.
     *
     * @param array<string, array{left: int, right: int}> $ageGap Band label → `{left: husband-older, right: wife-older}` counts
     *
     * @return PopulationPyramidPayload Single-group `{left, right}` payload, empty when no band carries a count
     */
    public static function toModel(array $ageGap): PopulationPyramidPayload
    {
        $bands  = [];
        $column = [];
        $total  = 0;

        foreach ($ageGap as $band => $counts) {
            $left  = $counts['left'];
            $right = $counts['right'];

            $bands[]  = $band;
            $column[] = ['left' => $left, 'right' => $right];
            $total += $left + $right;
        }

        if ($total === 0) {
            return new PopulationPyramidPayload([], [], []);
        }

        return new PopulationPyramidPayload([''], $bands, [$column]);
    }
}
