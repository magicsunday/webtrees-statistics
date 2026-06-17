<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Enum\Sex;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Repository\NameRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GivenNameNormalizer;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_column;
use function array_unique;
use function count;

use const PHP_INT_MAX;

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
#[UsesClass(GivenNameNormalizer::class)]
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
     * Six distinct given names appear in the fixture (Anna, Friedrich, Maria,
     * Hans, Lisa, plus the sex-`U` 12th individual's "Unknown"), split across
     * sexes. Female: Anna×3, Maria×2, Lisa×1 → 3 distinct. Male: Friedrich×2,
     * Hans×3 → 2 distinct. The 12th individual carries sex `U`, so it contributes
     * to the all-sexes count but to neither the female nor the male count.
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
     * threshold, with the boundary INCLUSIVE (`count >= threshold`). Across all
     * sexes the fixture carries six distinct given names — Anna (×3), Friedrich
     * (×2), Maria (×2), Hans (×3), Lisa (×1) and the sex-`U` individual's
     * "Unknown" (×1) — of which exactly two, Anna and Hans, reach an occurrence
     * of three. Pinning both exact values guards the inclusive boundary: a
     * regression to a strict `count > threshold` would drop the two names
     * sitting exactly on the threshold and yield 0, not 2.
     */
    #[Test]
    public function countDistinctGivenNamesRespectsThreshold(): void
    {
        $tree = $this->importFixtureTree('name-trends.ged');
        $repo = $this->repository($tree);

        $unbounded = $repo->countDistinctGivenNames(NameRepository::SEX_ALL, 1);
        $threeOnly = $repo->countDistinctGivenNames(NameRepository::SEX_ALL, 3);

        self::assertSame(6, $unbounded, 'All six distinct given names clear threshold 1');
        self::assertSame(2, $threeOnly, 'Only Anna (×3) and Hans (×3) reach the inclusive threshold 3');
    }

    /**
     * The distinct-count card and the Top-N card for the SAME sex must fold the
     * underlying `name` rows exactly once between them, not once per card
     * (GH-154). The fold (the SQL scan plus the per-token tokenise) depends only
     * on the sex filter; the threshold and limit are applied afterwards, so a
     * second card for the same sex must reuse the memoised fold rather than
     * re-scanning. The query count is the discriminator: before the shared fold
     * the two calls issued two scans, after it a single one.
     */
    #[Test]
    public function givenNameCountAndTopShareOneFoldPerSex(): void
    {
        $tree = $this->importFixtureTree('name-trends.ged');
        $repo = $this->repository($tree);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $repo->countDistinctGivenNames(Sex::Male->value);
        $repo->topGivenNames(Sex::Male->value, 1, 15);

        $queryCount = count(DB::connection()->getQueryLog());

        DB::connection()->disableQueryLog();

        self::assertSame(
            1,
            $queryCount,
            'Count and Top for one sex must share a single name-row fold, not scan once per card',
        );
    }

    /**
     * The headline distinct-given-name count must stay in lockstep with the
     * Top-N aggregation it summarises: at a given threshold the count equals the
     * number of distinct Top-N entries the same threshold yields (GH-154 splits
     * the count off the shared fold, so this pins that the two derive the same
     * key set). Asserted symmetrically for both sexes so neither the male nor the
     * female card can drift.
     */
    #[Test]
    public function givenNameCountMatchesTopNCardinalityForBothSexes(): void
    {
        $tree = $this->importFixtureTree('name-trends.ged');
        $repo = $this->repository($tree);

        foreach ([Sex::Male->value, Sex::Female->value, NameRepository::SEX_ALL] as $sex) {
            $count = $repo->countDistinctGivenNames($sex, 1);
            $top   = $repo->topGivenNames($sex, 1, PHP_INT_MAX);

            self::assertCount(
                $count,
                $top,
                'Distinct count must equal the unbounded Top-N cardinality for sex ' . $sex,
            );
        }
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
     * A parent carrying a second top-level `1 NAME` line must not inflate the
     * passdown cohort. webtrees stores both `1 NAME` lines as `name` rows with
     * `n_type = 'NAME'` (differing only in `n_num`), so the parent join must pin
     * the primary name via `n_num = 0`; an `n_type` filter alone keeps both rows
     * and fans the parent-child pair into two cohort entries — one matching, one
     * not — skewing the rate.
     *
     * The fixture has ten father → son pairs in the 1850s, all named "Otto"
     * (a perfect 100 % pass-down). The first father also carries a second
     * `1 NAME Zzz` whose given name does NOT match the son. With the primary-name
     * pin the rate stays 100 % (10 matches / 10 pairs); without it the extra
     * non-matching row makes it 10 / 11 ≈ 90.9 %.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyIgnoresAParentsSecondNameLine(): void
    {
        $tree   = $this->importFixtureTree('passdown-parent-two-names.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        self::assertSame(['19th cent.'], $result->categories);

        $fatherSon = $result->series[0];
        self::assertSame('Father → son', $fatherSon->name);
        self::assertEqualsWithDelta(
            100.0,
            $fatherSon->values[0],
            0.05,
            "Ten matching pairs read 100 %; the first father's second NAME line must not add an 11th, non-matching cohort entry",
        );
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
     * A parent or child whose primary `NAME` carries no given name
     * (`1 NAME /Last/`) is NOT stored with an empty `n_givn`: webtrees
     * substitutes the whole-value placeholder `@P.N.`
     * ({@see \Fisharebest\Webtrees\Individual::PRAENOMEN_NESCIO}). `givenNameTokens()`
     * collapses that placeholder to an empty token set, so the two `continue`
     * guards in `passdownPairsByCentury()` drop the pair instead of folding an
     * unknown name into the cohort. Both guards run *after* the dated-birth gate,
     * so every discriminator child carries an 1850 birth — proving the drop is the
     * empty-token guard, not a missing date.
     *
     * The fixture seeds ten dated father-son pairs that all share the name
     * "Johann" → a clean 100 %% 19th-century cohort, plus three extra dated pairs
     * exercising every failure mode of the placeholder bug:
     *
     * - empty *father* given name → the parent guard must drop it (else 10/11);
     * - empty *son* given name → the child guard must drop it (else 10/11);
     * - empty *both* → without the placeholder fix `@P.N.` matches `@P.N.`, a
     *   phantom MATCH that *inflates* the rate (11/13 ≈ 84.6 %).
     *
     * With the placeholder collapsed all three pairs drop and the cohort stays
     * 10/10 = 100 %. Treating `@P.N.` as a real token instead lands anywhere from
     * 84.6 % to 76.9 %, every variant tripping the delta assertion.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyDropsPairsWithEmptyGivenName(): void
    {
        $tree   = $this->importFixtureTree('passdown-empty-given-name-excluded.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        self::assertSame(['19th cent.'], $result->categories, 'Empty-given-name pairs never spawn a phantom century slot');

        $fatherSon = $result->series[0];
        self::assertSame('Father → son', $fatherSon->name);
        self::assertEqualsWithDelta(
            100.0,
            $fatherSon->values[0],
            0.05,
            'Ten matching dated pairs form the cohort; the unknown-given-name (@P.N.) father, son and both-empty pairs are all dropped'
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
     * solely by the tie-break. The aggregation delegates the ordering to the
     * shared {@see TopNAggregator::rankKeys()} (count descending, then fold key
     * ascending in PHP byte order), so the survivors must be the three
     * byte-order-lowest fold keys — `Edgar`, `Fred`, `Greta`. Without the
     * deterministic tie-break the survivors would follow the arbitrary database
     * row order, so this pins the contract that `rankKeys()` guarantees.
     */
    #[Test]
    public function topGivenNamesBreaksEqualCountTiesDeterministicallyUnderLimit(): void
    {
        $tree   = $this->importFixtureTree('name-custom-subtag.ged');
        $labels = array_column($this->repository($tree)->topGivenNames(NameRepository::SEX_ALL, 1, 3), 'label');

        // Limit honoured: exactly three distinct survivors.
        self::assertCount(3, $labels);
        self::assertSame($labels, array_unique($labels));

        // All tokens tie at one occurrence, so the fold-key-ascending tie-break
        // in rankKeys() decides: the three byte-order-lowest fold keys yield
        // Edgar / Fred / Greta.
        self::assertSame(['Edgar', 'Fred', 'Greta'], $labels);
    }

    /**
     * Spelling variants that differ only by diacritics or case fold into one
     * given-name entry whose count is the sum across variants, labelled with the
     * most frequent raw spelling. The fixture pairs José (×2) + Jose (×1) and
     * Sofia (×2) + Sofía (×1) with the unique control name Wilhelm, so a folded
     * aggregation yields three entries (José=3, Sofia=3, Wilhelm=1) while the
     * old raw-grouping would leak five (José=2, Jose=1, Sofia=2, Sofía=1,
     * Wilhelm=1).
     */
    #[Test]
    public function topGivenNamesFoldsSpellingVariantsAndLabelsWithDominantForm(): void
    {
        $tree    = $this->importFixtureTree('given-name-fold.ged');
        $entries = $this->repository($tree)->topGivenNames(NameRepository::SEX_ALL, 1, 100);
        $labels  = array_column($entries, 'label');

        // The dominant raw spelling becomes the label; the folded-away variants
        // never surface as their own entry.
        self::assertContains('José', $labels);
        self::assertContains('Sofia', $labels);
        self::assertContains('Wilhelm', $labels);
        self::assertNotContains('Jose', $labels);
        self::assertNotContains('Sofía', $labels);

        // The label's count is the sum across the folded variants.
        $byLabel = array_column($entries, 'value', 'label');
        self::assertSame(3, $byLabel['José']);
        self::assertSame(3, $byLabel['Sofia']);
        self::assertSame(1, $byLabel['Wilhelm']);
    }

    /**
     * The distinct-count uses the same folded aggregation, so the five raw
     * spellings in the fixture collapse to three distinct given names.
     */
    #[Test]
    public function countDistinctGivenNamesCountsFoldedVariantsOnce(): void
    {
        $tree = $this->importFixtureTree('given-name-fold.ged');

        self::assertSame(3, $this->repository($tree)->countDistinctGivenNames(NameRepository::SEX_ALL));
    }

    /**
     * The Top-N limit slice breaks equal-count ties on the fold key in PHP byte
     * order, independent of the database row order (which collates differently
     * across SQLite and MySQL). The fixture has three count-1 names in rowid
     * order Charlie, Alice, Bob; with limit 2 the byte-order-lowest two —
     * Alice, Bob — must survive, never the rowid-first Charlie. A regression to
     * a row-order-dependent slice would let Charlie through on one engine.
     */
    #[Test]
    public function topGivenNamesBreaksEqualCountTiesByLabelNotRowOrder(): void
    {
        $tree   = $this->importFixtureTree('given-name-tiebreak.ged');
        $labels = array_column(
            $this->repository($tree)->topGivenNames(NameRepository::SEX_ALL, 1, 2),
            'label',
        );

        self::assertSame(['Alice', 'Bob'], $labels);
    }

    /**
     * The boundary tie is broken on the FOLD KEY, not on the resolved display
     * label. The fixture has Anna (×2) plus the count-1 pair Zoé and Zoia, whose
     * dominant display spellings sort opposite to their fold keys: fold keys
     * `zoe` < `zoia` keep "Zoé", but the display labels "Zoia" < "Zoé" would keep
     * "Zoia". At Top-2 the survivors are {Anna, Zoé} — proving the cut is decided
     * by the shared {@see TopNAggregator::rankKeys()} fold-key byte order, not by
     * the display label (the former display-label tie-break would have yielded
     * {Anna, Zoia}). Unlike the ASCII fixtures above, this genuinely
     * discriminates the two tie-break bases.
     */
    #[Test]
    public function topGivenNamesBreaksBoundaryTiesOnFoldKeyNotDisplayLabel(): void
    {
        $tree   = $this->importFixtureTree('given-name-fold-tiebreak.ged');
        $labels = array_column(
            $this->repository($tree)->topGivenNames(NameRepository::SEX_ALL, 1, 2),
            'label',
        );

        self::assertSame(['Anna', 'Zoé'], $labels);
    }

    /**
     * An individual is counted once per fold key even when the same name is
     * recorded in several forms (primary NAME + a ROMN/FONE transliteration that
     * Latin-folds onto it). The fixture: I1 = NAME "José" + ROMN "Jose", I2 =
     * NAME "José", I3 = NAME "Wilhelm". Two distinct individuals carry fold key
     * "jose", so the bubble value is 2 — not 3, which a naive sum of the
     * per-n_givn COUNT(DISTINCT n_id) over the folded keys would yield.
     */
    #[Test]
    public function topGivenNamesCountsAnIndividualOncePerFoldKeyAcrossNameForms(): void
    {
        $tree    = $this->importFixtureTree('given-name-transliteration.ged');
        $byLabel = array_column(
            $this->repository($tree)->topGivenNames(NameRepository::SEX_ALL, 1, 100),
            'value',
            'label',
        );

        self::assertSame(2, $byLabel['José']);
        self::assertSame(1, $byLabel['Wilhelm']);
        self::assertArrayNotHasKey('Jose', $byLabel);
    }

    /**
     * The passdown token comparison folds spelling variants too: ten
     * 19th-century families each pair a father "José" with a son "Jose". The
     * diacritic variant folds to the same name, so all ten count as a passdown
     * (100 %). Without folding the tokens differ and the rate would read 0 %.
     */
    #[Test]
    public function sameSexNamePassdownByCenturyMatchesAcrossSpellingVariants(): void
    {
        $tree   = $this->importFixtureTree('passdown-spelling-variant.ged');
        $result = $this->repository($tree)->sameSexNamePassdownByCentury();

        self::assertSame(['19th cent.'], $result->categories);

        $fatherSon = $result->series[0];
        self::assertSame('Father → son', $fatherSon->name);
        self::assertEqualsWithDelta(100.0, $fatherSon->values[0], 0.05);
    }
}
