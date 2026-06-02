<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Calc;

use function array_keys;
use function array_slice;
use function array_sum;
use function count;
use function intdiv;
use function max;
use function min;
use function sort;
use function sqrt;
use function usort;

/**
 * Detects years whose recorded death count stands out against the local
 * baseline. For every year that sits at the centre of a complete fixed-width
 * window, the count is compared to the window's mean and standard deviation;
 * years whose standard score reaches the threshold are reported, ranked by
 * that score. The window median doubles as the "expected" baseline the spike is
 * measured against. The detector is pure: it takes a year → count map and
 * returns plain rows, so the surrounding repository owns the SQL and the value
 * objects while the statistics stay unit-testable in isolation.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MortalityAnomalies
{
    /**
     * Width of the rolling comparison window in years. Odd so the window has a
     * single centre year and an unambiguous median.
     */
    private const int WINDOW = 11;

    /**
     * Number of years on each side of the centre year — half the window,
     * rounded down. A year qualifies for evaluation only when this many years
     * of context exist on both sides, so the most recent and oldest years
     * (which lack a full window) are never falsely flagged.
     */
    private const int HALF = 5;

    /**
     * Minimum baseline (window median) for a year to be considered. Below this
     * the local death level is too thin for "above the baseline" to be
     * meaningful — a single extra death against a near-empty neighbourhood
     * would otherwise read as a large anomaly — and a zero baseline would
     * divide by zero. Two means a year is only flagged where dying was a
     * recurring event in the surrounding decade.
     */
    private const int MIN_BASELINE = 2;

    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Detect the mortality-anomaly years in a year → death-count map. Missing
     * years inside the observed span count as zero deaths. Only years with a
     * full {@see WINDOW}-year window, a non-zero window spread and a baseline of
     * at least {@see MIN_BASELINE} are considered; of those, the ones whose standard score reaches
     * `$zScoreThreshold` are selected by standard score descending (the year
     * itself as a deterministic tie-break) and capped at `$topN`, then returned
     * in chronological order (ascending year) for display.
     *
     * @param array<int, int> $deathsByYear    Deaths per (positive) year; gaps mean zero deaths
     * @param float           $zScoreThreshold Minimum standard score for a year to count as an anomaly
     * @param int             $topN            Maximum number of anomalies to return
     *
     * @return list<array{year: int, deaths: int, baseline: int, multiplier: float, zScore: float}>
     */
    public static function detect(array $deathsByYear, float $zScoreThreshold, int $topN): array
    {
        if (($topN < 1) || ($deathsByYear === [])) {
            return [];
        }

        $years   = array_keys($deathsByYear);
        $minYear = min($years);
        $maxYear = max($years);

        $anomalies = [];

        for ($year = $minYear + self::HALF; $year <= $maxYear - self::HALF; ++$year) {
            $window = [];

            for ($offset = $year - self::HALF; $offset <= $year + self::HALF; ++$offset) {
                $window[] = $deathsByYear[$offset] ?? 0;
            }

            $deaths   = $deathsByYear[$year] ?? 0;
            $baseline = self::median($window);

            // A spike against a too-thin neighbourhood is not a meaningful
            // mortality signal (and a zero baseline would divide by zero).
            if ($baseline < self::MIN_BASELINE) {
                continue;
            }

            $mean   = array_sum($window) / self::WINDOW;
            $stdDev = self::standardDeviation($window, $mean);

            // A perfectly flat window has no spread, so no year stands out.
            if ($stdDev <= 0.0) {
                continue;
            }

            $zScore = ($deaths - $mean) / $stdDev;

            if ($zScore < $zScoreThreshold) {
                continue;
            }

            $anomalies[] = [
                'year'       => $year,
                'deaths'     => $deaths,
                'baseline'   => $baseline,
                'multiplier' => (float) $deaths / $baseline,
                'zScore'     => $zScore,
            ];
        }

        // Select the most significant anomalies first (year as a deterministic
        // tie-break), then keep the requested number.
        usort(
            $anomalies,
            static function (array $a, array $b): int {
                $byScore = $b['zScore'] <=> $a['zScore'];

                if ($byScore !== 0) {
                    return $byScore;
                }

                return $a['year'] <=> $b['year'];
            },
        );

        $anomalies = array_slice($anomalies, 0, $topN);

        // Present the kept anomalies in chronological order.
        usort(
            $anomalies,
            static fn (array $a, array $b): int => $a['year'] <=> $b['year'],
        );

        return $anomalies;
    }

    /**
     * The median of a window. The window always has an odd length
     * ({@see WINDOW}), so the median is the single middle value after sorting.
     *
     * @param list<int> $window The window's death counts
     */
    private static function median(array $window): int
    {
        sort($window);

        return $window[intdiv(count($window), 2)];
    }

    /**
     * The population standard deviation of a window around a precomputed mean.
     *
     * @param list<int> $window The window's death counts
     * @param float     $mean   The arithmetic mean of the window
     */
    private static function standardDeviation(array $window, float $mean): float
    {
        $sumSquares = 0.0;

        foreach ($window as $value) {
            $sumSquares += ($value - $mean) ** 2;
        }

        return sqrt($sumSquares / count($window));
    }
}
