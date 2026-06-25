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
use MagicSunday\Webtrees\Statistic\Repository\DivorceRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\AgeBuckets;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

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
#[UsesClass(AgeBuckets::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(DateJoin::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class DivorceRepositoryIntegrationTest extends AbstractIntegrationTestCase
{
    private function repository(Tree $tree): DivorceRepository
    {
        return new DivorceRepository($tree);
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
     *   wife age 40 → `40–49` (BIRT 10.6.1865 → DIV 14.9.1905, her
     *   40th birthday that year already passed);
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
        self::assertSame(1, $husbands['50–59'] ?? 0, 'F2 husband (54 via MIN DIV) — a MIN -> MAX swap would push F2 to 58 (still in 50–59 here)');

        self::assertSame(3, array_sum($wives), 'F1 + F2 + F3 = 3 women; without dedup F2 doubles and the sum climbs to 4');
        self::assertSame(2, $wives['40–49'] ?? 0, 'F1 wife (40) + F3 wife (43)');
        self::assertSame(1, $wives['50–59'] ?? 0, 'F2 wife (52 via MIN DIV)');
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
