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
use MagicSunday\Webtrees\Statistic\Repository\MarriageRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_sum;
use function array_values;

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
            $this->statisticsData($tree),
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
     * F3: 1920 − 1915 = +5y → 5-9.
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
            if (str_starts_with($label, '-')) {
                $negTotal += $count;
            } elseif (str_starts_with($label, '<')) {
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
     */
    #[Test]
    public function weddingsByCenturyPreservesActualCounts(): void
    {
        $tree   = $this->importFixtureTree('marriage.ged');
        $result = $this->repository($tree)->weddingsByCentury();

        self::assertSame(3, array_sum($result));
        self::assertContains(2, array_values($result), 'one century should carry the two 20th-century marriages');
    }

    /**
     * Every histogram method must survive a tree with zero
     * marriages (no families at all) and return its empty / all-
     * zero shape — neither throwing on `max()` of an empty array
     * nor leaving partial buckets behind. The acceptance criteria
     * for issue #4 spell this out explicitly.
     *
     * The repo-owned histograms (ageAtMarriage, duration, ageGap)
     * keep their bucket scaffolding so the renderer reads the same
     * keys on an empty tree as on a populated one. The pass-through
     * accessors (weddingsByCentury / weddingsByMonth) legitimately
     * return `[]` because they delegate to core — those are
     * asserted only on `array_sum === 0`.
     */
    #[Test]
    public function histogramsRenderEmptyOnZeroMarriages(): void
    {
        $tree = $this->importFixtureTree('empty-marriages.ged');
        $repo = $this->repository($tree);

        self::assertSame(0, array_sum($repo->ageAtMarriageDistribution('M')));
        self::assertSame(0, array_sum($repo->ageAtMarriageDistribution('F')));
        self::assertSame(0, array_sum($repo->durationDistribution()));
        self::assertSame(0, array_sum($repo->ageGapDistribution()));
        self::assertSame(0, array_sum($repo->weddingsByCentury()));
        self::assertSame(0, array_sum($repo->weddingsByMonth()));
    }

    /**
     * The "sparse" companion to the zero-marriages test. The fixture
     * has two FAMs:
     *
     *  F1 — HUSB + WIFE present, NO MARR tag, NO BIRT dates → must
     *       not contribute to durationDistribution (no marriage to
     *       measure) nor ageAtMarriageDistribution (no MARR to age
     *       against) nor ageGapDistribution (no spouse BIRT dates).
     *
     *  F2 — has MARR but only the husband has BIRT → ageGapDistribution
     *       must drop the row (wife BIRT missing), ageAtMarriageDistribution
     *       must drop the wife (no BIRT), but the husband is still
     *       counted.
     *
     * The skip branches in each repo method are otherwise dead-code
     * paths the existing fixtures never exercise.
     */
    #[Test]
    public function histogramsSkipFamsWithoutMarrOrBirth(): void
    {
        $tree = $this->importFixtureTree('sparse-marriages.ged');
        $repo = $this->repository($tree);

        // F1 has no MARR → F1 contributes nothing.
        // F2 has MARR + husband BIRT 1880 + wife no BIRT.
        // Husband Bert was born 1880, married 1905 → 25 years old.
        $husbands = $repo->ageAtMarriageDistribution('M');
        self::assertSame(1, $husbands['25–29'] ?? null);
        self::assertSame(1, array_sum($husbands), 'only Bert qualifies — Anna+Berta lack BIRT');

        // No wife has a BIRT → wives histogram is empty.
        self::assertSame(0, array_sum($repo->ageAtMarriageDistribution('F')));

        // F1 has no MARR; F2 has MARR but no DIV / DEAT → no
        // terminating event → duration histogram empty.
        self::assertSame(0, array_sum($repo->durationDistribution()));

        // F1 has no spouse BIRT; F2 only has the husband's BIRT
        // → no fully-dated couple → age-gap histogram empty.
        self::assertSame(0, array_sum($repo->ageGapDistribution()));

        // F2's MARR 1905 lands in the 20th century.
        self::assertSame(1, array_sum($repo->weddingsByCentury()));
        // F2's MARR is in JUN.
        self::assertSame(1, $repo->weddingsByMonth()['JUN'] ?? null);
    }
}
