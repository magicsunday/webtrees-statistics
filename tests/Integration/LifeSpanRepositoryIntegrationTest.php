<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Repository\LifeSpanRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_keys;
use function array_map;
use function array_sum;
use function array_values;
use function count;
use function sprintf;

/**
 * End-to-end test of {@see LifeSpanRepository} against a fixture
 * that hits each behaviour the LifeSpan tab depends on:
 *
 * * Anna (1850-1925) — 75y → 70-79 bucket
 * * Berta (1900-1995) — 95y → 90-99 bucket
 * * Carl (1700-1820) — 120y → 100+ overflow
 * * Doris (1920-1923) — 3y → 0-9 bucket
 * * Emil (1880-1933) — 53y → 50-59 bucket
 * * Franz (1950, living, no death) — excluded from age-at-death
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class LifeSpanRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Construct a real LifeSpanRepository wired through core's
     * {@see StatisticsData} accessor — same constructor signature
     * the DI container would resolve at runtime.
     */
    private function repository(Tree $tree): LifeSpanRepository
    {
        return new LifeSpanRepository(
            $tree,
            $this->statisticsData($tree),
        );
    }

    /**
     * Age-at-death distribution puts each test individual in the
     * expected 10-year bucket, with the 100+ overflow catching the
     * Carl outlier. Franz (living) is silently excluded.
     */
    #[Test]
    public function ageAtDeathDistributionBucketsEverySurvivor(): void
    {
        $tree   = $this->importFixtureTree('life-span.ged');
        $result = $this->repository($tree)->ageAtDeathDistribution();

        // Every 10-year band exists in the output, even when empty.
        self::assertCount(11, $result);
        self::assertArrayHasKey('0–9', $result);
        self::assertArrayHasKey('100+', $result);

        self::assertSame(1, $result['0–9'], 'Doris (3y) sits in 0-9');
        self::assertSame(1, $result['50–59'], 'Emil (53y) sits in 50-59');
        self::assertSame(1, $result['70–79'], 'Anna (75y) sits in 70-79');
        self::assertSame(1, $result['90–99'], 'Berta (95y) sits in 90-99');
        self::assertSame(1, $result['100+'], 'Carl (120y) hits the overflow');

        // Five deceased individuals contributed; living Franz did not.
        self::assertSame(5, array_sum($result));
    }

    /**
     * topOldestDeceased returns Carl first (120y) then Berta (95y)
     * then Anna (75y); Franz is alive and never shows up.
     */
    #[Test]
    public function topOldestDeceasedRanksByAgeDesc(): void
    {
        $tree   = $this->importFixtureTree('life-span.ged');
        $result = $this->repository($tree)->topOldestDeceased(10);

        $values = array_values($result);
        self::assertGreaterThanOrEqual(120, $values[0]);
        self::assertGreaterThanOrEqual($values[1], $values[0]);
    }

    /**
     * topOldestLiving returns living individuals (no DEAT date)
     * with their current age. The fixture has one such — Franz
     * (1950, still living). Other rows have DEAT and must not
     * appear.
     */
    #[Test]
    public function topOldestLivingReturnsOnlyLivingIndividuals(): void
    {
        $tree   = $this->importFixtureTree('life-span.ged');
        $result = $this->repository($tree)->topOldestLiving(10);

        // Exactly one living individual in the fixture (Franz).
        self::assertCount(1, $result);
        // Franz born 1950 → his current age in test-run year is
        // > 60 in any plausible environment. Use a generous floor
        // so the test stays stable as years pass.
        $age = array_values($result)[0];
        self::assertGreaterThan(60, $age);
    }

    /**
     * livingByAgeBand groups Franz (1950, living) into the 65+
     * bucket. Every other fixture individual is deceased and
     * contributes nothing.
     */
    #[Test]
    public function livingByAgeBandGroupsByLifeStage(): void
    {
        $tree   = $this->importFixtureTree('life-span.ged');
        $result = $this->repository($tree)->livingByAgeBand();

        $totals = [];

        foreach ($result as $entry) {
            $totals[$entry['label']] = $entry['value'];
        }

        // Four bands always returned, even when most are empty.
        self::assertCount(4, $result);
        self::assertSame(1, $totals['65+'] ?? null, 'Franz is the only living individual');
    }

    /**
     * birthsByDecade aggregates every BIRT date across the tree
     * into a decade bucket, fills inner zero-decades so gaps stay
     * visible, and trims leading / trailing zeroes via
     * HistogramTrim. The fixture's six dated births are spread
     * across 1700, 1850, 1880, 1900, 1920, 1950 decade starts,
     * so the visible window is 1700..1950 with one tick per
     * active decade and zero for all empty in-between decades.
     * Decade keys are integer starts; the view layer formats them
     * via `I18N::translate('%ss', $decade)`.
     */
    #[Test]
    public function birthsByDecadeFillsInnerGapsAndTrimsBoundaries(): void
    {
        $tree   = $this->importFixtureTree('life-span.ged');
        $result = $this->repository($tree)->birthsByDecade();

        // First and last keys frame the visible range.
        $keys = array_keys($result);
        self::assertSame(1700, $keys[0]);
        self::assertSame(1950, $keys[count($keys) - 1]);

        // Every active decade carries exactly one birth.
        self::assertSame(1, $result[1700]);
        self::assertSame(1, $result[1850]);
        self::assertSame(1, $result[1880]);
        self::assertSame(1, $result[1900]);
        self::assertSame(1, $result[1920]);
        self::assertSame(1, $result[1950]);

        // Inner empty decade is rendered as a 0 bucket, not dropped.
        self::assertSame(0, $result[1710]);
        self::assertSame(0, $result[1870]);
        self::assertSame(0, $result[1930]);

        // Dense decade window between first and last active decade —
        // assert the exact key sequence instead of a count so that
        // adding a fixture row that widens or narrows the envelope
        // fails the assertion at the boundary rather than at an
        // opaque bucket-count mismatch.
        self::assertSame(range(1700, 1950, 10), array_keys($result));
    }

    /**
     * cumulativeBirthsByDecade layers a running sum on top of the
     * birthsByDecade() payload. The life-span.ged fixture has one
     * birth each at decade starts 1700, 1850, 1880, 1900, 1920,
     * 1950, with zero-filled inner decades. The cumulative series
     * therefore steps up monotonically: 1 at 1700, holds at 1 across
     * the long 1710..1840 gap, then 2 at 1850, 3 at 1880, 4 at 1900,
     * 5 at 1920, and 6 at 1950. Visible window and decade keys
     * match birthsByDecade() exactly.
     */
    #[Test]
    public function cumulativeBirthsByDecadeStepsUpMonotonically(): void
    {
        $tree   = $this->importFixtureTree('life-span.ged');
        $result = $this->repository($tree)->cumulativeBirthsByDecade();

        // Same visible window as birthsByDecade().
        self::assertSame(range(1700, 1950, 10), array_keys($result));

        // Cumulative steps at each active decade.
        self::assertSame(1, $result[1700]);
        self::assertSame(2, $result[1850]);
        self::assertSame(3, $result[1880]);
        self::assertSame(4, $result[1900]);
        self::assertSame(5, $result[1920]);
        self::assertSame(6, $result[1950]);

        // Inner zero-decades hold the running total — no decrease,
        // no reset across the long 1710..1840 silent stretch.
        self::assertSame(1, $result[1710]);
        self::assertSame(1, $result[1840]);
        self::assertSame(2, $result[1870]);
        self::assertSame(5, $result[1930]);

        // Strictly non-decreasing across the whole window.
        $previous = 0;

        foreach ($result as $decade => $value) {
            self::assertGreaterThanOrEqual($previous, $value, sprintf('Decade %d decreased', $decade));
            $previous = $value;
        }
    }

    /**
     * Single-birth tree collapses to a one-entry cumulative series:
     * one decade key, one running-total of 1. Locks the lower-bound
     * behaviour of the aggregator so a future "drop singleton series"
     * tweak fails the test instead of silently regressing the
     * minimum-data display.
     */
    #[Test]
    public function cumulativeBirthsByDecadeForSingleBirthHasOneStep(): void
    {
        $tree   = $this->importFixtureTree('empty-marriages.ged');
        $result = $this->repository($tree)->cumulativeBirthsByDecade();

        self::assertSame([1900 => 1], $result);
    }

    /**
     * life-span.ged carries five deceased individuals — Anna 75y in
     * 19th c., Berta 95y in 20th c., Carl 120y in 18th c., Doris 3y
     * in 20th c., Emil 53y in 19th c. The 18th-century cohort is a
     * single individual and stays below MIN_COHORT_SIZE; the 19th
     * and 20th each have two and are also below the threshold. The
     * fixture is therefore expected to drop every cohort.
     */
    #[Test]
    public function deathAgeDistributionByCenturyDropsSubThresholdCohorts(): void
    {
        $tree = $this->importFixtureTree('life-span.ged');

        self::assertSame([], $this->repository($tree)->deathAgeDistributionByCentury());
    }

    /**
     * empty-marriages.ged has one dated BIRT but no DEAT, so no
     * BirthDeathPair survives the join. Locks the empty-result
     * short-circuit for trees without computable lifespans.
     */
    #[Test]
    public function deathAgeDistributionByCenturyIsEmptyWithoutDeathDates(): void
    {
        $tree = $this->importFixtureTree('empty-marriages.ged');

        self::assertSame([], $this->repository($tree)->deathAgeDistributionByCentury());
    }

    /**
     * Sub-threshold sample (life-span.ged only carries 5 dated
     * deaths, the threshold is 12) returns null because the
     * winter / baseline ratio derived from too few samples is too
     * noisy to publish.
     */
    #[Test]
    public function deathWinterPeakScoreReturnsNullBelowMinimumSample(): void
    {
        $tree = $this->importFixtureTree('life-span.ged');

        self::assertNull($this->repository($tree)->deathWinterPeakScore());
    }

    /**
     * Winter-peak fixture has 12 deaths with six in DEC/JAN/FEB and
     * six spread across the rest of the calendar. Score becomes
     * (6 / 3) / (12 / 12) = 2.0 — the peak is exactly twice the
     * baseline rate. Locks both the threshold trip and the formula.
     */
    #[Test]
    public function deathWinterPeakScoreComputesRatioForWinterHeavyFixture(): void
    {
        $tree   = $this->importFixtureTree('winter-peak.ged');
        $result = $this->repository($tree)->deathWinterPeakScore();

        self::assertNotNull($result);
        self::assertSame(2.0, $result->score);
        self::assertSame(6, $result->seasonCount);
        self::assertSame(12, $result->total);
    }

    /**
     * Evenly-distributed fixture: one death per calendar month,
     * twelve months. Winter share equals the baseline so the score
     * lands exactly at 1.0 — the neutral middle the consumer reads
     * as "no winter peak".
     */
    #[Test]
    public function deathWinterPeakScoreLandsAtOneForEvenDistribution(): void
    {
        $tree   = $this->importFixtureTree('winter-flat.ged');
        $result = $this->repository($tree)->deathWinterPeakScore();

        self::assertNotNull($result);
        self::assertSame(1.0, $result->score);
        self::assertSame(3, $result->seasonCount);
        self::assertSame(12, $result->total);
    }

    /**
     * Winter-trough fixture: one death in JAN, none in DEC/FEB,
     * eleven spread across the rest. Winter density falls well
     * below the baseline so the score sits under 1.0 — the
     * widget surfaces this as "winter is under-represented".
     */
    #[Test]
    public function deathWinterPeakScoreFallsBelowOneForWinterPoorDistribution(): void
    {
        $tree   = $this->importFixtureTree('winter-trough.ged');
        $result = $this->repository($tree)->deathWinterPeakScore();

        self::assertNotNull($result);
        self::assertLessThan(1.0, $result->score);
        self::assertSame(1, $result->seasonCount);
        self::assertSame(12, $result->total);
    }

    /**
     * Survival curve emits one series per qualifying birth century
     * with monotonically falling values from 100 % at age 0 down
     * to the post-100 floor. The fixture carries:
     * * 19th + 20th century cohorts (30 individuals each, both qualify)
     * * 18th-century cohort with 5 individuals (well below floor)
     * * 17th-century cohort with 29 individuals — exactly one short
     *   of the {@see MIN_COHORT_SIZE_SURVIVAL} floor, locking the
     *   strict-less-than boundary against off-by-one regressions.
     *
     * Categories are always the 11 age anchors regardless of which
     * cohorts pass the floor.
     */
    #[Test]
    public function survivalFunctionByCenturyEmitsOneSeriesPerQualifyingCohort(): void
    {
        $tree   = $this->importFixtureTree('survival-curve-cohorts.ged');
        $result = $this->repository($tree)->survivalFunctionByCentury();

        self::assertSame(
            ['0', '10', '20', '30', '40', '50', '60', '70', '80', '90', '100'],
            $result->categories,
        );

        // Only the 19th and 20th cohorts qualify. The 17th (29 INDI)
        // and 18th (5 INDI) cohorts both sit below
        // MIN_COHORT_SIZE_SURVIVAL=30 and are dropped from the output.
        self::assertCount(2, $result->series, 'only the 19th + 20th cohorts clear MIN_COHORT_SIZE_SURVIVAL=30');

        $seriesNames = array_map(
            static fn (LineChartSeries $s): string => $s->name,
            $result->series,
        );

        // Lock the chronological sort order so the positional reads
        // below are guarded against a future re-sort of $cohorts.
        self::assertSame(['19th', '20th'], $seriesNames, 'qualifying cohorts ordered chronologically');
        self::assertNotContains('17th', $seriesNames, '29-INDI 17th cohort sits one below the floor and must be dropped');
        self::assertNotContains('18th', $seriesNames, '5-INDI 18th cohort is far below the floor and must be dropped');

        // 19th century cohort: 5 die at 5y, 5 at 25y, 10 at 65y, 10 at 80y.
        $nineteenth = $result->series[0];
        self::assertSame(100.0, $nineteenth->values[0], 'all 30 individuals are alive at age 0');
        self::assertEqualsWithDelta(83.3, $nineteenth->values[1], 0.1, 'age 10: 25 of 30 reached');
        self::assertEqualsWithDelta(66.7, $nineteenth->values[3], 0.1, 'age 30: 20 of 30 reached');
        self::assertEqualsWithDelta(33.3, $nineteenth->values[7], 0.1, 'age 70: 10 of 30 reached');
        self::assertEqualsWithDelta(33.3, $nineteenth->values[8], 0.1, 'age 80: 10 of 30 reached');
        self::assertSame(0.0, $nineteenth->values[9], 'age 90: nobody from this cohort');

        // 20th century cohort: 5 die at 50y, 15 at 80y, 10 at 95y.
        $twentieth = $result->series[1];
        self::assertSame(100.0, $twentieth->values[0]);
        self::assertSame(100.0, $twentieth->values[5], 'age 50: those dying at 50 still count');
        self::assertEqualsWithDelta(83.3, $twentieth->values[6], 0.1, 'age 60: 25 of 30 reached');
        self::assertEqualsWithDelta(83.3, $twentieth->values[8], 0.1, 'age 80: 25 of 30 reached');
        self::assertEqualsWithDelta(33.3, $twentieth->values[9], 0.1, 'age 90: 10 of 30 reached');
        self::assertSame(0.0, $twentieth->values[10], 'age 100: nobody reached the centenarian anchor');
    }

    /**
     * A tree with no recorded BIRT+DEAT pairs at all returns an
     * empty payload (no categories, no series) so the widget
     * surfaces the EmptyStatePlaceholder instead of an axis
     * scaffold with zero lines. empty-marriages.ged carries one
     * BIRT without a DEAT — exactly the case where every cohort
     * stays empty.
     */
    #[Test]
    public function survivalFunctionByCenturyReturnsEmptyPayloadWithoutRecordedDeaths(): void
    {
        $tree   = $this->importFixtureTree('empty-marriages.ged');
        $result = $this->repository($tree)->survivalFunctionByCentury();

        self::assertSame([], $result->categories);
        self::assertSame([], $result->series);
    }
}
