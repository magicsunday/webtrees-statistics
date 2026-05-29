<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\TreeHealthRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end test of the Tree Health repository against a curated fixture that
 * exercises every documented data-quality scenario: fully sourced individuals,
 * half-sourced ones, individuals missing BIRT or DEAT, individuals with no
 * events at all, plus a parent-child chain with parseable birth years on both
 * ends.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class TreeHealthRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Two of six individuals carry a SOUR citation; the headline ratio must
     * report 2 / 6.
     */
    #[Test]
    public function sourceCitationCoverageReturnsTheExpectedRatio(): void
    {
        $tree     = $this->importFixtureTree('tree-health.ged');
        $coverage = (new TreeHealthRepository($tree))->sourceCitationCoverage();

        self::assertSame(2, $coverage->value);
        self::assertSame(6, $coverage->total);
    }

    /**
     * The fixture seeds known missing-event counts: BIRT missing on 2 of 6
     * individuals (the no-events-stub and the sparse child); BIRT place missing
     * additionally on the half-data parent (5 of 6); DEAT missing on 4 of 6
     * (only the sourced parent and the documented child have a death event);
     * DEAT place missing on the same 4.
     */
    #[Test]
    public function missingEventGapsReturnTheExpectedCounts(): void
    {
        $tree = $this->importFixtureTree('tree-health.ged');
        $gaps = (new TreeHealthRepository($tree))->missingEventGaps();

        self::assertSame(2, $gaps['BIRT_event']['value']);
        self::assertSame(6, $gaps['BIRT_event']['total']);

        self::assertSame(3, $gaps['BIRT_place']['value']);

        self::assertSame(4, $gaps['DEAT_event']['value']);
        self::assertSame(4, $gaps['DEAT_place']['value']);
    }

    /**
     * The fixture has two usable parent-child pairs (I1 1900 → I3 1928, delta
     * 28; I2 1902 → I3 1928, delta 26); the average is 27.
     */
    #[Test]
    public function averageGenerationLengthMatchesTheFixture(): void
    {
        $tree    = $this->importFixtureTree('tree-health.ged');
        $average = (new TreeHealthRepository($tree))->averageGenerationLength();

        self::assertNotNull($average);
        self::assertEqualsWithDelta(27.0, $average, 0.001);
    }

    /**
     * source-coverage-by-century.ged seeds 12 individuals:
     *  * five 19th-century births, three of them with `2 SOUR @S1@`
     *  * five 20th-century births, two of them with `2 SOUR @S1@`
     *  * one 18th-century birth, unsourced
     *  * one with no BIRT at all
     *
     * The minimum-sample threshold is 5. Two cohorts clear it; the 18th-century
     * cohort (n=1) is dropped. Locks both the threshold filter and the `ksort`
     * ordering so a regression that returned the surviving cohorts in insertion
     * order rather than ascending century would fail at `$result[0]['century']
     * === 19`.
     */
    #[Test]
    public function sourceCitationCoverageByCenturyKeepsCohortsAboveThreshold(): void
    {
        $tree   = $this->importFixtureTree('source-coverage-by-century.ged');
        $result = (new TreeHealthRepository($tree))->sourceCitationCoverageByCentury();

        self::assertCount(2, $result, 'Both 19th- and 20th-century cohorts meet the minimum sample size; 18th-century is dropped');

        self::assertSame(19, $result[0]['century']);
        self::assertSame(5, $result[0]['total']);
        self::assertSame(3, $result[0]['sourced']);
        self::assertEqualsWithDelta(60.0, $result[0]['percentage'], 0.001);

        self::assertSame(20, $result[1]['century']);
        self::assertSame(5, $result[1]['total']);
        self::assertSame(2, $result[1]['sourced']);
        self::assertEqualsWithDelta(40.0, $result[1]['percentage'], 0.001);
    }

    /**
     * The tree-health.ged fixture has only four dated births, all in the 20th
     * century — below the per-century minimum sample, so the breakdown drops
     * the cohort and returns an empty list. Locks the threshold-driven empty
     * path against accidental loosening.
     */
    #[Test]
    public function sourceCitationCoverageByCenturyIsEmptyWhenAllCohortsAreSubThreshold(): void
    {
        $tree = $this->importFixtureTree('tree-health.ged');

        self::assertSame([], (new TreeHealthRepository($tree))->sourceCitationCoverageByCentury());
    }
}
