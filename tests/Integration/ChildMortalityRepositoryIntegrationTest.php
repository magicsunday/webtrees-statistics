<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\ChildMortalityRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_column;

/**
 * End-to-end test of {@see ChildMortalityRepository} backed by
 * `child-mortality.ged`:
 *
 *   I1–I3 — 19th century, died < 5 years old
 *   I4–I5 — 19th century, survived past 5
 *   I6–I10 — 20th century, survived past 5
 *   I11 — 16th century, died < 5 (single child — below cohort
 *          threshold, must be dropped from the per-century view)
 *   I12 — birth only, no death (excluded)
 *   I13 — death only, no birth (excluded)
 *
 * Expected:
 *   - tree-wide: 11 valid pairs, 4 died (I1+I2+I3 in 19th, I11 in 16th) → 36.4 %
 *   - per-century: 19th 60.0 %, 20th 0.0 %, 16th omitted
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ChildMortalityRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * The tree-wide summary counts every individual whose BIRT and
     * DEAT julian-days are both > 0 (regardless of recording
     * completeness elsewhere) and computes the under-5 percentage
     * across all of them, ignoring per-century cohort suppression.
     */
    #[Test]
    public function summaryAggregatesAcrossAllValidPairs(): void
    {
        $tree   = $this->importFixtureTree('child-mortality.ged');
        $result = (new ChildMortalityRepository($tree))->summary();

        self::assertNotNull($result);
        self::assertSame(11, $result['total']);
        self::assertSame(4, $result['died']);
        self::assertSame(36.4, $result['rate']);
    }

    /**
     * Per-century breakdown drops cohorts below the minimum
     * threshold (5 children) so the line does not spike on a single
     * unlucky family. The 16th-century single death (Klara) must not
     * appear; the 19th and 20th centuries must.
     */
    #[Test]
    public function perCenturyBreakdownSuppressesTinyCohorts(): void
    {
        $tree   = $this->importFixtureTree('child-mortality.ged');
        $result = (new ChildMortalityRepository($tree))->byBirthCentury();

        $centuries = array_column($result, 'century');
        self::assertNotContains(16, $centuries, '16th-century cohort has 1 child — below threshold, must be dropped');
        self::assertContains(19, $centuries);
        self::assertContains(20, $centuries);
    }

    /**
     * Per-century rates carry the actual numerator + denominator so
     * the view can phrase the tooltip prose ("3 of 5 children died
     * before age 5"). Verify the numbers match the fixture inventory.
     */
    #[Test]
    public function perCenturyRatesCarryNumeratorAndDenominator(): void
    {
        $tree   = $this->importFixtureTree('child-mortality.ged');
        $result = (new ChildMortalityRepository($tree))->byBirthCentury();

        $byCentury = [];

        foreach ($result as $entry) {
            $byCentury[$entry['century']] = $entry;
        }

        self::assertSame(5, $byCentury[19]['total']);
        self::assertSame(3, $byCentury[19]['died']);
        self::assertSame(60.0, $byCentury[19]['rate']);

        self::assertSame(5, $byCentury[20]['total']);
        self::assertSame(0, $byCentury[20]['died']);
        self::assertSame(0.0, $byCentury[20]['rate']);
    }
}
