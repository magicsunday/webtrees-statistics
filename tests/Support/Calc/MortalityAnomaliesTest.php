<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Calc;

use MagicSunday\Webtrees\Statistic\Support\Calc\MortalityAnomalies;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_fill;

/**
 * Branch coverage for the rolling-window mortality-anomaly detector: a single
 * spike's statistics, the threshold and window guards, the flat-series no-op,
 * z-score ranking, the year tie-break, and the top-N cap.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(MortalityAnomalies::class)]
final class MortalityAnomaliesTest extends TestCase
{
    /**
     * A flat baseline of five deaths per year for years 0..10 with a single
     * spike at the centre year. Only the centre year (5) has a full 11-year
     * window, and the spike clears any reasonable threshold.
     *
     * @param int $spikeYear  The year the spike sits on
     * @param int $spikeValue The death count at the spike
     *
     * @return array<int, int>
     */
    private function plateauWithSpike(int $spikeYear, int $spikeValue): array
    {
        $series = [];

        for ($year = 0; $year <= 10; ++$year) {
            $series[$year] = 5;
        }

        $series[$spikeYear] = $spikeValue;

        return $series;
    }

    /**
     * The centre-year spike is detected with the window median as the baseline,
     * the deaths-over-baseline multiplier, and a positive standard score.
     */
    #[Test]
    public function detectsSingleSpikeWithWindowStatistics(): void
    {
        $result = MortalityAnomalies::detect($this->plateauWithSpike(5, 20), 2.0, 10);

        self::assertCount(1, $result);
        self::assertSame(5, $result[0]['year']);
        self::assertSame(20, $result[0]['deaths']);
        self::assertSame(5, $result[0]['baseline']);
        self::assertSame(4.0, $result[0]['multiplier']);
        self::assertEqualsWithDelta(3.162, $result[0]['zScore'], 0.01);
    }

    /**
     * A threshold above the spike's score suppresses it.
     */
    #[Test]
    public function thresholdAboveScoreExcludesTheSpike(): void
    {
        self::assertSame([], MortalityAnomalies::detect($this->plateauWithSpike(5, 20), 4.0, 10));
    }

    /**
     * A perfectly flat series has no window spread, so no year stands out.
     */
    #[Test]
    public function flatSeriesYieldsNoAnomalies(): void
    {
        $flat = array_fill(0, 11, 5);

        self::assertSame([], MortalityAnomalies::detect($flat, 2.0, 10));
    }

    /**
     * A spike whose surrounding window is mostly empty years has a zero median
     * baseline. Such a year is skipped rather than dividing the count by a zero
     * baseline — the "spike against an effectively empty neighbourhood" guard,
     * which the flat-series test does not reach (there the window spread is zero
     * instead).
     */
    #[Test]
    public function spikeAgainstAnEmptyNeighbourhoodIsSkipped(): void
    {
        // Only the span endpoints and the centre carry deaths, so the window
        // around year 5 is [1,0,0,0,0,20,0,0,0,0,1]: a zero median but a
        // non-zero mean and spread, which reaches the baseline guard.
        $series = [0 => 1, 5 => 20, 10 => 1];

        self::assertSame([], MortalityAnomalies::detect($series, 2.0, 10));
    }

    /**
     * A spike against a baseline below the minimum (here a window median of one
     * death) is too thin to be a meaningful signal and is skipped, even though
     * its standard score would otherwise clear the threshold.
     */
    #[Test]
    public function thinBaselineBelowMinimumIsSkipped(): void
    {
        // Years 0..10 with one death each and a spike at the centre: the window
        // median is 1, below the minimum baseline.
        $series = [];

        for ($year = 0; $year <= 10; ++$year) {
            $series[$year] = 1;
        }

        $series[5] = 6;

        self::assertSame([], MortalityAnomalies::detect($series, 2.0, 10));
    }

    /**
     * A spike on a year that lacks five years of context on both sides is never
     * evaluated, so it is not reported.
     */
    #[Test]
    public function spikeWithoutFullWindowIsNotFlagged(): void
    {
        // Year 9 sits one year short of a full right-hand window (max year is
        // 10), so only year 5 — a flat baseline cell — is ever evaluated.
        self::assertSame([], MortalityAnomalies::detect($this->plateauWithSpike(9, 30), 2.0, 10));
    }

    /**
     * Region A (years 0..10): a spike at 5 plus a secondary bump at 3 widens the
     * window's spread, lowering its score below a clean single spike. Region B
     * (years 100..110): a clean spike at 105 keeps the higher score.
     *
     * @return array<int, int>
     */
    private function twoRegionsLowThenHighScore(): array
    {
        $series = [];

        for ($year = 0; $year <= 10; ++$year) {
            $series[$year] = 5;
        }

        $series[5] = 20;
        $series[3] = 10;

        for ($year = 100; $year <= 110; ++$year) {
            $series[$year] = 5;
        }

        $series[105] = 20;

        return $series;
    }

    /**
     * The kept anomalies are returned in chronological order, not by score: the
     * earlier year leads even though its standard score is the lower of the two.
     */
    #[Test]
    public function keptAnomaliesAreReturnedInChronologicalOrder(): void
    {
        $result = MortalityAnomalies::detect($this->twoRegionsLowThenHighScore(), 2.0, 10);

        self::assertSame([5, 105], array_column($result, 'year'));
        // The chronologically-first row carries the lower score, proving the
        // output is ordered by year and not by score.
        self::assertLessThan($result[1]['zScore'], $result[0]['zScore']);
    }

    /**
     * Selection (before the chronological display sort) keeps the most
     * significant anomalies: capped to one, the higher-scoring later spike
     * survives over the lower-scoring earlier one.
     */
    #[Test]
    public function selectsMostSignificantWhenCapped(): void
    {
        $result = MortalityAnomalies::detect($this->twoRegionsLowThenHighScore(), 2.0, 1);

        self::assertSame([105], array_column($result, 'year'));
    }

    /**
     * Two spikes with an identical score are ordered by year ascending.
     */
    #[Test]
    public function equalScoresAreTieBrokenByYearAscending(): void
    {
        $series = $this->plateauWithSpike(5, 20);

        for ($year = 100; $year <= 110; ++$year) {
            $series[$year] = 5;
        }

        $series[105] = 20;

        $result = MortalityAnomalies::detect($series, 2.0, 10);

        self::assertSame([5, 105], array_column($result, 'year'));
        self::assertEqualsWithDelta($result[0]['zScore'], $result[1]['zScore'], 0.0001);
    }

    /**
     * The result is capped at the requested size, keeping the highest-ranked
     * anomalies.
     */
    #[Test]
    public function capsToTopN(): void
    {
        $series = $this->plateauWithSpike(5, 20);

        for ($year = 100; $year <= 110; ++$year) {
            $series[$year] = 5;
        }

        $series[105] = 20;

        $result = MortalityAnomalies::detect($series, 2.0, 1);

        self::assertCount(1, $result);
        self::assertSame(5, $result[0]['year']);
    }

    /**
     * Empty input and a non-positive cap both yield an empty list.
     */
    #[Test]
    public function emptyInputAndNonPositiveCapReturnEmpty(): void
    {
        self::assertSame([], MortalityAnomalies::detect([], 2.0, 10));
        self::assertSame([], MortalityAnomalies::detect($this->plateauWithSpike(5, 20), 2.0, 0));
        self::assertSame([], MortalityAnomalies::detect($this->plateauWithSpike(5, 20), 2.0, -1));
    }
}
