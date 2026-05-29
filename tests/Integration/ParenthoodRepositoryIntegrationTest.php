<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\ParenthoodRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_sum;
use function count;

/**
 * End-to-end test of {@see ParenthoodRepository} against two fixtures:
 *
 * `age-at-first-child.ged` (histogram + sub-threshold sanity):
 *   F1: Anton (BIRT 1880) + Berta (BIRT 1885) → Carl (BIRT 1903)
 *       - father age 23 → bucket 20–24
 *       - mother age 18 → bucket 15–19
 *   F2: Emil (BIRT 1860) + Frieda (BIRT 1870) → Greta (BIRT 1907)
 *       - father age 47 → bucket 45–49
 *       - mother age 37 → bucket 35–39
 *   F3: Hans + Ilse, no children → excluded from both distributions
 *
 * `age-at-first-child-by-decade.ged` (per-decade aggregate happy path):
 *   - 5 families with children born 1900–1905 (cohort meets the floor)
 *   - 5 families with children born 1911–1915 (cohort meets the floor)
 *   - 2 families with children born 1923–1925 (cohort dropped)
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ParenthoodRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Fathers' distribution picks up Anton (23 years → 20–24 bucket) and Emil
     * (47 years → 45–49 bucket). The childless family contributes nothing.
     */
    #[Test]
    public function fathersDistributionMatchesFixture(): void
    {
        $tree   = $this->importFixtureTree('age-at-first-child.ged');
        $result = (new ParenthoodRepository($tree))->ageAtFirstChildDistribution('M');

        self::assertSame(1, $result['20–24']);
        self::assertSame(1, $result['45–49']);
        self::assertSame(0, $result['15–19'] ?? 0, 'No father in the 15–19 bucket');
        self::assertSame(0, $result['35–39'] ?? 0, 'No father in the 35–39 bucket');
    }

    /**
     * Mothers' distribution picks up Berta (18 years → 15–19 bucket) and Frieda
     * (37 years → 35–39 bucket). The childless family contributes nothing.
     */
    #[Test]
    public function mothersDistributionMatchesFixture(): void
    {
        $tree   = $this->importFixtureTree('age-at-first-child.ged');
        $result = (new ParenthoodRepository($tree))->ageAtFirstChildDistribution('F');

        self::assertSame(1, $result['15–19']);
        self::assertSame(1, $result['35–39']);
        self::assertSame(0, $result['20–24'] ?? 0, 'No mother in the 20–24 bucket');
        self::assertSame(0, $result['45–49'] ?? 0, 'No mother in the 45–49 bucket');
    }

    /**
     * The family with no children must NOT contribute to either sex's
     * distribution — there is no age to compute without a dated child. Total
     * count across all buckets sums to exactly the families that had a dated
     * child.
     */
    #[Test]
    public function childlessFamiliesAreExcluded(): void
    {
        $tree    = $this->importFixtureTree('age-at-first-child.ged');
        $fathers = (new ParenthoodRepository($tree))->ageAtFirstChildDistribution('M');
        $mothers = (new ParenthoodRepository($tree))->ageAtFirstChildDistribution('F');

        self::assertSame(2, array_sum($fathers), 'Two fathers contributed; the childless father is excluded');
        self::assertSame(2, array_sum($mothers), 'Two mothers contributed; the childless mother is excluded');
    }

    /**
     * The 2-family age-at-first-child fixture is below the per- decade cohort
     * floor (5) on every cohort, so the mean-by- decade aggregate must
     * short-circuit to an empty payload — no categories, no series. Locks the
     * sub-threshold drop so single-couple trees don't render a statistically
     * meaningless 1-point trend line.
     */
    #[Test]
    public function meanByDecadeIsEmptyWhenEveryCohortIsBelowThreshold(): void
    {
        $tree   = $this->importFixtureTree('age-at-first-child.ged');
        $result = (new ParenthoodRepository($tree))->ageAtFirstChildMeanByDecade();

        self::assertSame([], $result->categories);
        self::assertSame([], $result->series);
    }

    /**
     * The by-decade fixture carries five qualifying families in the 1900s, five
     * in the 1910s, and two sub-threshold families in the 1920s. The 1920s
     * decade must therefore be dropped from `categories` (both sexes below the
     * cohort floor of 5), leaving exactly two decades on the X-axis, ordered
     * chronologically by decade-start year (1900s then 1910s). Both series stay
     * in lockstep so the chart-lib LineChart can render them on a shared X
     * axis. The series names are asserted literally so a future label refactor
     * that swaps the order trips this test instead of the silent assertion
     * downstream.
     */
    #[Test]
    public function meanByDecadeKeepsOnlyDecadesThatMeetTheCohortFloor(): void
    {
        $tree   = $this->importFixtureTree('age-at-first-child-by-decade.ged');
        $result = (new ParenthoodRepository($tree))->ageAtFirstChildMeanByDecade();

        self::assertSame(['1900s', '1910s'], $result->categories, '1900s precedes 1910s, 1920s dropped');
        self::assertCount(2, $result->series, 'Exactly one series per parent sex');

        $fathers = $result->series[0];
        $mothers = $result->series[1];

        self::assertSame('Fathers', $fathers->name, 'First series is the fathers line');
        self::assertSame('Mothers', $mothers->name, 'Second series is the mothers line');

        self::assertCount(count($result->categories), $fathers->values, 'Fathers series has one value per category');
        self::assertCount(count($result->categories), $mothers->values, 'Mothers series has one value per category');
        self::assertCount(count($result->categories), $fathers->tooltips, 'Fathers tooltips parallel the values');
        self::assertCount(count($result->categories), $mothers->tooltips, 'Mothers tooltips parallel the values');

        // Lock the happy-path tooltip body so a regression to the empty
        // string or the wrong msgid surfaces here instead of silently
        // shipping a blank hover.
        self::assertStringContainsString('years', $fathers->tooltips[0]);
        self::assertStringContainsString('n = 5', $fathers->tooltips[0]);
    }

    /**
     * Children-by-birth-decade bucketing: the fixture's first cohort (children
     * born 1900–1905) anchors families whose fathers were born in the
     * 1870s/1880s and mothers in the 1880s. The parental-decade is irrelevant.
     * The 1900s bucket therefore holds the five 1900-birth-year families and
     * lands the fathers' mean at 26.6 years ((23+25+24+29+32)/5) and the
     * mothers' at 20.8 ((21+20+21+22+20)/5). The 1910s bucket is pinned too so
     * a cursor-tracking bug between decades cannot pass with only the first
     * index green. Locks the "decade = child's BIRT" contract the issue
     * specifies and guards against an accidental "decade = parent's BIRT"
     * regression.
     */
    #[Test]
    public function meanByDecadeBucketsByChildBirthYearNotParentBirthYear(): void
    {
        $tree   = $this->importFixtureTree('age-at-first-child-by-decade.ged');
        $result = (new ParenthoodRepository($tree))->ageAtFirstChildMeanByDecade();

        // Re-assert series order so this test is self-contained — the
        // sibling test could be deleted without silently rebinding
        // values[0] / values[1] to the wrong sex.
        $fathers = $result->series[0];
        $mothers = $result->series[1];
        self::assertSame('Fathers', $fathers->name);
        self::assertSame('Mothers', $mothers->name);

        // Index 0 = 1900s (child-birth decade).
        self::assertEqualsWithDelta(26.6, $fathers->values[0], 0.05, 'Fathers mean for the 1900s cohort');
        self::assertEqualsWithDelta(20.8, $mothers->values[0], 0.05, 'Mothers mean for the 1900s cohort');

        // Index 1 = 1910s. F6-F10: fathers 1880/1882/1881/1885/1883
        // → children 1915/1912/1913/1911/1914, ages 35/30/32/26/31 →
        // mean 30.8. Mothers 1890/1885/1888/1890/1889 → ages
        // 25/27/25/21/25 → mean 24.6.
        self::assertEqualsWithDelta(30.8, $fathers->values[1], 0.05, 'Fathers mean for the 1910s cohort');
        self::assertEqualsWithDelta(24.6, $mothers->values[1], 0.05, 'Mothers mean for the 1910s cohort');
    }

    /**
     * Cache-sharing safety check: the same per-instance pair cache now serves
     * both the bucket-histogram consumer ({@see
     * ParenthoodRepository::ageAtFirstChildDistribution()}) and the per-decade
     * aggregate ({@see ParenthoodRepository::ageAtFirstChildMeanByDecade()}).
     * Call them in sequence on the same repository instance and verify the
     * second consumer sees the full payload — a regression where the cached row
     * shape diverged or the cache key collided across consumers would surface
     * here.
     */
    #[Test]
    public function meanByDecadeReadsCorrectlyAfterDistributionPopulatesTheCache(): void
    {
        $tree = $this->importFixtureTree('age-at-first-child-by-decade.ged');
        $repo = new ParenthoodRepository($tree);

        // Warm the pair cache via the histogram consumer first.
        $fathersHistogram = $repo->ageAtFirstChildDistribution('M');
        $mothersHistogram = $repo->ageAtFirstChildDistribution('F');
        self::assertSame(12, array_sum($fathersHistogram), 'All 12 fathers contribute to the histogram');
        self::assertSame(12, array_sum($mothersHistogram), 'All 12 mothers contribute to the histogram');

        // Same instance, second consumer — must see the by-decade payload intact.
        $result = $repo->ageAtFirstChildMeanByDecade();
        self::assertSame(['1900s', '1910s'], $result->categories, 'Cache hit still yields the full per-decade payload');
    }

    /**
     * Per-sex-independent cohort drop: the asymmetric fixture carries five
     * families in the 1930s where both spouses have a recorded birth date and
     * five families in the 1940s where only the husband has a birth date (the
     * wife row is filtered upstream by the BIRT-julian-day predicate). The
     * 1940s decade must therefore survive the cohort floor — the fathers cohort
     * sits at 5 — while the mothers cohort sits at 0 and gets suppressed with
     * the "no data" tooltip plus a zero value on the mothers line. Locks the
     * contract that one sex below the floor does NOT drag the whole decade with
     * it.
     */
    #[Test]
    public function meanByDecadeSuppressesOnlySexBelowCohortFloor(): void
    {
        $tree   = $this->importFixtureTree('age-at-first-child-asymmetric.ged');
        $result = (new ParenthoodRepository($tree))->ageAtFirstChildMeanByDecade();

        self::assertSame(['1930s', '1940s'], $result->categories, 'Both decades survive — neither is below the floor for BOTH sexes');

        $fathers = $result->series[0];
        $mothers = $result->series[1];
        self::assertSame('Fathers', $fathers->name);
        self::assertSame('Mothers', $mothers->name);

        // 1930s: symmetric cohort, both means real.
        self::assertGreaterThan(0, $fathers->values[0], 'Fathers line carries a real 1930s mean');
        self::assertGreaterThan(0, $mothers->values[0], 'Mothers line carries a real 1930s mean');

        // 1940s: asymmetric — fathers pass the floor (5 with dated BIRT),
        // mothers fail (none have a recorded birth date).
        self::assertGreaterThan(0, $fathers->values[1], 'Fathers line still renders a 1940s mean');
        self::assertSame(0, $mothers->values[1], 'Mothers line is suppressed to a zero value on the 1940s tick');
        self::assertStringContainsString('no data', $mothers->tooltips[1], 'Mothers 1940s tooltip carries the suppression caption');
    }
}
