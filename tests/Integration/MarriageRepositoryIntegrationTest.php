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
use MagicSunday\Webtrees\Statistic\Support\Calc\AgeBuckets;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_column;
use function array_sum;
use function array_values;

/**
 * End-to-end test of {@see MarriageRepository} against a curated fixture
 * covering the four edge cases that matter:
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
#[CoversClass(MarriageRepository::class)]
#[UsesClass(AgeBuckets::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(DateJoin::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
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
     * Total husband-older + wife-older count across the two-sided age-gap
     * distribution.
     *
     * @param array<string, array{left: int, right: int}> $dist
     */
    private function ageGapTotal(array $dist): int
    {
        return array_sum(array_column($dist, 'left')) + array_sum(array_column($dist, 'right'));
    }

    /**
     * Age-at-marriage histogram surfaces each husband in the expected 5-year
     * bucket. Anton married at 25, Carl at 45, Emil at 35 — three distinct
     * buckets.
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
     * Duration histogram skips F3 (no end event yet) and buckets the other two
     * marriages into their decade bands. F1 1875→1920 = 45y, F2 1925→1950 =
     * 25y.
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
     * Couple age-gap histogram folds into shared magnitude bands, the side
     * decided by who is the older partner. In the fixture F1 (husband older
     * ~3y) and F2 (husband older ~15y) feed the left column, F3 (wife older
     * ~5y) the right.
     */
    #[Test]
    public function ageGapDistributionSplitsCouplesByOlderPartner(): void
    {
        $tree   = $this->importFixtureTree('marriage.ged');
        $result = $this->repository($tree)->ageGapDistribution();

        $husbandOlder = array_sum(array_column($result, 'left'));
        $wifeOlder    = array_sum(array_column($result, 'right'));

        self::assertSame(2, $husbandOlder, 'F1 + F2 — husband the older partner');
        self::assertSame(1, $wifeOlder, 'F3 — wife the older partner');

        // The husband-older ~3y couple sits on the left of the nearest band.
        self::assertSame(1, $result['0–4']['left'], 'F1 husband older ~3y');
        // The wife-older ~5y couple sits on the right of the 5–9 band.
        self::assertSame(1, $result['5–9']['right'], 'F3 wife older ~5y');
    }

    /**
     * Widowhood histogram only counts FAMs where BOTH spouses have a recorded
     * DEAT. In the fixture, only F1 qualifies: Anton died 1925, Berta died 1920
     * — a 5-year widowhood for Anton. F2 has an undated wife DEAT (Doris), F3
     * has neither spouse dated, so neither contributes. The single qualifying
     * pair lands in the 5–9 band.
     */
    #[Test]
    public function widowhoodYearsDistributionCountsOnlyFamsWithBothDeats(): void
    {
        $tree   = $this->importFixtureTree('marriage.ged');
        $result = $this->repository($tree)->widowhoodYearsDistribution();

        self::assertSame(1, $result['5–9'] ?? null, 'F1: Anton outlived Berta by 5 years');
        self::assertSame(1, array_sum($result), 'F2 + F3 contribute nothing (missing DEAT)');
    }

    /**
     * The histogram always returns the full 11-band scaffold (0–4, 5–9, ...,
     * 45–49, 50+) so the BarChart consumer renders a continuous axis even on a
     * tree with zero qualifying couples. The empty-marriages fixture carries
     * one INDI with a BIRT but no FAM, so no row qualifies.
     */
    #[Test]
    public function widowhoodYearsDistributionExposesAllBandsEvenWhenEmpty(): void
    {
        $tree   = $this->importFixtureTree('empty-marriages.ged');
        $result = $this->repository($tree)->widowhoodYearsDistribution();

        self::assertCount(11, $result, 'Always returns 11 bands (0-4 through 50+)');
        self::assertSame(0, array_sum($result));
        self::assertArrayHasKey('0–4', $result);
        self::assertArrayHasKey('50+', $result);
    }

    /**
     * `weddingsByMonth` returns the GEDCOM month-keyed counts directly: JUN ×2
     * (F1 + F3), SEP ×1 (F2).
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
     * `countEventsByCentury`. The fixture has three marriages: F1 1875 (19th),
     * F2 1925 (20th), F3 1955 (20th). The previous implementation iterated the
     * result with `$k => $v` where $v was a `[label, count]` tuple, and the
     * `(int) $v` cast on an array silently collapsed every count to 1 — so a
     * tree with 300 weddings reported "7 recorded marriages" (the number of
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
     * Every histogram method must survive a tree with zero marriages (no
     * families at all) and return its empty / all- zero shape — neither
     * throwing on `max()` of an empty array nor leaving partial buckets behind.
     * The acceptance criteria for issue #4 spell this out explicitly.
     *
     * The repo-owned histograms (ageAtMarriage, duration, ageGap) keep their
     * bucket scaffolding so the renderer reads the same keys on an empty tree
     * as on a populated one. The pass-through accessors (weddingsByCentury /
     * weddingsByMonth) legitimately return `[]` because they delegate to core —
     * those are asserted only on `array_sum === 0`.
     */
    #[Test]
    public function histogramsRenderEmptyOnZeroMarriages(): void
    {
        $tree = $this->importFixtureTree('empty-marriages.ged');
        $repo = $this->repository($tree);

        self::assertSame(0, array_sum($repo->ageAtMarriageDistribution('M')));
        self::assertSame(0, array_sum($repo->ageAtMarriageDistribution('F')));
        self::assertSame(0, array_sum($repo->durationDistribution()));
        self::assertSame(0, $this->ageGapTotal($repo->ageGapDistribution()));
        self::assertSame(0, array_sum($repo->weddingsByCentury()));
        self::assertSame(0, array_sum($repo->weddingsByMonth()));
    }

    /**
     * The "sparse" companion to the zero-marriages test. The fixture has two
     * FAMs:
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
     * The skip branches in each repo method are otherwise dead-code paths the
     * existing fixtures never exercise.
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
        self::assertSame(0, $this->ageGapTotal($repo->ageGapDistribution()));

        // F2's MARR 1905 lands in the 20th century.
        self::assertSame(1, array_sum($repo->weddingsByCentury()));
        // F2's MARR is in JUN.
        self::assertSame(1, $repo->weddingsByMonth()['JUN'] ?? null);
    }

    /**
     * Webtrees writes TWO rows into the `dates` table for every BET..AND /
     * FROM..TO date range. The age-gap query joins `families` to two `dates`
     * aliases (`hb` for husband BIRT, `wb` for wife BIRT); without `GROUP BY
     * families.f_id` a single ranged BIRT row would surface as two entries in
     * the histogram, both contributing slightly different gaps from the same
     * family.
     *
     * Fixture `marriage-edge-cases.ged` carries four families:
     *
     * * F1 — both spouses full-date BIRT (~3 year gap),
     * * F2 — both spouses full-date BIRT (~5 year gap),
     * * F3 — husband `BET 1870 AND 1873` BIRT, wife full-date BIRT
     *        (~2 year gap once the BET..AND is aggregated to its
     *        lower bound),
     * * F4 — both spouses full-date BIRT (~1 year gap; the FROM..TO
     *        DEAT on the husband is irrelevant to this histogram).
     *
     * Post-dedup the histogram carries exactly four entries. Without the GROUP
     * BY F3 would surface twice (one row per BIRT range bound), pushing the sum
     * to 5.
     */
    #[Test]
    public function ageGapDistributionDedupsRangedBirthRows(): void
    {
        $tree   = $this->importFixtureTree('marriage-edge-cases.ged');
        $result = $this->repository($tree)->ageGapDistribution();

        self::assertSame(
            4,
            $this->ageGapTotal($result),
            'F1 + F2 + F3 + F4 = 4 entries; without dedup F3 would contribute 2 (one per BET..AND bound) and the sum would climb to 5',
        );

        // Bucket-specific lock so a future MIN -> MAX flip on
        // `hb.d_julianday1` would surface: with MIN (lower bound) every
        // husband anchors a couple of years older than his wife → the four
        // land husband-older (left) in the nearest "0–4" band. A MIN -> MAX
        // swap on F3 BIRT would flip its sign toward wife-older and move it to
        // the right, surfacing here.
        self::assertSame(4, array_sum(array_column($result, 'left')), 'F1..F4 all husband-older, post-MIN-aggregate');
        self::assertSame(0, array_sum(array_column($result, 'right')), 'no family flips to wife-older');
    }

    /**
     * Same doubling pathology on the DEAT side: a FROM..TO / BET..AND DEAT
     * produces two rows in the `dates` table, and the widowhood query joins
     * twice on `dates` (husband DEAT, wife DEAT). The fixture's F4 carries
     * husband DEAT `FROM 1945 TO 1947` plus a full-date wife DEAT — without
     * `GROUP BY families.f_id` F4 would yield two entries with different
     * widowhood years (one per husband-DEAT bound). All four families have both
     * DEAT dates, so the post-dedup histogram totals four; without dedup it
     * totals five.
     */
    #[Test]
    public function widowhoodYearsDistributionDedupsRangedDeathRows(): void
    {
        $tree   = $this->importFixtureTree('marriage-edge-cases.ged');
        $result = $this->repository($tree)->widowhoodYearsDistribution();

        self::assertSame(
            4,
            array_sum($result),
            'F1 + F2 + F3 + F4 = 4 entries; without dedup F4 would contribute 2 (one per FROM..TO DEAT bound) and the sum would climb to 5',
        );

        // Bucket-specific lock for the MAX(d_julianday2) aggregate.
        // F1 husb=1920 / wife=1915 → widowhood ~4y → "0–4".
        // F3 husb=1935 / wife=1942 → widowhood ~6y → "5–9".
        // F4 husb FROM 1945 TO 1947 / wife 1955 → with MAX(d_julianday2)
        // the husband-DEAT anchor lands at 31.12.1947 → widowhood ~7y
        // → "5–9". A MAX -> MIN swap would slide F4's husband DEAT to
        // 01.01.1945, pushing the widowhood to ~10y → "10–14" and
        // shifting the assertions below.
        self::assertSame(1, $result['0–4'] ?? 0, 'F1 widowhood ~4 years');
        self::assertSame(2, $result['5–9'] ?? 0, 'F3 + F4 widowhood lands in 5-9 with MAX(d_julianday2); a swap to MIN(d_julianday1) would peel F4 off into the 10-14 bucket');
        self::assertSame(1, $result['10–14'] ?? 0, 'F2 widowhood ~10 years; a MAX -> MIN swap on F4 would inflate this bucket to 2');
    }

    /**
     * The marriage-duration distribution rides on the private
     * `marriageDurationPairs()` accessor, which joins families to four date
     * aliases (MARR + DIV + 2× DEAT). A ranged BIRT / MARR / DIV / DEAT would
     * surface the same family more than once and skew the histogram. The
     * marriage-edge-cases.ged fixture exercises this via F4 whose husband DEAT
     * is FROM 1945 TO 1947 — two rows in the dates table, two distinct
     * end-julian-days, two duration histogram entries without the aggregate.
     *
     * Post-dedup the histogram totals 4 (one per FAM). All four families
     * terminate via DEAT (no DIV in the fixture) so the MARR → earliest-DEAT
     * span is:
     *
     * * F1 (1880 → wife 1915) ≈ 35y → `30–39`,
     * * F2 (1885 → husband 1930) ≈ 44y → `40–49`,
     * * F3 (1895 → husband 1935) ≈ 39y → `30–39`,
     * * F4 (1905 → MIN(husband d_julianday1) = 1945-01-01)
     *   ≈ 39y → `30–39`.
     *
     * The bucket-specific assertion locks the MIN(d_julianday1) aggregate for
     * the end-of-marriage anchor: a MIN -> MAX swap on F4 husband DEAT would
     * slide the duration to ≈ 41y and push F4 into the `40–49` bucket, peeling
     * the `30–39` count from 3 to 2.
     */
    #[Test]
    public function durationDistributionDedupsRangedTerminusRows(): void
    {
        $tree   = $this->importFixtureTree('marriage-edge-cases.ged');
        $result = $this->repository($tree)->durationDistribution();

        self::assertSame(
            4,
            array_sum($result),
            'F1 + F2 + F3 + F4 = 4 entries; without the GROUP BY F4 contributes 2 (one per FROM..TO DEAT bound) and the sum climbs to 5',
        );
        self::assertSame(3, $result['30–39'] ?? 0, 'F1 + F3 + F4 land in 30-39 with MIN(husb_d.d_julianday1); a swap on F4 would peel one entry off');
        self::assertSame(1, $result['40–49'] ?? 0, 'F2 ~44y; a MIN -> MAX swap on F4 husband DEAT would inflate this bucket to 2');
    }
}
