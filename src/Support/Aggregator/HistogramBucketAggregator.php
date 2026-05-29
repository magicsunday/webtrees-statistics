<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use function array_chunk;
use function array_sum;
use function array_values;
use function ceil;
use function count;
use function intdiv;

/**
 * Collapses a numeric `[label => count]` distribution into a smaller set of
 * equally-sized adjacent-bucket groups so a histogram with dozens of stops
 * still reads clearly. The number of resulting bars is bounded by `$maxBars`;
 * the group size is the smallest integer from a fixed candidate ladder (1, 5,
 * 10, 25, 50, 100) needed to bring the histogram under that bound — keeping
 * labels human- readable ("0–4", "5–9", "10–14", …) instead of arbitrary-step
 * ranges.
 *
 * Pure helper, no I/O. Output keys are returned as positional band labels; PHP
 * auto-casts numeric-string keys back to int, so callers consuming the label as
 * a string must coerce explicitly.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class HistogramBucketAggregator
{
    /**
     * Default ceiling on the rendered bar count. Sized for the 12-col grid card
     * width that hosts every histogram in the module — beyond ~24 ticks the
     * chart-lib BarChart axis labels start to overlap regardless of font size.
     */
    public const int DEFAULT_MAX_BARS = 24;

    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Collapse `$series` into `<= $maxBars` adjacent bands. Picks the smallest
     * power-of-five chunk size needed to fit the cap; falls back to a derived
     * chunk size when the largest candidate (100) still doesn't fit, so very
     * large inputs are guaranteed to stay under `$maxBars`.
     *
     * @param array<int, int> $series  Bucket index → count
     * @param int             $maxBars Upper bound on the returned bar count
     *
     * @return array<int|string, int> Band label → count
     */
    public static function compressByFives(array $series, int $maxBars): array
    {
        if ($maxBars < 1) {
            $maxBars = 1;
        }

        $values = array_values($series);
        $total  = count($values);

        if ($total === 0) {
            return [];
        }

        $chunkSize = null;

        foreach ([1, 5, 10, 25, 50, 100] as $candidate) {
            $resultingBars = intdiv($total, $candidate) + ((($total % $candidate) > 0) ? 1 : 0);

            if ($resultingBars <= $maxBars) {
                $chunkSize = $candidate;

                break;
            }
        }

        // Every candidate exceeded the cap (huge series) — derive a
        // dense chunk size so the bar count still respects $maxBars.
        $chunkSize ??= (int) ceil($total / $maxBars);

        return self::group($values, $chunkSize);
    }

    /**
     * Bucket `$values` into adjacent groups of `$chunkSize` and emit a `[label
     * => count]` map. Each output label is either a single index ("3") or a
     * closed range ("0–4"), produced from the positional index of the
     * first/last value in the source list.
     *
     * @param list<int> $values    Per-bucket counts in display order
     * @param int       $chunkSize Number of adjacent buckets to fold into one band
     *
     * @return array<int|string, int> Band label → count
     */
    private static function group(array $values, int $chunkSize): array
    {
        if ($chunkSize <= 1) {
            $out = [];

            foreach ($values as $index => $value) {
                $out[(string) $index] = $value;
            }

            return $out;
        }

        $out = [];

        foreach (array_chunk($values, $chunkSize) as $chunkIndex => $chunk) {
            $from        = $chunkIndex * $chunkSize;
            $to          = $from + count($chunk) - 1;
            $label       = ($from === $to) ? (string) $from : ($from . '–' . $to);
            $out[$label] = array_sum($chunk);
        }

        return $out;
    }
}
