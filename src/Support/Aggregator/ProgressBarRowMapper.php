<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use function str_pad;

use const STR_PAD_LEFT;

/**
 * Folds a `[label => value]` map into a list of progress-bar rows
 * with pre-computed display state (zero-padded rank, percentage of
 * the row with the largest value). Both the `progress-list.phtml`
 * and the `podium.phtml` partials consume the same row shape, so
 * the computation lives in one place — extension to new Top-N
 * surfaces just calls the mapper instead of redoing the loop and
 * the max-pick inline in the template.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ProgressBarRowMapper
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Map a `[label => value]` distribution to a list of rows. Each
     * row carries the original label and numeric value plus two
     * display-derived fields: a zero-padded two-digit `rank` ("01",
     * "02", …) and a `percentage` of the row with the largest value
     * (0–100). Returns an empty list when the input is empty or
     * every value is non-positive — the caller renders the empty
     * placeholder in that branch.
     *
     * @param array<string, int|float> $data Label → value map (display order = caller order)
     *
     * @return list<array{rank: string, label: string, value: float, percentage: float}>
     */
    public static function toRows(array $data): array
    {
        if ($data === []) {
            return [];
        }

        $maxValue = 0.0;

        foreach ($data as $value) {
            $floatValue = (float) $value;

            if ($floatValue > $maxValue) {
                $maxValue = $floatValue;
            }
        }

        if ($maxValue <= 0) {
            return [];
        }

        $rows = [];
        $i    = 0;

        foreach ($data as $label => $value) {
            $floatValue = (float) $value;
            $rows[]     = [
                'rank'       => str_pad((string) (++$i), 2, '0', STR_PAD_LEFT),
                'label'      => $label,
                'value'      => $floatValue,
                'percentage' => ($floatValue / $maxValue) * 100,
            ];
        }

        return $rows;
    }
}
