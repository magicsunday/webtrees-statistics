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
use MagicSunday\Webtrees\Statistic\Model\Heatmap\HeatmapPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Model\Metric\WinterPeakScore;
use MagicSunday\Webtrees\Statistic\Model\Pyramid\PopulationPyramidPayload;
use MagicSunday\Webtrees\Statistic\Model\Ranking\RankingEntry;
use MagicSunday\Webtrees\Statistic\Repository\LifeSpanRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\HistogramTrim;
use MagicSunday\Webtrees\Statistic\Support\Database\BirthDeathPairsQuery;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use MagicSunday\Webtrees\Statistic\Support\Locale\IsoCountryMap;
use MagicSunday\Webtrees\Statistic\Support\Locale\MonthName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_fill;
use function array_keys;
use function array_map;
use function array_sum;
use function count;
use function sprintf;

/**
 * End-to-end test of {@see LifeSpanRepository} against a fixture that hits each
 * behaviour the LifeSpan tab depends on:
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
#[CoversClass(LifeSpanRepository::class)]
#[UsesClass(HeatmapPayload::class)]
#[UsesClass(LineChartPayload::class)]
#[UsesClass(LineChartSeries::class)]
#[UsesClass(WinterPeakScore::class)]
#[UsesClass(PopulationPyramidPayload::class)]
#[UsesClass(RankingEntry::class)]
#[UsesClass(HistogramTrim::class)]
#[UsesClass(BirthDeathPairsQuery::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(DateJoin::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
#[UsesClass(CenturyName::class)]
#[UsesClass(MonthName::class)]
final class LifeSpanRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Construct a real LifeSpanRepository wired through core's {@see
     * StatisticsData} accessor — same constructor signature the DI container
     * would resolve at runtime.
     */
    private function repository(Tree $tree): LifeSpanRepository
    {
        return new LifeSpanRepository(
            $tree,
            $this->statisticsData($tree),
            new IsoCountryMap(),
        );
    }

    /**
     * Age-at-death distribution puts each test individual in the expected
     * 10-year bucket, with the 100+ overflow catching the Carl outlier. Franz
     * (living) is silently excluded.
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
     * The age-at-death histogram counts each deceased individual once even when
     * a birth or death is a range date, and it bins on the upper-bound (maximum
     * possible) lifespan. I1 (`BIRT 1850`, `DEAT BET 1920 AND 1924`) has two
     * death rows both in the 70–79 band — a raw per-row count would tally I1
     * twice. I3 (`BIRT 1850`, `DEAT BET 1918 AND 1922`) straddles a band edge:
     * its lower bound is age 68 (60–69), its upper bound age 72 (70–79); the
     * `MAX(death julian-day)` choice must land it once in 70–79, never split
     * into 60–69. So the 70–79 band holds three people (I1, precise I2, I3) and
     * 60–69 stays empty.
     */
    #[Test]
    public function ageAtDeathDistributionCountsRangedLifespansOnce(): void
    {
        $tree   = $this->importFixtureTree('age-at-death-dedup.ged');
        $result = $this->repository($tree)->ageAtDeathDistribution();

        self::assertSame(3, $result['70–79'], 'I1, I2 and the band-straddling I3 each count once');
        self::assertSame(0, $result['60–69'], 'The straddler bins on its upper bound, never splitting into 60–69');
        self::assertSame(3, array_sum($result), 'Three deceased individuals, not five dates rows');
    }

    /**
     * topOldestDeceased returns Carl first (120y) then Berta (95y) then Anna
     * (75y); Franz is alive and never shows up.
     */
    #[Test]
    public function topOldestDeceasedRanksByAgeDesc(): void
    {
        $tree   = $this->importFixtureTree('life-span.ged');
        $result = $this->repository($tree)->topOldestDeceased(10);

        self::assertGreaterThanOrEqual(120, $result[0]->value);
        self::assertGreaterThanOrEqual($result[1]->value, $result[0]->value);
    }

    /**
     * topOldestLiving returns living individuals (no DEAT date) with their
     * current age. The fixture has one such — Franz (1950, still living). Other
     * rows have DEAT and must not appear.
     */
    #[Test]
    public function topOldestLivingReturnsOnlyLivingIndividuals(): void
    {
        $tree   = $this->importFixtureTree('life-span.ged');
        $result = $this->repository($tree)->topOldestLiving(10);

        // Exactly one living individual in the fixture (Franz).
        self::assertCount(1, $result);
        // Franz carries the digit-only XREF "906" (see fixture) — the
        // entry must round-trip it intact alongside the age.
        self::assertSame('906', $result[0]->xref);
        // Franz born 1950 → his current age in test-run year is
        // > 60 in any plausible environment. Use a generous floor
        // so the test stays stable as years pass.
        self::assertGreaterThan(60, $result[0]->value);
    }

    /**
     * livingByAgeBand groups Franz (1950, living) into the 65+ bucket. Every
     * other fixture individual is deceased and contributes nothing.
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
     * birthsByDecade aggregates every BIRT date across the tree into a decade
     * bucket, fills inner zero-decades so gaps stay visible, and trims leading
     * / trailing zeroes via HistogramTrim. The fixture's six dated births are
     * spread across 1700, 1850, 1880, 1900, 1920, 1950 decade starts, so the
     * visible window is 1700..1950 with one tick per active decade and zero for
     * all empty in-between decades. Decade keys are integer starts; the view
     * layer formats them via `I18N::translate('%ss', $decade)`.
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
     * birthsByDecade() payload. The life-span.ged fixture has one birth each at
     * decade starts 1700, 1850, 1880, 1900, 1920, 1950, with zero-filled inner
     * decades. The cumulative series therefore steps up monotonically: 1 at
     * 1700, holds at 1 across the long 1710..1840 gap, then 2 at 1850, 3 at
     * 1880, 4 at 1900, 5 at 1920, and 6 at 1950. Visible window and decade keys
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
     * Single-birth tree collapses to a one-entry cumulative series: one decade
     * key, one running-total of 1. Locks the lower-bound behaviour of the
     * aggregator so a future "drop singleton series" tweak fails the test
     * instead of silently regressing the minimum-data display.
     */
    #[Test]
    public function cumulativeBirthsByDecadeForSingleBirthHasOneStep(): void
    {
        $tree   = $this->importFixtureTree('empty-marriages.ged');
        $result = $this->repository($tree)->cumulativeBirthsByDecade();

        self::assertSame([1900 => 1], $result);
    }

    /**
     * life-span.ged carries five deceased individuals — Anna 75y in 19th c.,
     * Berta 95y in 20th c., Carl 120y in 18th c., Doris 3y in 20th c., Emil 53y
     * in 19th c. The 18th-century cohort is a single individual and stays below
     * MIN_COHORT_SIZE; the 19th and 20th each have two and are also below the
     * threshold. The fixture is therefore expected to drop every cohort.
     */
    #[Test]
    public function deathAgeDistributionByCenturyDropsSubThresholdCohorts(): void
    {
        $tree = $this->importFixtureTree('life-span.ged');

        self::assertSame([], $this->repository($tree)->deathAgeDistributionByCentury());
    }

    /**
     * empty-marriages.ged has one dated BIRT but no DEAT, so no BirthDeathPair
     * survives the join. Locks the empty-result short-circuit for trees without
     * computable lifespans.
     */
    #[Test]
    public function deathAgeDistributionByCenturyIsEmptyWithoutDeathDates(): void
    {
        $tree = $this->importFixtureTree('empty-marriages.ged');

        self::assertSame([], $this->repository($tree)->deathAgeDistributionByCentury());
    }

    /**
     * Sub-threshold sample (life-span.ged only carries 5 dated deaths, the
     * threshold is 12) returns null because the winter / baseline ratio derived
     * from too few samples is too noisy to publish.
     */
    #[Test]
    public function deathWinterPeakScoreReturnsNullBelowMinimumSample(): void
    {
        $tree = $this->importFixtureTree('life-span.ged');

        self::assertNull($this->repository($tree)->deathWinterPeakScore());
    }

    /**
     * Winter-peak fixture has 12 deaths with six in DEC/JAN/FEB and six spread
     * across the rest of the calendar. Score becomes (6 / 3) / (12 / 12) = 2.0
     * — the peak is exactly twice the baseline rate. Locks both the threshold
     * trip and the formula.
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
     * Evenly-distributed fixture: one death per calendar month, twelve months.
     * Winter share equals the baseline so the score lands exactly at 1.0 — the
     * neutral middle the consumer reads as "no winter peak".
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
     * Winter-trough fixture: one death in JAN, none in DEC/FEB, eleven spread
     * across the rest. Winter density falls well below the baseline so the
     * score sits under 1.0 — the widget surfaces this as "winter is
     * under-represented".
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
     * Survival curve emits one series per qualifying birth century with
     * monotonically falling values from 100 % at age 0 down to the post-100
     * floor. The fixture carries:
     * * 19th + 20th century cohorts (30 individuals each, both qualify)
     * * 18th-century cohort with 5 individuals (well below floor)
     * * 17th-century cohort with 29 individuals — exactly one short
     *   of the {@see MIN_COHORT_SIZE_SURVIVAL} floor, locking the
     *   strict-less-than boundary against off-by-one regressions.
     *
     * Categories are always the 11 age anchors regardless of which cohorts pass
     * the floor.
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
        self::assertSame(['19th cent.', '20th cent.'], $seriesNames, 'qualifying cohorts ordered chronologically');
        self::assertNotContains('17th cent.', $seriesNames, '29-INDI 17th cohort sits one below the floor and must be dropped');
        self::assertNotContains('18th cent.', $seriesNames, '5-INDI 18th cohort is far below the floor and must be dropped');

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
     * A tree with no recorded BIRT+DEAT pairs at all returns an empty payload
     * (no categories, no series) so the widget surfaces the
     * EmptyStatePlaceholder instead of an axis scaffold with zero lines.
     * empty-marriages.ged carries one BIRT without a DEAT — exactly the case
     * where every cohort stays empty.
     */
    #[Test]
    public function survivalFunctionByCenturyReturnsEmptyPayloadWithoutRecordedDeaths(): void
    {
        $tree   = $this->importFixtureTree('empty-marriages.ged');
        $result = $this->repository($tree)->survivalFunctionByCentury();

        self::assertSame([], $result->categories);
        self::assertSame([], $result->series);
    }

    /**
     * Webtrees writes TWO rows into the `dates` table for every BET..AND /
     * FROM..TO date — one for the lower bound, one for the upper. A JOIN that
     * fetches `dates AS birth` and `dates AS death` without grouping by
     * individual would therefore see the same person twice (with two different
     * JDs each side), inflating cohort counts and skewing every per- century
     * aggregation.
     *
     * The dedicated fixture `life-span-edge-cases.ged` carries:
     *
     * * I1-I30 — 30 19th-century full-date controls, all born in
     *   1850 (spread day/month) and dying exactly 50 years later;
     * * I31 — a BET..AND BIRT individual (`BET 1851 AND 1853`,
     *   full-date DEAT 15 JUN 1902) which webtrees writes as two
     *   BIRT rows;
     * * I32 — a FROM..TO DEAT individual (full-date BIRT 10 APR
     *   1855, `FROM 1903 TO 1905`) which webtrees writes as two
     *   DEAT rows.
     *
     * The cohort therefore contains 32 distinct individuals. A
     * non-deduplicating JOIN would yield 34 rows (30 controls + 2 for BET..AND
     * + 2 for FROM..TO), and 34 would surface here via `n`. The assertion locks
     * the post-aggregation count so a regression that drops the GROUP BY
     * surfaces immediately.
     */
    #[Test]
    public function deathAgeDistributionByCenturyDedupsBetweenAndFromToDoubleRows(): void
    {
        $tree   = $this->importFixtureTree('life-span-edge-cases.ged');
        $result = $this->repository($tree)->deathAgeDistributionByCentury();

        self::assertCount(1, $result, 'only the 19th-century cohort qualifies');
        self::assertSame(19, $result[0]['century']);
        self::assertSame(
            32,
            $result[0]['n'],
            '30 controls + 1 BET..AND BIRT + 1 FROM..TO DEAT = 32 unique individuals; without dedup the count climbs to 34',
        );
    }

    /**
     * `averageLifespanBySexAndCentury` runs the same BirthDeath pairs through a
     * per-sex aggregation, so the same BET..AND / FROM..TO doubling pathology
     * would inflate either the male or female cohort count. The fixture splits
     * sexes alternately across the 30 controls (15 male + 15 female) and adds
     * one male BET..AND BIRT plus one female FROM..TO DEAT, so each sex cohort
     * must land at exactly 16. The tooltip carries the cohort size in its `n =
     * …` suffix — we assert it directly to surface a regression at the per-sex
     * layer.
     */
    #[Test]
    public function averageLifespanBySexAndCenturyDedupsBetweenAndFromToDoubleRows(): void
    {
        $tree   = $this->importFixtureTree('life-span-edge-cases.ged');
        $result = $this->repository($tree)->averageLifespanBySexAndCentury();

        self::assertCount(1, $result->categories, 'only the 19th-century cohort qualifies');
        self::assertSame('19th cent.', $result->categories[0]);

        self::assertStringContainsString(
            'n = 16',
            $result->series[0]->tooltips[0],
            '15 male controls + 1 BET..AND male = 16 unique men',
        );
        self::assertStringContainsString(
            'n = 16',
            $result->series[1]->tooltips[0],
            '15 female controls + 1 FROM..TO female = 16 unique women',
        );
    }

    /**
     * `survivalFunctionByCentury` is the third cohort method that rides on
     * `aggregatedPairColumns()`; without a dedup-specific test the GROUP BY on
     * this path could silently regress while the other two assertions stay
     * green. The 19th-century cohort post-aggregation has 32 individuals (= 30
     * controls + 1 BET..AND BIRT + 1 FROM..TO DEAT) and clears the
     * `MIN_COHORT_SIZE_SURVIVAL = 30` floor, so the series is emitted. The
     * tooltip text embeds the cohort denominator (`of %s individuals reached
     * this age`) and is the only public surface that exposes the cohort size —
     * we assert `of 32 individuals` to lock the dedup on this third call site.
     */
    #[Test]
    public function survivalFunctionByCenturyDedupsBetweenAndFromToDoubleRows(): void
    {
        $tree   = $this->importFixtureTree('life-span-edge-cases.ged');
        $result = $this->repository($tree)->survivalFunctionByCentury();

        self::assertCount(1, $result->series, 'only the 19th-century cohort clears MIN_COHORT_SIZE_SURVIVAL = 30');
        self::assertSame('19th cent.', $result->series[0]->name);

        self::assertStringContainsString(
            'of 32 individuals',
            $result->series[0]->tooltips[0],
            '30 controls + 1 BET..AND + 1 FROM..TO = 32 unique individuals; without dedup the cohort climbs to 34',
        );
    }

    /**
     * The population-pyramid payload bins each BIRT+DEAT individual into its
     * birth century, its 10-year age-at-death band and its sex. The bands axis
     * is the full oldest-first range; the centuries axis lists only populated
     * cohorts in chronological order. population-pyramid.ged places two 100+
     * deaths in the 17th century (one per sex), a 70–79 pair plus a 50–59 male
     * and a 0–9 female in the 19th, and a 0–9 male plus a 90–99 female in the
     * 20th.
     */
    #[Test]
    public function deathsByCenturyAgeBandSexBinsBySexBandAndCentury(): void
    {
        $tree   = $this->importFixtureTree('population-pyramid.ged');
        $result = $this->repository($tree)->deathsByCenturyAgeBandSex();

        // Centuries axis: only populated cohorts, chronological order.
        self::assertSame(['17th cent.', '19th cent.', '20th cent.'], $result->groups);

        // Bands axis: full 11-band range, oldest first so the pyramid base
        // (youngest) sits at the bottom.
        self::assertCount(11, $result->bands);
        self::assertSame('100+', $result->bands[0]);
        self::assertSame('0–9', $result->bands[10]);

        // 17th century: one male + one female centenarian.
        self::assertSame(['left' => 1, 'right' => 1], $result->data[0][0], '17th c. 100+ band');
        self::assertSame(2, $this->columnTotal($result->data[0]), '17th c. carries exactly two deaths');

        // 19th century: 70–79 pair, 50–59 male, 0–9 female.
        self::assertSame(['left' => 1, 'right' => 1], $result->data[1][3], '19th c. 70–79 band');
        self::assertSame(['left' => 1, 'right' => 0], $result->data[1][5], '19th c. 50–59 band (male only)');
        self::assertSame(['left' => 0, 'right' => 1], $result->data[1][10], '19th c. 0–9 band (female only)');

        // 20th century: 0–9 male, 90–99 female.
        self::assertSame(['left' => 1, 'right' => 0], $result->data[2][10], '20th c. 0–9 band (male only)');
        self::assertSame(['left' => 0, 'right' => 1], $result->data[2][1], '20th c. 90–99 band (female only)');
    }

    /**
     * Individuals with an unknown / unrecorded sex and living individuals (no
     * DEAT) never enter the pyramid: only the eight M/F deceased of
     * population-pyramid.ged are counted, so the unknown-sex 19th-century death
     * and the living 20th-century male leave the totals at eight.
     */
    #[Test]
    public function deathsByCenturyAgeBandSexExcludesUnknownSexAndLiving(): void
    {
        $tree   = $this->importFixtureTree('population-pyramid.ged');
        $result = $this->repository($tree)->deathsByCenturyAgeBandSex();

        $total = 0;

        foreach ($result->data as $column) {
            $total += $this->columnTotal($column);
        }

        self::assertSame(8, $total, 'unknown-sex and living individuals are excluded');
    }

    /**
     * A tree without a single computable BIRT+DEAT pair yields empty axes so the
     * widget renders its empty state instead of an axis of zero-height bars.
     */
    #[Test]
    public function deathsByCenturyAgeBandSexReturnsEmptyAxesWithoutDeaths(): void
    {
        $tree   = $this->importFixtureTree('empty-marriages.ged');
        $result = $this->repository($tree)->deathsByCenturyAgeBandSex();

        self::assertSame([], $result->groups);
        self::assertSame([], $result->bands);
        self::assertSame([], $result->data);
    }

    /**
     * Locks the age-band cut points so a future change to the bucketing maths
     * can't silently shift a death into the wrong band. population-pyramid-bands.ged
     * places five male 20th-century deaths just past each boundary: 0.5y and
     * 9.5y both fall in 0–9, 10.5y in 10–19, 99.5y in 90–99, and 100.5y in the
     * 100+ overflow.
     */
    #[Test]
    public function deathsByCenturyAgeBandSexBucketsAtBandBoundaries(): void
    {
        $tree   = $this->importFixtureTree('population-pyramid-bands.ged');
        $result = $this->repository($tree)->deathsByCenturyAgeBandSex();

        self::assertSame(['20th cent.'], $result->groups);

        $column = $result->data[0];
        // bands are oldest-first: [100+, 90–99, 80–89, 70–79, …, 10–19, 0–9].
        self::assertSame(['left' => 1, 'right' => 0], $column[0], '100.5y → 100+ overflow');
        self::assertSame(['left' => 1, 'right' => 0], $column[1], '99.5y → 90–99');
        self::assertSame(['left' => 1, 'right' => 0], $column[9], '10.5y → 10–19');
        self::assertSame(['left' => 2, 'right' => 0], $column[10], '0.5y and 9.5y → 0–9');

        // Bands 80–89 … 20–29 stay empty.
        foreach ([2, 3, 4, 5, 6, 7, 8] as $emptyBand) {
            self::assertSame(['left' => 0, 'right' => 0], $column[$emptyBand]);
        }
    }

    /**
     * eventHeatmapByPeriodMonth('BIRT') bins every dated birth into its 25-year
     * period row and calendar-month column. The fixture's births land in the
     * 1900 period (two in January 1901, one in July 1905) and the 1925 period
     * (two in December, 1925 and 1928); the column axis is the twelve month
     * names abbreviated to three characters, January first.
     */
    #[Test]
    public function eventHeatmapByPeriodMonthBinsBirthsByPeriodAndMonth(): void
    {
        $tree   = $this->importFixtureTree('event-heatmap.ged');
        $result = $this->repository($tree)->eventHeatmapByPeriodMonth('BIRT');

        self::assertSame(['1900', '1925'], $result->rows);

        // Twelve abbreviated month columns, January first and December last.
        self::assertCount(12, $result->cols);
        self::assertSame('Jan', $result->cols[0]);
        self::assertSame('Dec', $result->cols[11]);

        // The full month names ride alongside for the tooltip.
        self::assertCount(12, $result->colTitles);
        self::assertSame('January', $result->colTitles[0]);
        self::assertSame('December', $result->colTitles[11]);

        // 1900 period: January (index 0) carries two births, July (index 6) one.
        self::assertSame(2, $result->values[0][0], '1900 period January holds I1 + I2');
        self::assertSame(1, $result->values[0][6], '1900 period July holds I3');

        // 1925 period: December (index 11) carries two births.
        self::assertSame(2, $result->values[1][11], '1925 period December holds I4 + I5');

        // Five dated births land in the matrix; the year-only birth is excluded.
        self::assertSame(5, $this->matrixTotal($result->values));
    }

    /**
     * A 25-year period with no recorded event between two that do carry one
     * survives as an all-zero row, so a gap in the records reads as a blank band
     * rather than collapsing the axis. The future fixture's births fall in the
     * 1900 and 1950 periods, leaving the 1925 period empty between them.
     */
    #[Test]
    public function eventHeatmapByPeriodMonthKeepsInnerGapAsZeroRow(): void
    {
        $tree   = $this->importFixtureTree('event-heatmap-future.ged');
        $result = $this->repository($tree)->eventHeatmapByPeriodMonth('BIRT');

        self::assertSame('1925', $result->rows[1]);
        self::assertSame(array_fill(0, 12, 0), $result->values[1], '1925 period is an all-zero band');
    }

    /**
     * A date carrying only a year (no month) is dropped from the matrix: it
     * cannot be placed in a month column. The fixture's I6 has a year-only birth
     * (1922) and a year-only death (1975), so neither the births nor the deaths
     * heatmap counts it — the births matrix totals five, not six.
     */
    #[Test]
    public function eventHeatmapByPeriodMonthExcludesYearOnlyDates(): void
    {
        $tree       = $this->importFixtureTree('event-heatmap.ged');
        $repository = $this->repository($tree);

        // The year-only 1922 birth falls in the 1900 period; excluded, that row
        // carries only the three dated births (two January, one July).
        $births = $repository->eventHeatmapByPeriodMonth('BIRT');
        self::assertSame(3, array_sum($births->values[0]), '1900 period holds only the three dated births');

        // The year-only 1975 death would have seeded the 1975 period; instead the
        // deaths matrix totals four dated events.
        $deaths = $repository->eventHeatmapByPeriodMonth('DEAT');
        self::assertSame(4, $this->matrixTotal($deaths->values), 'four dated deaths, year-only excluded');
    }

    /**
     * A BCE (negative-year) date is excluded entirely: it must not seed a
     * negative period row that would balloon the dense period-fill into hundreds
     * of empty rows reaching back to antiquity. The fixture's I7 has a BCE birth
     * (1 MAR 50 B.C.); the births matrix still starts at the 1900 period and
     * totals five.
     */
    #[Test]
    public function eventHeatmapByPeriodMonthExcludesBceDates(): void
    {
        $tree   = $this->importFixtureTree('event-heatmap.ged');
        $result = $this->repository($tree)->eventHeatmapByPeriodMonth('BIRT');

        // No pre-CE / antiquity rows — the first row is still the 1900 period.
        self::assertSame('1900', $result->rows[0]);
        self::assertSame(['1900', '1925'], $result->rows);
        self::assertSame(5, $this->matrixTotal($result->values), 'the BCE birth is not counted');
    }

    /**
     * A future-dated year is excluded: webtrees stores both bounds of a
     * `BET … AND …` range as separate `dates` rows, so a `BET MAR 1900 AND MAR
     * 9999` birth would otherwise seed the dense period-fill out past the year
     * 9000 and balloon the matrix into hundreds of empty rows. The fixture's I1
     * has exactly that range; the in-range lower bound (1900 period) is kept
     * while the far-future upper bound is dropped, so the matrix ends at the
     * realistic 1950 period (I2) rather than reaching into the future.
     */
    #[Test]
    public function eventHeatmapByPeriodMonthExcludesFarFutureRangeBound(): void
    {
        $tree   = $this->importFixtureTree('event-heatmap-future.ged');
        $result = $this->repository($tree)->eventHeatmapByPeriodMonth('BIRT');

        // The matrix stays bounded: the 1900 through 1950 periods, not the 9000s.
        self::assertSame('1900', $result->rows[0]);
        self::assertSame('1950', $result->rows[count($result->rows) - 1]);
        self::assertSame(range(1900, 1950, 25), array_map(
            static fn (string $label): int => (int) $label,
            $result->rows,
        ));

        // Only the in-range lower bound (1900 March) and the 1950 anchor count;
        // the far-future upper bound is excluded.
        self::assertSame(2, $this->matrixTotal($result->values));
    }

    /**
     * A range date whose two bounds fall in different period rows AND different
     * month columns counts the individual once in its lower-bound cell, never
     * split across both. I1 (`BET DEC 1899 AND JAN 1900`) lands once in the 1875
     * period's December column (lower bound Dec 1899); a raw per-row count would
     * also seed the 1900 period's January column from the upper-bound row. I2
     * (precise `15 MAR 1900`) anchors the 1900 period. So the matrix totals two,
     * the 1900-period January cell stays empty, and no record is double-counted.
     */
    #[Test]
    public function eventHeatmapByPeriodMonthCountsCrossPeriodRangeOnce(): void
    {
        $tree   = $this->importFixtureTree('heatmap-period-month-dedup.ged');
        $result = $this->repository($tree)->eventHeatmapByPeriodMonth('BIRT');

        self::assertSame(['1875', '1900'], $result->rows);
        self::assertSame(1, $result->values[0][11], 'I1 lands once in the 1875 period December');
        self::assertSame(1, $result->values[1][2], 'I2 anchors the 1900 period March');
        self::assertSame(0, $result->values[1][0], 'No January leak from I1 upper bound into the 1900 period');
        self::assertSame(2, $this->matrixTotal($result->values), 'Two individuals, not three dates rows');
    }

    /**
     * eventHeatmapByPeriodMonth('DEAT') bins dated deaths the same way over the
     * DEAT axis. The fixture's deaths fall in the 1950 period (two in March,
     * 1970 and 1972) and the 1975 period (one December 1980, one January 1990).
     */
    #[Test]
    public function eventHeatmapByPeriodMonthBinsDeathsByPeriodAndMonth(): void
    {
        $tree   = $this->importFixtureTree('event-heatmap.ged');
        $result = $this->repository($tree)->eventHeatmapByPeriodMonth('DEAT');

        self::assertSame(['1950', '1975'], $result->rows);

        self::assertSame(2, $result->values[0][2], '1950 period March holds I1 + I2');
        self::assertSame(1, $result->values[1][11], '1975 period December holds I3');
        self::assertSame(1, $result->values[1][0], '1975 period January holds I4');

        self::assertSame(4, $this->matrixTotal($result->values));
    }

    /**
     * A tree without a single dated event of the requested fact returns empty
     * axes so the partial can render its placeholder instead of an empty grid.
     * The fixture records no deaths, so the DEAT heatmap collapses to empty.
     */
    #[Test]
    public function eventHeatmapByPeriodMonthReturnsEmptyAxesWithoutEvents(): void
    {
        $tree   = $this->importFixtureTree('empty-marriages.ged');
        $result = $this->repository($tree)->eventHeatmapByPeriodMonth('DEAT');

        self::assertSame([], $result->rows);
        self::assertSame([], $result->cols);
        self::assertSame([], $result->colTitles);
        self::assertSame([], $result->values);
    }

    /**
     * Sum every count across a period × month value matrix.
     *
     * @param list<list<int>> $values
     */
    private function matrixTotal(array $values): int
    {
        $total = 0;

        foreach ($values as $row) {
            $total += array_sum($row);
        }

        return $total;
    }

    /**
     * Sum the left + right counts across every band of one group column.
     *
     * @param list<array{left: int, right: int}> $column
     */
    private function columnTotal(array $column): int
    {
        $total = 0;

        foreach ($column as $cell) {
            $total += $cell['left'] + $cell['right'];
        }

        return $total;
    }
}
