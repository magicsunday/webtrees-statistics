<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use function array_keys;
use function array_slice;

/**
 * Histogram-display helpers used by the chart view layer to suppress
 * always-empty leading / trailing buckets. The age-at-marriage and
 * age-at-divorce widgets render two sex-split histograms side by
 * side; rendering 0–4 / 5–9 / 10–14 rows when nobody in either sex
 * has a marriage in that bucket is pure visual noise. A bucket is
 * only dropped when BOTH sex variants are 0 — keeping an
 * outlier in either series prevents the histogram from misleading
 * the reader into thinking the bucket doesn't exist.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class HistogramTrim
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Drop leading and trailing buckets that are 0 (or missing) in
     * both `$a` and `$b`. The two arrays MUST share the same key
     * order (typically because they come from the same repository
     * with a sex parameter). Returns slices with the same key shape,
     * trimmed to the inclusive range from the first to the last
     * bucket where at least one series carries a non-zero count.
     *
     * If both arrays are completely empty (every bucket 0 in both),
     * the originals are returned unchanged so the caller can decide
     * how to handle that case at the view level (typically by
     * hiding the card entirely).
     *
     * @param array<string, int> $a First sex variant (e.g. husbands)
     * @param array<string, int> $b Second sex variant (e.g. wives)
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    public static function dropCoZeroEnds(array $a, array $b): array
    {
        $keys  = array_keys($a);
        $first = null;
        $last  = null;

        foreach ($keys as $index => $key) {
            $aCount = $a[$key] ?? 0;
            $bCount = $b[$key] ?? 0;

            if (($aCount === 0) && ($bCount === 0)) {
                continue;
            }

            $first ??= $index;
            $last = $index;
        }

        if (($first === null) || ($last === null)) {
            return [$a, $b];
        }

        $length = ($last - $first) + 1;

        return [
            array_slice($a, $first, $length, true),
            array_slice($b, $first, $length, true),
        ];
    }
}
