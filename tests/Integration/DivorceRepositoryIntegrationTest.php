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
use MagicSunday\Webtrees\Statistic\Enum\Sex;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarPayload;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarSeries;
use MagicSunday\Webtrees\Statistic\Repository\DivorceRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\AgeBuckets;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_combine;
use function array_map;
use function array_sum;

/**
 * Integration test for {@see DivorceRepository}. Fixture has 4 families; 3 are
 * divorced (F1/F2/F4), 1 still married (F3).
 *
 *   F1: Alf 1950, Anna 1952; MARR 1975, DIV 1990 — Alf 40, Anna 38
 *   F2: Bert 1955, Beate 1958; MARR 1985, DIV 2005 — Bert 50, Beate 47
 *   F4: Dirk 1970, Doris 1972; MARR 1995, DIV 2015 — Dirk 45, Doris 43
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(DivorceRepository::class)]
#[UsesClass(Sex::class)]
#[UsesClass(StackedBarPayload::class)]
#[UsesClass(StackedBarSeries::class)]
#[UsesClass(AgeBuckets::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(DateJoin::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
#[UsesClass(CenturyName::class)]
final class DivorceRepositoryIntegrationTest extends IntegrationTestCase
{
    private function repository(Tree $tree): DivorceRepository
    {
        return new DivorceRepository(
            $tree,
            $this->statisticsData($tree),
        );
    }

    /**
     * Age-at-divorce histogram for husbands surfaces Alf (40), Bert (50), Dirk
     * (45) in their respective 10-year buckets — Alf and Dirk both fall into
     * 40-49, Bert into 50-59.
     */
    #[Test]
    public function ageAtDivorceDistributionBucketsHusbands(): void
    {
        $tree   = $this->importFixtureTree('divorce.ged');
        $result = $this->repository($tree)->ageAtDivorceDistribution('M');

        self::assertSame(2, $result['40–49'] ?? null);
        self::assertSame(1, $result['50–59'] ?? null);
        self::assertSame(3, array_sum($result));
    }

    /**
     * Cohort-rate for the 1970s decade: F1 + F4 marriages, both divorced. But
     * the cohort filter drops cohorts < 3 — so the 1970s and 1990s vanish, only
     * cohorts ≥ 3 marriages survive.
     *
     * In this fixture every decade has < 3 marriages, so the filtered output is
     * empty. That IS the documented behaviour (sparse trees produce no
     * misleading "100% divorce" lines).
     */
    #[Test]
    public function divorceRateCohortFilterDropsThinDecades(): void
    {
        $tree   = $this->importFixtureTree('divorce.ged');
        $result = $this->repository($tree)->divorceRateByMarriageCohort();

        // Every decade in the fixture has < 3 marriages, so nothing
        // should be reported. The filter is the design.
        self::assertSame([], $result);
    }

    /**
     * Divorces-by-century groups the three DIVs into the expected centuries
     * (one in 20th, two in 21st).
     */
    #[Test]
    public function divorcesByCenturyCountsAllDivorces(): void
    {
        $tree   = $this->importFixtureTree('divorce.ged');
        $result = $this->repository($tree)->divorcesByCentury();

        // The fixture has three DIV events (1990, 2005, 2015).
        // Earlier the repository iterated core's return value with
        // `$k => $v` where `$v` was a `[label, count]` tuple — and
        // the `(int) $v` cast on an array collapsed every count to
        // 1, producing one row per century with value 1. The
        // sum-must-equal-three assertion catches that regression:
        // if the bug came back, the total would equal the number of
        // distinct centuries, not the number of divorces.
        self::assertSame(3, array_sum($result));
    }

    /**
     * `divorcesByMonth` returns the GEDCOM month-keyed counts. Fixture: F1 DIV
     * JUN 1990, F2 DIV SEP 2005, F4 DIV JUN 2015 → JUN ×2, SEP ×1.
     */
    #[Test]
    public function divorcesByMonthCountsByGedcomMonthCode(): void
    {
        $tree   = $this->importFixtureTree('divorce.ged');
        $result = $this->repository($tree)->divorcesByMonth();

        self::assertSame(2, $result['JUN'] ?? null);
        self::assertSame(1, $result['SEP'] ?? null);
        self::assertSame(3, array_sum($result));
    }

    /**
     * Age-at-divorce for wives separately: Anna 1952 → 1990 = 38 → 30-39
     * bucket; Beate 1958 → 2005 = 47 → 40-49; Doris 1972 → 2015 = 43 → 40-49.
     */
    #[Test]
    public function ageAtDivorceDistributionForWives(): void
    {
        $tree   = $this->importFixtureTree('divorce.ged');
        $result = $this->repository($tree)->ageAtDivorceDistribution('F');

        self::assertSame(1, $result['30–39'] ?? null);
        self::assertSame(2, $result['40–49'] ?? null);
        self::assertSame(3, array_sum($result));
    }

    /**
     * `divorcesByCenturyAndAgeBand` classifies F1 (Alf 40) into the 40–49 band
     * of the 20th century, F2 (Bert 50) into the 50–59 band of the 21st century
     * and F4 (Dirk 45) into the 40–49 band of the 21st century. The legend
     * always carries every ten-year cohort plus Unknown, even when a band has
     * zero counts everywhere, so the reader sees the complete age scale.
     */
    #[Test]
    public function divorcesByCenturyAndAgeBandBucketsByHusbandAge(): void
    {
        $tree   = $this->importFixtureTree('divorce.ged');
        $result = $this->repository($tree)->divorcesByCenturyAndAgeBand();

        self::assertSame(['20th', '21st'], $result->categories);
        self::assertSame(['20th Century', '21st Century'], $result->tooltipLabels);

        // Every band must appear in the legend regardless of zeros.
        $bandNames = array_map(static fn (StackedBarSeries $series): string => $series->name, $result->series);
        self::assertSame(
            ['0–9', '10–19', '20–29', '30–39', '40–49', '50–59', '60–69', '70–79', '80–89', '90+', 'Unknown'],
            $bandNames,
        );

        $perBand = array_combine(
            $bandNames,
            array_map(static fn (StackedBarSeries $series): array => $series->data, $result->series),
        );

        self::assertSame([1, 1], $perBand['40–49']);
        self::assertSame([0, 1], $perBand['50–59']);
        self::assertSame([0, 0], $perBand['0–9']);
        self::assertSame([0, 0], $perBand['10–19']);
        self::assertSame([0, 0], $perBand['20–29']);
        self::assertSame([0, 0], $perBand['30–39']);
        self::assertSame([0, 0], $perBand['60–69']);
        self::assertSame([0, 0], $perBand['70–79']);
        self::assertSame([0, 0], $perBand['80–89']);
        self::assertSame([0, 0], $perBand['90+']);
        self::assertSame([0, 0], $perBand['Unknown']);
    }

    /**
     * Totals invariant: summing every band across every century must equal the
     * grand total of `divorcesByCentury`. The widget is built around the
     * side-by-side comparison, so this catches the double-counting regression
     * where iterating both spouse-birth columns rendered twice the sample size.
     * Per-century equality is asserted separately by the classification tests —
     * checking it through `divorcesByCentury` here would couple the test to
     * core's SQL `ROUND` century formula, which diverges from the PHP `intdiv`
     * formula under SQLite's integer division and is irrelevant to the
     * cross-card invariant.
     */
    #[Test]
    public function divorcesByCenturyAndAgeBandPreservesGrandTotal(): void
    {
        $tree    = $this->importFixtureTree('divorce.ged');
        $repo    = $this->repository($tree);
        $stacked = $repo->divorcesByCenturyAndAgeBand();

        $stackedGrandTotal = array_sum(array_map(
            static fn (StackedBarSeries $series): int => array_sum($series->data),
            $stacked->series,
        ));

        self::assertSame(array_sum($repo->divorcesByCentury()), $stackedGrandTotal);
    }

    /**
     * Husband-missing, wife-only and no-BIRT-at-all rows must still count
     * toward the per-century totals — via the wife-fallback branch and the
     * Unknown catch-all, respectively. The 150-year age in F4 is a data-entry
     * typo and lands in Unknown rather than being silently dropped.
     *
     * Fixture (divorce-age-bands.ged):
     *   F1 Hugo 1900 + Hilde 1903, DIV 1990 → husband 90 → 90+
     *   F2 Walter (no BIRT) + Wilma 1955, DIV 1995 → wife 40 → 40–49
     *   F3 Mark + Mira (no BIRT), DIV 2010 → Unknown
     *   F4 Otto 1700 + Olga 1705, DIV 1850 → age 150 typo → Unknown
     */
    #[Test]
    public function divorcesByCenturyAndAgeBandRoutesMissingAgesToUnknown(): void
    {
        $tree   = $this->importFixtureTree('divorce-age-bands.ged');
        $result = $this->repository($tree)->divorcesByCenturyAndAgeBand();

        self::assertSame(['19th', '20th', '21st'], $result->categories);

        $perBand = array_combine(
            array_map(static fn (StackedBarSeries $series): string => $series->name, $result->series),
            array_map(static fn (StackedBarSeries $series): array => $series->data, $result->series),
        );

        // Hugo 90 (90+, 20th) + Wilma 40 wife-fallback (40–49, 20th).
        self::assertSame([0, 1, 0], $perBand['90+']);
        self::assertSame([0, 1, 0], $perBand['40–49']);

        // F3 (no BIRT) → 21st Unknown; F4 (age 150 typo) → 19th Unknown.
        self::assertSame([1, 0, 1], $perBand['Unknown']);
    }

    /**
     * Grand-total invariant on the sparsely-dated fixture: every row that
     * `divorcesByCentury` counts must end up in some band (no-BIRT rows in
     * Unknown, age typos in Unknown, valid ages in the matching life-stage
     * band). 4 divorces → 4 ticks total.
     */
    #[Test]
    public function divorcesByCenturyAndAgeBandReconcilesGrandTotalOnSparseTree(): void
    {
        $tree    = $this->importFixtureTree('divorce-age-bands.ged');
        $repo    = $this->repository($tree);
        $stacked = $repo->divorcesByCenturyAndAgeBand();

        $stackedGrandTotal = array_sum(array_map(
            static fn (StackedBarSeries $series): int => array_sum($series->data),
            $stacked->series,
        ));

        self::assertSame(4, array_sum($repo->divorcesByCentury()));
        self::assertSame(4, $stackedGrandTotal);
    }

    /**
     * Empty tree returns the empty `{categories, tooltipLabels, series}` shape
     * — chart-lib's empty-state placeholder picks up the absence and renders
     * the "no data" message.
     */
    #[Test]
    public function divorcesByCenturyAndAgeBandRendersEmptyOnZeroDivorces(): void
    {
        $tree   = $this->importFixtureTree('empty-marriages.ged');
        $result = $this->repository($tree)->divorcesByCenturyAndAgeBand();

        self::assertSame([], $result->categories);
        self::assertSame([], $result->tooltipLabels);
        self::assertSame([], $result->series);
    }

    /**
     * Every divorce histogram method must survive a tree with zero divorces (no
     * families at all) — the same acceptance contract issue #4 requires for
     * marriages also applies here because the sex-axis CSS tokens are shared
     * across the two sets.
     */
    #[Test]
    public function histogramsRenderEmptyOnZeroDivorces(): void
    {
        $tree = $this->importFixtureTree('empty-marriages.ged');
        $repo = $this->repository($tree);

        self::assertSame(0, array_sum($repo->ageAtDivorceDistribution('M')));
        self::assertSame(0, array_sum($repo->ageAtDivorceDistribution('F')));
        self::assertSame(0, array_sum($repo->divorcesByCentury()));
        self::assertSame(0, array_sum($repo->divorcesByMonth()));
        self::assertSame([], $repo->divorceRateByMarriageCohort());
    }

    /**
     * Webtrees writes TWO rows into the `dates` table for every BET..AND /
     * FROM..TO date range. Every Divorce query joins `families` to at least two
     * `dates` aliases (DIV plus BIRT / MARR), so a single ranged DIV or BIRT
     * would surface the same family more than once and skew every histogram.
     *
     * Fixture `divorce-edge-cases.ged` carries three families:
     *
     * * F1 — full-date BIRT for both spouses + full-date MARR + DIV
     *   (control); husband age at divorce 45 → bucket `40–49`,
     *   wife age 40 → `40–49` (BIRT 22.9.1865 → DIV 14.9.1905 is
     *   8 days short of 40 calendar years but the integer-year
     *   bucket math via `intdiv(days, 365)` and the 9 leap days
     *   between the dates land it at exactly 40);
     * * F2 — full-date BIRT + MARR, DIV `BET 1925 AND 1928` — the
     *   DIV ranges drives the dedup on the divorce side. Post-MIN
     *   anchor: 01.01.1925, husband age 54 → `50–59`, wife age 52
     *   → `50–59`;
     * * F3 — husband BIRT `BET 1880 AND 1883`, full-date MARR + DIV
     *   — the BIRT range drives the dedup on the spouse side.
     *   Post-MIN anchor: 01.01.1880, husband age 45 → `40–49`,
     *   wife age 43 → `40–49`.
     *
     * Without the GROUP BY F2 and F3 each contribute two rows for the husband
     * side (sum climbs from 3 to 5) and F2 doubles on the wife side as well
     * (sum climbs from 3 to 4; F3 wife BIRT is full-date so she stays
     * unaffected by the BIRT-doubling).
     */
    #[Test]
    public function ageAtDivorceDistributionDedupsRangedDivAndBirthRows(): void
    {
        $tree = $this->importFixtureTree('divorce-edge-cases.ged');
        $repo = $this->repository($tree);

        $husbands = $repo->ageAtDivorceDistribution('M');
        $wives    = $repo->ageAtDivorceDistribution('F');

        self::assertSame(3, array_sum($husbands), 'F1 + F2 + F3 = 3 men; without dedup F2+F3 contribute 2 each and the sum climbs to 5');
        self::assertSame(2, $husbands['40–49'] ?? 0, 'F1 (45) + F3 (45 via MIN BIRT) — a MIN -> MAX swap on F3 husband BIRT would drop the entry into 40–49 still, but the row count via husbands sum would surface the missing GROUP BY');
        self::assertSame(1, $husbands['50–59'] ?? 0, 'F2 husband (54 via MIN DIV) — a MIN -> MAX swap would push F2 to 57 (still in 50–59 here)');

        self::assertSame(3, array_sum($wives), 'F1 + F2 + F3 = 3 women; without dedup F2 doubles and the sum climbs to 4');
        self::assertSame(2, $wives['40–49'] ?? 0, 'F1 wife (40) + F3 wife (43)');
        self::assertSame(1, $wives['50–59'] ?? 0, 'F2 wife (52 via MIN DIV)');
    }

    /**
     * The cross-tabulated divorces-by-century-and-age-band stack counts one
     * tick per FAM. The same ranged-row doubling that skews the simple age
     * histograms also inflates the per-century stack totals, so the GROUP BY
     * must propagate here too.
     *
     * All three fixture families divorce in the 20th century (F1 1905, F2
     * 1925-1928, F3 1925). Per-century totals must equal the number of distinct
     * FAMs (3) — not 5 with two doubled rows.
     */
    #[Test]
    public function divorcesByCenturyAndAgeBandDedupsRangedRows(): void
    {
        $tree   = $this->importFixtureTree('divorce-edge-cases.ged');
        $result = $this->repository($tree)->divorcesByCenturyAndAgeBand();

        self::assertCount(1, $result->categories, 'all three divorces land in the 20th-century column');

        $total = 0;

        foreach ($result->series as $series) {
            foreach ($series->data as $value) {
                $total += $value;
            }
        }

        self::assertSame(3, $total, 'F1 + F2 + F3 = 3 divorces in the 20th century; without dedup F2+F3 contribute 2 each and the total climbs to 5');
    }

    /**
     * `divorceRateByMarriageCohort` joins families to MARR + DIV (leftJoin). A
     * ranged DIV with full-date MARR produces two rows: both share the same
     * `marr_year` (single anchor) but each carries a different `div_year`, so a
     * FAM gets counted twice in `total` AND `divorced` of the same cohort. The
     * resulting cohort rate drifts away from the true distinct-FAM count.
     *
     * To make the dedup observable through the public API, the fixture pads the
     * 1890s decade with two extra `MarrOnly` families (F4 MARR 1893 + F5 MARR
     * 1898, neither with a DIV) so the 1890 cohort clears the adaptive sample
     * threshold (`max(3, intdiv(totalMarriages, 100)) = 3`). The 1880 and 1900
     * cohorts stay below the floor and drop out of the visible window, leaving
     * the 1890 cohort as the only visible decade.
     *
     * Post-dedup: 1890 cohort total = 3 (F2 + F4 + F5), divorced = 1 (F2) →
     * rate = round(1 / 3, 4) = 0.3333.
     *
     * Without the GROUP BY F2's ranged DIV doubles the cohort: total = 4,
     * divorced = 2 → rate = 0.5. A regression that drops the `MIN(divr.d_year)`
     * aggregate would surface as a flipped rate here.
     */
    #[Test]
    public function divorceRateByMarriageCohortDedupsRangedDivRows(): void
    {
        $tree   = $this->importFixtureTree('divorce-edge-cases.ged');
        $result = $this->repository($tree)->divorceRateByMarriageCohort();

        self::assertSame(
            [1890 => 0.3333],
            $result,
            'post-dedup the 1890 cohort carries total = 3 (F2 + F4 + F5) and divorced = 1 (F2); without the GROUP BY the rate climbs to 0.5',
        );
    }
}
