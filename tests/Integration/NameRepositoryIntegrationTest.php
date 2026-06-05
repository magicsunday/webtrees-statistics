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
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Repository\NameRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_column;
use function array_unique;

/**
 * Integration test for {@see NameRepository}. The count / passdown cases use
 * the `name-trends.ged` fixture (twelve individuals, five distinct given names,
 * one common surname "Test"); the whitelist cases use `name-custom-subtag.ged`,
 * which pairs primary `NAME` records with custom and standardised sub-tags
 * (`_LAST`, `_AKA`, `_MARNM`, `ROMN`, `FONE`, `_HEB`) to prove that the custom,
 * alias and married-name forms are excluded while the primary name and its
 * romanised / phonetic / Hebrew transliterations still count.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(NameRepository::class)]
#[UsesClass(Sex::class)]
#[UsesClass(LineChartPayload::class)]
#[UsesClass(LineChartSeries::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(DedupedEventDates::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
#[UsesClass(CenturyName::class)]
final class NameRepositoryIntegrationTest extends IntegrationTestCase
{
    private function repository(Tree $tree): NameRepository
    {
        return new NameRepository($tree);
    }

    /**
     * Every individual carries the surname "Test" so the distinct- surname
     * count is 1. Tests the "headline number stays in lockstep with the Top-N
     * aggregation" promise.
     */
    #[Test]
    public function countDistinctSurnamesIs1ForFixture(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = $this->repository($tree)->countDistinctSurnames();

        self::assertSame(1, $result);
    }

    /**
     * Exercises the threshold > 1 branch separately so the GROUP BY + HAVING
     * path also gets test coverage. The single surname "Test" appears on all
     * twelve individuals, so the cardinality cliff sits at the population size:
     * threshold ≤ 12 returns 1, threshold ≥ 13 returns 0. Pinning both sides of
     * the boundary catches an off-by-one regression in the HAVING comparator (≥
     * vs >). The branch split was introduced to keep the query valid under
     * MySQL's `ONLY_FULL_GROUP_BY` mode.
     */
    #[Test]
    public function countDistinctSurnamesWithThresholdAboveOne(): void
    {
        $tree = $this->importFixtureTree('name-trends.ged');
        $repo = $this->repository($tree);

        self::assertSame(1, $repo->countDistinctSurnames(2));
        self::assertSame(1, $repo->countDistinctSurnames(12));
        self::assertSame(0, $repo->countDistinctSurnames(13));
    }

    /**
     * Five distinct given names appear in the fixture (Anna, Friedrich, Maria,
     * Hans, Lisa), split across sexes. Female: Anna×3, Maria×2, Lisa×1 → 3
     * distinct. Male: Friedrich×2, Hans×3 → 2 distinct. The 12th individual is
     * undated but still has a given name so still contributes.
     */
    #[Test]
    public function countDistinctGivenNamesPerSex(): void
    {
        $tree = $this->importFixtureTree('name-trends.ged');
        $repo = $this->repository($tree);

        self::assertGreaterThanOrEqual(3, $repo->countDistinctGivenNames(Sex::Female->value));
        self::assertGreaterThanOrEqual(2, $repo->countDistinctGivenNames(Sex::Male->value));
    }

    /**
     * The threshold filter excludes given names that occur fewer times than the
     * threshold. Asking for names that appear at least 3 times across the
     * fixture should drop the count.
     */
    #[Test]
    public function countDistinctGivenNamesRespectsThreshold(): void
    {
        $tree = $this->importFixtureTree('name-trends.ged');
        $repo = $this->repository($tree);

        // With threshold 1 we see all five given names; with
        // threshold 3 only Anna (3) and Hans (3) qualify.
        $unbounded = $repo->countDistinctGivenNames(NameRepository::SEX_ALL, 1);
        $threeOnly = $repo->countDistinctGivenNames(NameRepository::SEX_ALL, 3);

        self::assertGreaterThan($threeOnly, $unbounded);
    }

    /**
     * Father → son name-passdown fixture carries three cohorts: 1700s with
     * three pairs (below MIN_COHORT_SIZE=10, suppressed), 1800s with ten pairs
     * and three matches (30 %% rate), 1900s with ten pairs and five matches (50
     * %% rate). Every father is named "Johann"; sons either repeat the father's
     * name or carry a distinct "Different{n}" name.
     *
     * Locks the per-century rate computation, the cohort-floor suppression
     * policy (the 1700s century still takes an X-axis slot but its value drops
     * to zero with a "no data" tooltip), and the token comparison.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyComputesFatherSonRateAcrossCenturies(): void
    {
        $tree   = $this->importFixtureTree('father-son-name-passdown.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        self::assertSame(['18th cent.', '19th cent.', '20th cent.'], $result->categories, 'All three centuries appear chronologically');
        self::assertCount(2, $result->series, 'Two series: father → son and mother → daughter');

        $fatherSon = $result->series[0];
        self::assertSame('Father → son', $fatherSon->name);

        // 18th century: 3 pairs, sub-threshold → suppressed to 0.
        self::assertSame(0, $fatherSon->values[0], '1700s falls below MIN_COHORT_SIZE and reads zero');

        // 19th century: 10 pairs, 3 matches → 30 %.
        self::assertEqualsWithDelta(30.0, $fatherSon->values[1], 0.05, '1800s sits at 30 % match rate');

        // 20th century: 10 pairs, 5 matches → 50 %.
        self::assertEqualsWithDelta(50.0, $fatherSon->values[2], 0.05, '1900s sits at 50 % match rate');

        // Sub-threshold tooltip carries the "no data" caption so the
        // hover explains why the line dips to zero without leaving a
        // misleading 0 % suggestion.
        self::assertStringContainsString('no data', $fatherSon->tooltips[0]);

        // The fixture has no daughters at all so the mother → daughter
        // series is suppressed across every century.
        $motherDaughter = $result->series[1];
        self::assertSame('Mother → daughter', $motherDaughter->name);
        self::assertSame([0, 0, 0], $motherDaughter->values);
    }

    /**
     * BCE-born children fold into a negative century instead of being dropped.
     * name-passdown-bce.ged seeds 10 families whose sons all share the father's
     * given name and whose daughters all share the mother's, every child born
     * in the 1st century BCE — clearing MIN_COHORT_SIZE (10) for both series at
     * a 100 % match rate. A regression that re-introduces the `birth_year <= 0`
     * guard in `passdownPairsByCentury` would drop the whole cohort and return
     * an empty payload.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyBucketsBceBirthsIntoNegativeCenturies(): void
    {
        $tree   = $this->importFixtureTree('name-passdown-bce.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        self::assertSame([CenturyName::compactLabel(-1)], $result->categories);
        self::assertEqualsWithDelta(100.0, $result->series[0]->values[0], 0.05, 'Father → son: 10/10 match');
        self::assertEqualsWithDelta(100.0, $result->series[1]->values[0], 0.05, 'Mother → daughter: 10/10 match');
    }

    /**
     * The existing `name-trends.ged` fixture carries no FAMC links, so the
     * per-century passdown query yields zero parent-child pairs of either sex
     * pairing and the method short-circuits to an empty payload.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyIsEmptyWithoutParentChildLinks(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        self::assertSame([], $result->categories);
        self::assertSame([], $result->series);
    }

    /**
     * The passdown query deduplicates the two-row range encoding of a child's
     * birth. A son born `BET 1890 AND 1910` is stored as two `dates` rows — an
     * 1890 lower-bound (19th century) and a 1910 upper-bound (20th century).
     * Counting raw rows tallied the one father-son pair into both centuries,
     * inventing a phantom 20th-century x-axis slot. Collapsing the birth to its
     * lower-bound representative keeps the pair in the 19th century alone, so
     * only that single category survives. The match rate is sub-threshold here
     * (one pair, MIN_COHORT_SIZE is 10), so the category set — not the suppressed
     * value — is the contract the dedup must hold.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyCountsEachRangedBirthOnce(): void
    {
        $tree   = $this->importFixtureTree('passdown-century-dedup.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        self::assertSame(['19th cent.'], $result->categories, 'Ranged birth never spawns a phantom 20th-century slot');
    }

    /**
     * Multi-token match: a father named "Johann Friedrich" matches a son named
     * "Wilhelm Friedrich" because "Friedrich" appears in both names. Pins the
     * set-intersection semantics so a strict first-token regression would fail
     * this test.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyMatchesAnyOverlappingToken(): void
    {
        $tree   = $this->importFixtureTree('father-son-name-passdown-multi-token.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        // Fixture: 10 father-son pairs in the 1800s, 7 share at
        // least one token, no mother-daughter pairs.
        self::assertSame(['19th cent.'], $result->categories);
        self::assertCount(2, $result->series);

        $fatherSon = $result->series[0];
        self::assertSame('Father → son', $fatherSon->name);
        self::assertEqualsWithDelta(70.0, $fatherSon->values[0], 0.05);

        // Mother → daughter has zero pairs in this fixture.
        $motherDaughter = $result->series[1];
        self::assertSame(0, $motherDaughter->values[0]);
        self::assertStringContainsString('no data', $motherDaughter->tooltips[0]);
    }

    /**
     * A child without a Gregorian / Julian dated birth carries no birth century
     * and must be dropped from the passdown aggregation entirely — never crash
     * and never spawn a phantom category. The fixture pairs ten 1850-born sons
     * (all matching their father's name → a 100 %% 19th-century cohort) with one
     * extra father-son pair whose son has no `BIRT` date. The query no longer
     * inner-joins the birth subquery; the lower-bound year is resolved from the
     * deduplicated per-individual map and an absent xref is skipped.
     *
     * The undated son carries a *non-matching* given name (father "Wilhelm",
     * son "Friedrich") on purpose: it makes the 100 %% rate a discriminator for
     * the whole "silently include the undated child" regression class, not just
     * the array-key crash. Dropping the gate raises an undefined-key error;
     * defaulting the undated child into the 19th century instead would fold a
     * non-matching pair into the cohort and drop the rate to 10/11 ≈ 90.9 %,
     * tripping the delta assertion.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyDropsChildrenWithoutADatedBirth(): void
    {
        $tree   = $this->importFixtureTree('passdown-undated-child-excluded.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        self::assertSame(['19th cent.'], $result->categories, 'Undated child never spawns a phantom century slot');

        $fatherSon = $result->series[0];
        self::assertSame('Father → son', $fatherSon->name);
        self::assertEqualsWithDelta(
            100.0,
            $fatherSon->values[0],
            0.05,
            'Ten dated 19th-century sons all match; the non-matching undated son is excluded from the cohort'
        );
    }

    /**
     * Regression for the "common given names" leak (issue #75). A `1 NAME` with
     * a level-2 custom sub-tag (`2 _LAST 05 May 2001`) is indexed by core as a
     * separate `name` row whose `n_givn` is the literal sub-tag value. Core's
     * Top-N aggregation filters only `n_type <> '_MARNM'`, so the date string
     * tokenises into `05` / `May` / `2001` and pollutes the chart. The
     * whitelist (`NAME`, `ROMN`, `FONE`, `_HEB`) drops every arbitrary custom
     * tag and the `_AKA` alias, so none of those tokens may survive — while the
     * romanised (`ROMN`), phonetic (`FONE`) and Hebrew (`_HEB`) transliteration
     * variants still contribute the real name in their script.
     */
    #[Test]
    public function topGivenNamesExcludesCustomSubtagJunkButKeepsWhitelistedForms(): void
    {
        $tree   = $this->importFixtureTree('name-custom-subtag.ged');
        $labels = array_column($this->repository($tree)->topGivenNames(NameRepository::SEX_ALL, 1, 100), 'label');

        // _LAST date tokens must not leak in as "given names".
        self::assertNotContains('2001', $labels);
        self::assertNotContains('1999', $labels);
        self::assertNotContains('May', $labels);
        self::assertNotContains('Jun', $labels);
        self::assertNotContains('Jan', $labels);

        // _AKA is an arbitrary custom alias — excluded by the whitelist.
        self::assertNotContains('Aliasius', $labels);

        // Romanised / phonetic / Hebrew transliteration variants carry the
        // real name in another script — kept.
        self::assertContains('Romulus', $labels);
        self::assertContains('Phonetus', $labels);
        self::assertContains('Hebraicus', $labels);

        // Sanity: the primary NAME given names are still present.
        self::assertContains('John', $labels);
        self::assertContains('Greta', $labels);
    }

    /**
     * Surname counterpart of the whitelist fix. Neither the arbitrary custom
     * `_AKA` form nor the married-name `_MARNM` form contributes a surname:
     * `_AKA` is junk, and `_MARNM` is excluded so surnames stay counted by
     * primary (birth) name exactly as webtrees core does. Only the primary
     * `NAME` surname of each individual survives.
     */
    #[Test]
    public function topSurnamesKeepsOnlyPrimaryNameSurnames(): void
    {
        $tree   = $this->importFixtureTree('name-custom-subtag.ged');
        $labels = array_column($this->repository($tree)->topSurnames(100, 1), 'label');

        // _AKA surname (arbitrary custom) is excluded.
        self::assertNotContains('Aliasson', $labels);

        // _MARNM married name is excluded — core counts surnames by birth name.
        self::assertNotContains('Married', $labels);

        // Primary NAME surnames remain, including the maiden name behind the
        // _MARNM record.
        self::assertContains('Maiden', $labels);
        self::assertContains('Ditchi', $labels);
        self::assertContains('Smith', $labels);
    }

    /**
     * The distinct-given-name headline must stay in lockstep with the Top-N
     * list: counting the same whitelisted, tokenised set. With the junk
     * excluded the fixture exposes a bounded set of real given-name tokens, so
     * the count is finite and excludes the date fragments.
     */
    #[Test]
    public function countDistinctGivenNamesIgnoresCustomSubtagJunk(): void
    {
        $tree = $this->importFixtureTree('name-custom-subtag.ged');
        $repo = $this->repository($tree);

        // Distinct male given names: John, Peter, Edgar, Fred, Henry, Isaac,
        // Romulus (ROMN), Phonetus (FONE), Hebraicus (_HEB). @P.N. is excluded;
        // the _LAST date tokens and the _AKA alias are dropped.
        self::assertSame(9, $repo->countDistinctGivenNames(Sex::Male->value));
    }

    /**
     * Deterministic tie-break when the limit caps the Top-N list. Every
     * whitelisted given-name token in the fixture occurs exactly once, so all
     * counts tie; which tokens survive a sub-pool limit is therefore decided
     * solely by the tie-break. The aggregation orders its source rows by
     * `n_givn` and relies on PHP's stable sort, so the survivors must be the
     * tokens from the alphabetically-first `n_givn` rows — `Edgar`, `Fred`,
     * `Greta`. Without the deterministic ordering the survivors would follow
     * arbitrary database row order, so this pins the contract that the
     * `ORDER BY n_givn` tie-break exists to guarantee.
     */
    #[Test]
    public function topGivenNamesBreaksEqualCountTiesDeterministicallyUnderLimit(): void
    {
        $tree   = $this->importFixtureTree('name-custom-subtag.ged');
        $labels = array_column($this->repository($tree)->topGivenNames(NameRepository::SEX_ALL, 1, 3), 'label');

        // Limit honoured: exactly three distinct survivors.
        self::assertCount(3, $labels);
        self::assertSame($labels, array_unique($labels));

        // All tokens tie at one occurrence, so the n_givn-ascending tie-break
        // decides: the three lowest n_givn rows yield Edgar / Fred / Greta.
        self::assertSame(['Edgar', 'Fred', 'Greta'], $labels);
    }
}
