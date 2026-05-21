<?php

declare(strict_types=1);

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Repository\LifeSpanRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_keys;
use function array_sum;
use function array_values;
use function count;

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
            new StatisticsData($tree, new UserService()),
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
        self::assertSame(11, count($result));
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
     * across 1700s, 1850s, 1880s, 1900s, 1920s, 1950s, so the
     * visible window is 1700s..1950s with one tick per active
     * decade and zero for all empty in-between decades.
     */
    #[Test]
    public function birthsByDecadeFillsInnerGapsAndTrimsBoundaries(): void
    {
        $tree   = $this->importFixtureTree('life-span.ged');
        $result = $this->repository($tree)->birthsByDecade();

        // First and last keys frame the visible range.
        $keys = array_keys($result);
        self::assertSame('1700s', $keys[0]);
        self::assertSame('1950s', $keys[count($keys) - 1]);

        // Every active decade carries exactly one birth.
        self::assertSame(1, $result['1700s']);
        self::assertSame(1, $result['1850s']);
        self::assertSame(1, $result['1880s']);
        self::assertSame(1, $result['1900s']);
        self::assertSame(1, $result['1920s']);
        self::assertSame(1, $result['1950s']);

        // Inner empty decade is rendered as a 0 bucket, not dropped.
        self::assertSame(0, $result['1710s']);
        self::assertSame(0, $result['1870s']);
        self::assertSame(0, $result['1930s']);

        // Total entries = 26 (1700s through 1950s in 10-year steps).
        self::assertCount(26, $result);
    }
}
