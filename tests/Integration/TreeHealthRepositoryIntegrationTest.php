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

use function date;
use function implode;
use function strval;

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
final class TreeHealthRepositoryIntegrationTest extends AbstractIntegrationTestCase
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
     * The fixture seeds known missing-event counts, and the assertions pin the
     * values. BIRT is missing on the no-events stub and the sparse child. The
     * BIRT place is missing additionally on the half-data parent. The death rows
     * are pinned by a fixture with dates relative to today, because which
     * individual is deceased depends on the current date.
     */
    #[Test]
    public function missingEventGapsReturnTheExpectedCounts(): void
    {
        $tree = $this->importFixtureTree('tree-health.ged');
        $gaps = (new TreeHealthRepository($tree))->missingEventGaps();

        $birthDateGap = $gaps['BIRT_date'] ?? self::fail('Missing BIRT_date gap row');
        self::assertSame(2, $birthDateGap['value']);
        self::assertSame(6, $birthDateGap['total']);

        $birtPlace = $gaps['BIRT_place'] ?? self::fail('Missing BIRT_place gap row');
        self::assertSame(3, $birtPlace['value']);

        // The spouses of the family have a marriage event without a place.
        $marriageDateGap = $gaps['MARR_date'] ?? self::fail('Missing MARR_date gap row');
        self::assertSame(0, $marriageDateGap['value']);
        self::assertSame(2, $marriageDateGap['total']);

        $marrPlace = $gaps['MARR_place'] ?? self::fail('Missing MARR_place gap row');
        self::assertSame(2, $marrPlace['value']);
        self::assertSame(2, $marrPlace['total']);
    }

    /**
     * A family with a dated and placed marriage, a family with a dated marriage
     * and no place, and a family without a marriage event. The spouses of the
     * last family miss the event, the spouses of the middle one miss the place,
     * and a spouse without a marriage event also counts as missing the place.
     */
    #[Test]
    public function missingEventGapsCountSpousesWithoutAMarriageEventOrPlace(): void
    {
        $tree = $this->importFixtureTree('tree-health-marriage-gaps.ged');
        $gaps = (new TreeHealthRepository($tree))->missingEventGaps();

        $marriageDateGap = $gaps['MARR_date'] ?? self::fail('Missing MARR_date gap row');
        self::assertSame(2, $marriageDateGap['value']);
        self::assertSame(6, $marriageDateGap['total']);

        $marrPlace = $gaps['MARR_place'] ?? self::fail('Missing MARR_place gap row');
        self::assertSame(4, $marrPlace['value']);
        self::assertSame(6, $marrPlace['total']);
    }

    /**
     * The death rows count deceased individuals only, by the shared definition
     * of a living individual. A living person and a person without any data are
     * no gap. Among the deceased, D1 has a dated death with a place, D2 a dated
     * death without a place, D3 is deceased by age without a death event, D4 by
     * a burial without a death event and D5 has a death with a place and no
     * date. The date row counts the missing event and the undated event, the
     * place row counts the missing event and the event without a place, so the
     * two rows separate D2 from D5.
     */
    #[Test]
    public function missingEventGapsCountTheDeathRowsOverDeceasedIndividualsOnly(): void
    {
        $year = (int) date('Y');

        $lines = [
            '0 HEAD',
            '1 CHAR UTF-8',
            '1 GEDC',
            '2 VERS 5.5.1',
            '2 FORM LINEAGE-LINKED',
            '0 @D1@ INDI',
            '1 NAME Full /Death/',
            '1 BIRT',
            '2 DATE 1 JAN ' . ($year - 80),
            '1 DEAT',
            '2 DATE 1 JAN ' . ($year - 10),
            '2 PLAC Berlin, Germany',
            '0 @D2@ INDI',
            '1 NAME No /Place/',
            '1 BIRT',
            '2 DATE 1 JAN ' . ($year - 70),
            '1 DEAT',
            '2 DATE 1 JAN ' . ($year - 5),
            '0 @D3@ INDI',
            '1 NAME By /Age/',
            '1 BIRT',
            '2 DATE 1850',
            '0 @D4@ INDI',
            '1 NAME Only /Burial/',
            '1 BIRT',
            '2 DATE 1 JAN ' . ($year - 60),
            '1 BURI',
            '2 DATE 1 JAN ' . ($year - 2),
            '0 @D5@ INDI',
            '1 NAME No /Date/',
            '1 BIRT',
            '2 DATE 1 JAN ' . ($year - 50),
            '1 DEAT',
            '2 PLAC Hamburg, Germany',
            '0 @L1@ INDI',
            '1 NAME Young /Living/',
            '1 BIRT',
            '2 DATE 1 JAN ' . ($year - 30),
            '0 @L2@ INDI',
            '1 NAME No /Data/',
            '0 @L3@ INDI',
            '1 NAME No /Birthdate/',
            '1 BIRT',
            '2 PLAC Bonn, Germany',
            '0 @L4@ INDI',
            '1 NAME Phrase /Birthdate/',
            '1 BIRT',
            '2 DATE (some time ago)',
            '0 TRLR',
        ];

        $tree = $this->importGedcomTree('tree-health-death-' . strval($year), implode("\n", $lines));
        $gaps = (new TreeHealthRepository($tree))->missingEventGaps();

        $deatDate = $gaps['DEAT_date'] ?? self::fail('Missing DEAT_date gap row');
        self::assertSame(3, $deatDate['value']);
        self::assertSame(5, $deatDate['total']);

        $deatPlace = $gaps['DEAT_place'] ?? self::fail('Missing DEAT_place gap row');
        self::assertSame(3, $deatPlace['value']);
        self::assertSame(5, $deatPlace['total']);

        // Every individual counts for birth. L2 has no birth, L3 a birth without
        // a date and L4 a birth whose date is a phrase that holds no Julian day,
        // so three individuals miss the date, and only L3 carries a place.
        $birtDate = $gaps['BIRT_date'] ?? self::fail('Missing BIRT_date gap row');
        self::assertSame(3, $birtDate['value']);
        self::assertSame(9, $birtDate['total']);

        $birtPlace = $gaps['BIRT_place'] ?? self::fail('Missing BIRT_place gap row');
        self::assertSame(8, $birtPlace['value']);
    }

    /**
     * The marriage rows count spouses. F1 has a dated marriage with a place, F2
     * (one spouse) a dated marriage without a place, F3 a marriage with a place
     * and no date and F4 no marriage event. The date row counts F3 and F4, the
     * place row counts F2 and F4. Two spouses belong to two families each, E1
     * with the dated family first and E2 with the undated family first, and
     * both have a date because one of their families has a dated marriage, so
     * the order of the families does not decide.
     */
    #[Test]
    public function missingEventGapsCountTheMarriageRowsPerSpouse(): void
    {
        $lines = [
            '0 HEAD',
            '1 CHAR UTF-8',
            '1 GEDC',
            '2 VERS 5.5.1',
            '2 FORM LINEAGE-LINKED',
        ];

        $spouseFamilies = [
            'A1' => ['F1'],
            'A2' => ['F1'],
            'B1' => ['F2'],
            'C1' => ['F3'],
            'C2' => ['F3'],
            'D1' => ['F4'],
            'D2' => ['F4'],
            'E1' => ['F5', 'F6'],
            'E2' => ['F7', 'F8'],
        ];

        foreach ($spouseFamilies as $xref => $familyIds) {
            $lines[] = '0 @' . $xref . '@ INDI';
            $lines[] = '1 NAME Person /' . $xref . '/';

            foreach ($familyIds as $familyId) {
                $lines[] = '1 FAMS @' . $familyId . '@';
            }
        }

        $families = [
            ['F1', 'A1', 'A2', ['2 DATE 1 JAN 1950', '2 PLAC Berlin, Germany']],
            ['F2', 'B1', null, ['2 DATE 1 JAN 1951']],
            ['F3', 'C1', 'C2', ['2 PLAC Hamburg, Germany']],
            ['F4', 'D1', 'D2', null],
            ['F5', 'E1', null, ['2 DATE 1 JAN 1952', '2 PLAC Bonn, Germany']],
            ['F6', 'E1', null, null],
            ['F7', 'E2', null, null],
            ['F8', 'E2', null, ['2 DATE 1 JAN 1953', '2 PLAC Essen, Germany']],
        ];

        foreach ($families as [$id, $husband, $wife, $marriage]) {
            $lines[] = '0 @' . $id . '@ FAM';
            $lines[] = '1 HUSB @' . $husband . '@';

            if ($wife !== null) {
                $lines[] = '1 WIFE @' . $wife . '@';
            }

            if ($marriage !== null) {
                $lines[] = '1 MARR';

                foreach ($marriage as $line) {
                    $lines[] = $line;
                }
            }
        }

        $lines[] = '0 TRLR';

        $tree = $this->importGedcomTree('tree-health-marriage-' . strval(date('Y')), implode("\n", $lines));
        $gaps = (new TreeHealthRepository($tree))->missingEventGaps();

        $marrDate = $gaps['MARR_date'] ?? self::fail('Missing MARR_date gap row');
        self::assertSame(4, $marrDate['value']);
        self::assertSame(9, $marrDate['total']);

        $marrPlace = $gaps['MARR_place'] ?? self::fail('Missing MARR_place gap row');
        self::assertSame(3, $marrPlace['value']);
        self::assertSame(9, $marrPlace['total']);
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
