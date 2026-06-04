<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Model\Metric\RateCount;
use MagicSunday\Webtrees\Statistic\Repository\TreeHealthRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

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
#[CoversClass(TreeHealthRepository::class)]
#[UsesClass(RateCount::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(GedcomScanner::class)]
#[UsesClass(RowCast::class)]
#[UsesClass(CenturyName::class)]
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
     * The generation-length scan reads parent / child BIRT years through
     * GedcomScanner, which now negates B.C. years, so a BCE lineage is counted
     * instead of silently dropped (its short 2-digit years used to fail the
     * old four-digit-only regex). parenthood-bce.ged seeds five fathers born
     * ~75 B.C. each with a child 25 years younger (in the 50s B.C.), so every
     * delta is a clean 25 and the average comes back 25.0 rather than null.
     */
    #[Test]
    public function averageGenerationLengthCountsBceLineages(): void
    {
        $tree    = $this->importFixtureTree('parenthood-bce.ged');
        $average = (new TreeHealthRepository($tree))->averageGenerationLength();

        self::assertNotNull($average);
        self::assertEqualsWithDelta(25.0, $average, 0.001);
    }

    /**
     * source-coverage-by-century.ged seeds 12 individuals:
     *  * five 20th-century births (the LOW xrefs I1–I5), two with `2 SOUR @S1@`
     *  * five 19th-century births (the HIGH xrefs I6–I10), three with `2 SOUR @S1@`
     *  * one 18th-century birth, unsourced
     *  * one with no BIRT at all
     *
     * The 20th-century cohort deliberately sits on the lower XREFs so it is
     * encountered FIRST as the query walks `d_gid` order — the insertion order
     * is therefore [20, 19], the opposite of the ascending-century result. Only
     * a live `ksort` flips it back to [19, 20]; a regression that returned the
     * surviving cohorts in insertion order would fail at
     * `$result[0]['century'] === 19`. The minimum-sample threshold is 5: two
     * cohorts clear it, the 18th-century cohort (n=1) is dropped.
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

    /**
     * source-coverage-bce.ged seeds five Julian B.C. births in the 1st century
     * BCE (90, 70, 50, 30, 10 B.C.), two carrying `2 SOUR @S1@`. The fold must
     * land them in century -1 — `CenturyName::fromYear()` floors BCE years
     * toward negative infinity — so the breakdown surfaces a single cohort that
     * the per-century minimum sample (5) clears. A regression that truncates
     * toward zero, or one that re-introduces the `d_year > 0` exclusion, would
     * either misbucket the cohort into the CE ordinals or drop it entirely.
     */
    #[Test]
    public function sourceCitationCoverageByCenturyBucketsBceBirthsIntoNegativeCenturies(): void
    {
        $tree   = $this->importFixtureTree('source-coverage-bce.ged');
        $result = (new TreeHealthRepository($tree))->sourceCitationCoverageByCentury();

        self::assertCount(1, $result, 'Only the 1st-century-BCE cohort (n=5) clears the minimum sample; no CE cohorts exist');

        self::assertSame(-1, $result[0]['century']);
        self::assertSame(5, $result[0]['total']);
        self::assertSame(2, $result[0]['sourced']);
        self::assertEqualsWithDelta(40.0, $result[0]['percentage'], 0.001);
    }
}
