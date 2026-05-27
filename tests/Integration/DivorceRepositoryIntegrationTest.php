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
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarSeries;
use MagicSunday\Webtrees\Statistic\Repository\DivorceRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_combine;
use function array_map;
use function array_sum;

/**
 * Integration test for {@see DivorceRepository}. Fixture has 4
 * families; 3 are divorced (F1/F2/F4), 1 still married (F3).
 *
 *   F1: Alf 1950, Anna 1952; MARR 1975, DIV 1990 — Alf 40, Anna 38
 *   F2: Bert 1955, Beate 1958; MARR 1985, DIV 2005 — Bert 50, Beate 47
 *   F4: Dirk 1970, Doris 1972; MARR 1995, DIV 2015 — Dirk 45, Doris 43
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
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
     * Age-at-divorce histogram for husbands surfaces Alf (40),
     * Bert (50), Dirk (45) in their respective 10-year buckets —
     * Alf and Dirk both fall into 40-49, Bert into 50-59.
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
     * Cohort-rate for the 1970s decade: F1 + F4 marriages, both
     * divorced. But the cohort filter drops cohorts < 3 — so the
     * 1970s and 1990s vanish, only cohorts ≥ 3 marriages survive.
     *
     * In this fixture every decade has < 3 marriages, so the
     * filtered output is empty. That IS the documented behaviour
     * (sparse trees produce no misleading "100% divorce" lines).
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
     * Divorces-by-century groups the three DIVs into the expected
     * centuries (one in 20th, two in 21st).
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
     * `divorcesByMonth` returns the GEDCOM month-keyed counts.
     * Fixture: F1 DIV JUN 1990, F2 DIV SEP 2005, F4 DIV JUN 2015
     * → JUN ×2, SEP ×1.
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
     * Age-at-divorce for wives separately: Anna 1952 → 1990 = 38
     * → 30-39 bucket; Beate 1958 → 2005 = 47 → 40-49; Doris 1972
     * → 2015 = 43 → 40-49.
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
     * `divorcesByCenturyAndAgeBand` classifies F1 (Alf 40) into the
     * 40–49 band of the 20th century, F2 (Bert 50) into the 50–59
     * band of the 21st century and F4 (Dirk 45) into the 40–49 band
     * of the 21st century. The legend always carries every ten-year
     * cohort plus Unknown, even when a band has zero counts
     * everywhere, so the reader sees the complete age scale.
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
     * Totals invariant: summing every band across every century must
     * equal the grand total of `divorcesByCentury`. The widget is
     * built around the side-by-side comparison, so this catches the
     * double-counting regression where iterating both spouse-birth
     * columns rendered twice the sample size. Per-century equality
     * is asserted separately by the classification tests — checking
     * it through `divorcesByCentury` here would couple the test to
     * core's SQL `ROUND` century formula, which diverges from the
     * PHP `intdiv` formula under SQLite's integer division and is
     * irrelevant to the cross-card invariant.
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
     * Husband-missing, wife-only and no-BIRT-at-all rows must still
     * count toward the per-century totals — via the wife-fallback
     * branch and the Unknown catch-all, respectively. The 150-year
     * age in F4 is a data-entry typo and lands in Unknown rather
     * than being silently dropped.
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
     * Grand-total invariant on the sparsely-dated fixture: every
     * row that `divorcesByCentury` counts must end up in some band
     * (no-BIRT rows in Unknown, age typos in Unknown, valid ages in
     * the matching life-stage band). 4 divorces → 4 ticks total.
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
     * Empty tree returns the empty `{categories, tooltipLabels,
     * series}` shape — chart-lib's empty-state placeholder picks up
     * the absence and renders the "no data" message.
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
     * Every divorce histogram method must survive a tree with zero
     * divorces (no families at all) — the same acceptance contract
     * issue #4 requires for marriages also applies here because the
     * sex-axis CSS tokens are shared across the two sets.
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
}
