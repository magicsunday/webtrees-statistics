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
use MagicSunday\Webtrees\Statistic\Repository\NameRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration test for {@see NameRepository}. Uses the existing
 * `name-trends.ged` fixture: twelve individuals total (eleven dated + one
 * undated), five distinct given names and one common surname "Test" that
 * appears on every individual.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class NameRepositoryIntegrationTest extends IntegrationTestCase
{
    private function repository(Tree $tree): NameRepository
    {
        return new NameRepository($tree, $this->statisticsData($tree));
    }

    /**
     * Every individual carries the surname "Test" so the distinct- surname
     * count is 1. Tests the "headline number stays in lockstep with the Top-N
     * aggregation" promise.
     */
    #[Test]
    public function countDistinctSurnamesIs1ForFixture(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = $this->repository($tree)->countDistinctSurnames();

        self::assertSame(1, $result);
    }

    /**
     * Exercises the threshold > 1 branch separately so the GROUP BY + HAVING
     * path also gets test coverage. The single surname "Test" appears on all
     * twelve individuals, so the cardinality cliff sits at the population size:
     * threshold ≤ 12 returns 1, threshold ≥ 13 returns 0. Pinning both sides of
     * the boundary catches an off-by-one regression in the HAVING comparator (≥
     * vs >). The branch split was introduced to keep the query valid under
     * MySQL's `ONLY_FULL_GROUP_BY` mode.
     */
    #[Test]
    public function countDistinctSurnamesWithThresholdAboveOne(): void
    {
        $tree = $this->importFixtureTree('name-trends.ged');
        $repo = $this->repository($tree);

        self::assertSame(1, $repo->countDistinctSurnames(2));
        self::assertSame(1, $repo->countDistinctSurnames(12));
        self::assertSame(0, $repo->countDistinctSurnames(13));
    }

    /**
     * Five distinct given names appear in the fixture (Anna, Friedrich, Maria,
     * Hans, Lisa), split across sexes. Female: Anna×3, Maria×2, Lisa×1 → 3
     * distinct. Male: Friedrich×2, Hans×3 → 2 distinct. The 12th individual is
     * undated but still has a given name so still contributes.
     */
    #[Test]
    public function countDistinctGivenNamesPerSex(): void
    {
        $tree = $this->importFixtureTree('name-trends.ged');
        $repo = $this->repository($tree);

        self::assertGreaterThanOrEqual(3, $repo->countDistinctGivenNames('F'));
        self::assertGreaterThanOrEqual(2, $repo->countDistinctGivenNames('M'));
    }

    /**
     * The threshold filter excludes given names that occur fewer times than the
     * threshold. Asking for names that appear at least 3 times across the
     * fixture should drop the count.
     */
    #[Test]
    public function countDistinctGivenNamesRespectsThreshold(): void
    {
        $tree = $this->importFixtureTree('name-trends.ged');
        $repo = $this->repository($tree);

        // With threshold 1 we see all five given names; with
        // threshold 3 only Anna (3) and Hans (3) qualify.
        $unbounded = $repo->countDistinctGivenNames('ALL', 1);
        $threeOnly = $repo->countDistinctGivenNames('ALL', 3);

        self::assertGreaterThan($threeOnly, $unbounded);
    }

    /**
     * Father → son name-passdown fixture carries three cohorts: 1700s with
     * three pairs (below MIN_COHORT_SIZE=10, suppressed), 1800s with ten pairs
     * and three matches (30 %% rate), 1900s with ten pairs and five matches (50
     * %% rate). Every father is named "Johann"; sons either repeat the father's
     * name or carry a distinct "Different{n}" name.
     *
     * Locks the per-century rate computation, the cohort-floor suppression
     * policy (the 1700s century still takes an X-axis slot but its value drops
     * to zero with a "no data" tooltip), and the token comparison.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyComputesFatherSonRateAcrossCenturies(): void
    {
        $tree   = $this->importFixtureTree('father-son-name-passdown.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        self::assertSame(['18th cent.', '19th cent.', '20th cent.'], $result->categories, 'All three centuries appear chronologically');
        self::assertCount(2, $result->series, 'Two series: father → son and mother → daughter');

        $fatherSon = $result->series[0];
        self::assertSame('Father → son', $fatherSon->name);

        // 18th century: 3 pairs, sub-threshold → suppressed to 0.
        self::assertSame(0, $fatherSon->values[0], '1700s falls below MIN_COHORT_SIZE and reads zero');

        // 19th century: 10 pairs, 3 matches → 30 %.
        self::assertEqualsWithDelta(30.0, $fatherSon->values[1], 0.05, '1800s sits at 30 % match rate');

        // 20th century: 10 pairs, 5 matches → 50 %.
        self::assertEqualsWithDelta(50.0, $fatherSon->values[2], 0.05, '1900s sits at 50 % match rate');

        // Sub-threshold tooltip carries the "no data" caption so the
        // hover explains why the line dips to zero without leaving a
        // misleading 0 % suggestion.
        self::assertStringContainsString('no data', $fatherSon->tooltips[0]);

        // The fixture has no daughters at all so the mother → daughter
        // series is suppressed across every century.
        $motherDaughter = $result->series[1];
        self::assertSame('Mother → daughter', $motherDaughter->name);
        self::assertSame([0, 0, 0], $motherDaughter->values);
    }

    /**
     * The existing `name-trends.ged` fixture carries no FAMC links, so the
     * per-century passdown query yields zero parent-child pairs of either sex
     * pairing and the method short-circuits to an empty payload.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyIsEmptyWithoutParentChildLinks(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        self::assertSame([], $result->categories);
        self::assertSame([], $result->series);
    }

    /**
     * Multi-token match: a father named "Johann Friedrich" matches a son named
     * "Wilhelm Friedrich" because "Friedrich" appears in both names. Pins the
     * set-intersection semantics so a strict first-token regression would fail
     * this test.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyMatchesAnyOverlappingToken(): void
    {
        $tree   = $this->importFixtureTree('father-son-name-passdown-multi-token.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        // Fixture: 10 father-son pairs in the 1800s, 7 share at
        // least one token, no mother-daughter pairs.
        self::assertSame(['19th cent.'], $result->categories);
        self::assertCount(2, $result->series);

        $fatherSon = $result->series[0];
        self::assertSame('Father → son', $fatherSon->name);
        self::assertEqualsWithDelta(70.0, $fatherSon->values[0], 0.05);

        // Mother → daughter has zero pairs in this fixture.
        $motherDaughter = $result->series[1];
        self::assertSame(0, $motherDaughter->values[0]);
        self::assertStringContainsString('no data', $motherDaughter->tooltips[0]);
    }
}
