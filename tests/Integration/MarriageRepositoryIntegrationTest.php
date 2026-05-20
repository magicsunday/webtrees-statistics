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
use MagicSunday\Webtrees\Statistic\Repository\MarriageRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_sum;
use function array_values;
use function strpos;

/**
 * End-to-end test of {@see MarriageRepository} against a curated
 * fixture covering the four edge cases that matter:
 *
 *   F1: Anton (1850) × Berta (1853), married 1875 — both eventually
 *       deceased (1925 / 1920), so the duration ends at 1920.
 *   F2: Carl (1880) × Doris (1895), married 1925, Carl dies 1950,
 *       Doris is still living — duration ends at 1950.
 *   F3: Emil (1920) × Frieda (1915), married 1955 — both living, so
 *       the marriage has no terminating event and contributes
 *       nothing to the duration histogram.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class MarriageRepositoryIntegrationTest extends IntegrationTestCase
{
    private function repository(Tree $tree): MarriageRepository
    {
        return new MarriageRepository(
            $tree,
            new StatisticsData($tree, new UserService()),
        );
    }

    /**
     * Age-at-marriage histogram surfaces each husband in the
     * expected 5-year bucket. Anton married at 25, Carl at 45, Emil
     * at 35 — three distinct buckets.
     */
    #[Test]
    public function ageAtMarriageDistributionBucketsHusbands(): void
    {
        $tree   = $this->importFixtureTree('marriage.ged');
        $result = $this->repository($tree)->ageAtMarriageDistribution('M');

        self::assertSame(1, $result['25–29'] ?? null, 'Anton (25) sits in 25-29');
        self::assertSame(1, $result['45–49'] ?? null, 'Carl (45) sits in 45-49');
        self::assertSame(1, $result['35–39'] ?? null, 'Emil (35) sits in 35-39');
        self::assertSame(3, array_sum($result));
    }

    /**
     * Duration histogram skips F3 (no end event yet) and buckets the
     * other two marriages into their decade bands. F1 1875→1920 = 45y,
     * F2 1925→1950 = 25y.
     */
    #[Test]
    public function durationDistributionExcludesOpenMarriages(): void
    {
        $tree   = $this->importFixtureTree('marriage.ged');
        $result = $this->repository($tree)->durationDistribution();

        self::assertSame(1, $result['40–49'] ?? null, 'F1 ran 45 years');
        self::assertSame(1, $result['20–29'] ?? null, 'F2 ran 25 years');
        self::assertSame(2, array_sum($result), 'F3 contributes nothing');
    }

    /**
     * Couple age-gap histogram uses signed buckets centred on zero.
     * F1: husband 1850 − wife 1853 = −3y → -5 to -1 bucket
     * F2: 1880 − 1895 = -15y → -15 to -11
     * F3: 1920 − 1915 = +5y → 5-9
     */
    #[Test]
    public function ageGapDistributionAcceptsSignedBuckets(): void
    {
        $tree   = $this->importFixtureTree('marriage.ged');
        $result = $this->repository($tree)->ageGapDistribution();

        // The buckets render with en-dashes (–) for positive ranges
        // and " to " for negative ranges — find the correct one
        // by exact key match.
        $negTotal = 0;
        $posTotal = 0;
        foreach ($result as $label => $count) {
            if (strpos($label, '-') === 0) {
                $negTotal += $count;
            } elseif (strpos($label, '<') === 0) {
                $negTotal += $count;
            } else {
                $posTotal += $count;
            }
        }

        // 2 couples have the husband younger, 1 the husband older.
        self::assertSame(2, $negTotal);
        self::assertSame(1, $posTotal);
        self::assertSame(3, array_sum($result));
    }

    /**
     * `weddingsByMonth` returns the GEDCOM month-keyed counts
     * directly: JUN ×2 (F1 + F3), SEP ×1 (F2).
     */
    #[Test]
    public function weddingsByMonthCountsByGedcomMonthCode(): void
    {
        $tree   = $this->importFixtureTree('marriage.ged');
        $result = $this->repository($tree)->weddingsByMonth();

        self::assertSame(2, $result['JUN'] ?? null);
        self::assertSame(1, $result['SEP'] ?? null);
    }

    /**
     * `weddingsByCentury` returns the per-century count from core's
     * `countEventsByCentury`. The fixture has three marriages: F1
     * 1875 (19th), F2 1925 (20th), F3 1955 (20th). The previous
     * implementation iterated the result with `$k => $v` where $v
     * was a `[label, count]` tuple, and the `(int) $v` cast on an
     * array silently collapsed every count to 1 — so a tree with
     * 300 weddings reported "7 recorded marriages" (the number of
     * distinct centuries, not the actual total).
     *
     * The assertion below — total === fixture marriage count — is
     * the load-bearing check: a tuple-vs-map regression in core's
     * accessor would surface immediately as a total of 3 (centuries)
     * vs 3 (marriages), matching by accident; using 4 fixture rows
     * across 2 centuries would break that coincidence. The fixture
     * stays at 3 because that's enough to assert the per-century
     * split is correct.
     */
    #[Test]
    public function weddingsByCenturyPreservesActualCounts(): void
    {
        $tree   = $this->importFixtureTree('marriage.ged');
        $result = $this->repository($tree)->weddingsByCentury();

        // Total marriages across the fixture must match the
        // tuple-collapsing bug's reciprocal: 3 marriages in 2
        // centuries → sum of values must be 3, not 2.
        self::assertSame(3, array_sum($result));
        // At least one century must carry > 1 marriage for the
        // assertion to actually defend against the collapse bug.
        self::assertContains(2, array_values($result), 'one century should carry the two 20th-century marriages');
    }
}
