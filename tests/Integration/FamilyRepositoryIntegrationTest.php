<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Auth;
use MagicSunday\Webtrees\Statistic\Enum\MaritalBucket;
use MagicSunday\Webtrees\Statistic\Model\Family\SexRatioAnomaly;
use MagicSunday\Webtrees\Statistic\Model\Family\SiblingDeathCluster;
use MagicSunday\Webtrees\Statistic\Model\FamilyRow;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\ChunkedWhereIn;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;

/**
 * End-to-end test of the marital-status classifier against a curated GEDCOM
 * fixture loaded into an in-memory SQLite database. Mirrors what webtrees core
 * does in its own TestCase: bootstrap the schema, import the records, then
 * exercise the repository against the real tables.
 *
 * The fixture contains the four marital buckets at their boundaries: a living
 * couple (current × 2), a widow with deceased spouse (widowed × 1), a divorced
 * couple (divorced × 2), and two unpartnered individuals (single × 1 living,
 * single × 1 deceased).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(FamilyRepository::class)]
#[UsesClass(FamilyRow::class)]
#[UsesClass(SexRatioAnomaly::class)]
#[UsesClass(SiblingDeathCluster::class)]
#[UsesClass(ChunkedWhereIn::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(GedcomScanner::class)]
#[UsesClass(RowCast::class)]
final class FamilyRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Sum of the four buckets must equal the living count, the canonical
     * invariant the classifier promises.
     */
    #[Test]
    public function classifyLivingIndividualsMatchesTheCuratedFixture(): void
    {
        $tree    = $this->importFixtureTree('marital-status.ged');
        $buckets = (new FamilyRepository($tree))->classifyLivingIndividuals();

        self::assertSame(
            [
                MaritalBucket::Current->value  => 2,
                MaritalBucket::Divorced->value => 2,
                MaritalBucket::Widowed->value  => 1,
                MaritalBucket::Single->value   => 1,
            ],
            $buckets,
            'Four bucket counts must match the curated fixture',
        );
    }

    /**
     * A remarried individual appears in two FAMS families — one divorced, one
     * current — so the individual→families join must fan out to BOTH rows for
     * that person before the classifier applies its current > divorced
     * precedence. Guards the link-table join (which replaced an O(n×m) OR-join
     * for #82): a join that collapsed the multi-FAMS fan-out to one row would
     * silently misclassify the remarried individual.
     */
    #[Test]
    public function classifyLivingIndividualsFansOutAcrossMultipleFamsFamilies(): void
    {
        $tree    = $this->importFixtureTree('marital-status-remarried.ged');
        $buckets = (new FamilyRepository($tree))->classifyLivingIndividuals();

        self::assertSame(
            [
                // I1 (remarried: divorced F1 + current F2 → current wins) and
                // I3 (current second wife) are Current; I2 (divorced first
                // wife) is Divorced; nobody is widowed or single.
                MaritalBucket::Current->value  => 2,
                MaritalBucket::Divorced->value => 1,
                MaritalBucket::Widowed->value  => 0,
                MaritalBucket::Single->value   => 0,
            ],
            $buckets,
            'The remarried individual must classify as Current via the multi-FAMS fan-out',
        );
    }

    /**
     * Map the anomaly list to `[xref, sons, daughters]` triples so the order and
     * counts can be asserted without depending on the resolved display label.
     *
     * @param list<SexRatioAnomaly> $anomalies
     *
     * @return list<array{string, int, int}>
     */
    private function triples(array $anomalies): array
    {
        return array_map(
            static fn (SexRatioAnomaly $a): array => [$a->familyXref, $a->sons, $a->daughters],
            $anomalies,
        );
    }

    /**
     * With the defaults (≥ 6 children, ≥ 80 % one sex), the fixture qualifies
     * four families: F1 (7 sons / 0), F2 (0 / 8 daughters), F3 (6 sons / 1
     * daughter → 6 of 7 ≈ 85.7 %) and F6 (5 sons / 1 daughter → 5 of 6 ≈ 83.3 %,
     * which only clears the gate because the threshold is 80 % — at 85 % a
     * six-child family could never qualify below 6 of 6). F4 (4 / 4) fails the
     * skew gate and F5 (4 / 0) the child-count gate. F3 carries an eighth child
     * with no SEX, which must be excluded — otherwise its ratio would be 6 of 8
     * = 75 % and it would drop out. The result sorts by skew descending, then by
     * child count descending: F1 and F2 tie at 100 %, so F2 (8 children) leads F1
     * (7), then F3 (85.7 %), then F6 (83.3 %).
     */
    #[Test]
    public function getSexRatioAnomaliesAppliesTheDefaultThresholdsAndSort(): void
    {
        $tree   = $this->importFixtureTree('sex-ratio-anomalies.ged');
        $result = (new FamilyRepository($tree))->getSexRatioAnomalies();

        self::assertSame(
            [
                ['F2', 0, 8],
                ['F1', 7, 0],
                ['F3', 6, 1],
                ['F6', 5, 1],
            ],
            $this->triples($result),
        );

        self::assertNotSame('', $result[0]->label, 'The display label must resolve to the couple, not an empty string');
    }

    /**
     * Lowering the minimum child count to four pulls F5 (4 sons / 0, 100 %) into
     * the list while the balanced F4 (4 / 4) still fails the skew gate. Sorted by
     * skew descending then child count descending: the three 100 % families
     * F2 (8), F1 (7) and F5 (4), then F3 (85.7 %, 7), then F6 (83.3 %, 6).
     */
    #[Test]
    public function getSexRatioAnomaliesHonoursAConfigurableMinimumChildCount(): void
    {
        $tree   = $this->importFixtureTree('sex-ratio-anomalies.ged');
        $result = (new FamilyRepository($tree))->getSexRatioAnomalies(4);

        self::assertSame(
            [
                ['F2', 0, 8],
                ['F1', 7, 0],
                ['F5', 4, 0],
                ['F3', 6, 1],
                ['F6', 5, 1],
            ],
            $this->triples($result),
        );
    }

    /**
     * The limit caps the result to the top-N most skewed families.
     */
    #[Test]
    public function getSexRatioAnomaliesCapsToTheLimit(): void
    {
        $tree   = $this->importFixtureTree('sex-ratio-anomalies.ged');
        $result = (new FamilyRepository($tree))->getSexRatioAnomalies(7, 0.85, 2);

        self::assertSame(
            [
                ['F2', 0, 8],
                ['F1', 7, 0],
            ],
            $this->triples($result),
        );
    }

    /**
     * Ranked extremes follow the project's raw-rank convention: a family is
     * ranked from its raw counts and shown with a name-privatised label — not
     * dropped — exactly like the sibling "largest families" card.
     * `sex-ratio-living.ged` has one living family (six living sons, no
     * daughters, surname "Living") that clears the gates. To the importing admin
     * the label is the real couple name.
     *
     * (The visitor side is a separate test: Family::fullName() memoises its
     * rendered string on the cached record, so resolving once as admin would
     * leak the real name into a later same-process visitor resolution — the two
     * privacy universes must each start from a fresh import.)
     */
    #[Test]
    public function getSexRatioAnomaliesShowsTheRealLabelToTheAdmin(): void
    {
        $tree = $this->importFixtureTree('sex-ratio-living.ged');

        $result = (new FamilyRepository($tree))->getSexRatioAnomalies();

        self::assertCount(1, $result, 'the living skewed family is an anomaly');
        self::assertSame('F1', $result[0]->familyXref);
        self::assertSame(6, $result[0]->sons);
        self::assertSame(0, $result[0]->daughters);
        self::assertStringContainsString('Living', $result[0]->label, 'admin sees the real surname');
    }

    /**
     * Same fixture, anonymous visitor with live people hidden: the family is
     * still ranked and shown with its raw 6/0 split, but the label is privatised
     * (the real surname absent) — NOT dropped. Resolved fresh as the visitor so
     * no admin-computed `fullName()` is cached. Resolving only the Top-N keeps
     * this off the N+1 path (GH-186).
     */
    #[Test]
    public function getSexRatioAnomaliesShowsAHiddenFamilyWithAPrivatisedLabel(): void
    {
        $tree = $this->importFixtureTree('sex-ratio-living.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        Auth::logout();

        $result = (new FamilyRepository($tree))->getSexRatioAnomalies();

        self::assertCount(1, $result, 'the family is ranked + shown, not dropped');
        self::assertSame('F1', $result[0]->familyXref);
        self::assertSame(6, $result[0]->sons, 'raw split preserved');
        self::assertSame(0, $result[0]->daughters);
        self::assertNotSame('', $result[0]->label, 'a privatised placeholder label is still rendered');
        self::assertStringNotContainsString('Living', $result[0]->label, 'the real surname is privatised for the visitor');
    }

    /**
     * Map the cluster list to `[year, siblings, families]` triples so the
     * order and counts can be asserted directly.
     *
     * @param list<SiblingDeathCluster> $clusters
     *
     * @return list<array{int, int, int}>
     */
    private function clusterTriples(array $clusters): array
    {
        return array_map(
            static fn (SiblingDeathCluster $cluster): array => [$cluster->year, $cluster->siblings, $cluster->families],
            $clusters,
        );
    }

    /**
     * The metric is a cross-family epidemic indicator: a year qualifies only when
     * at least two distinct families each lost at least two children that year
     * (the per-family floor strips single losses that are not a sibling cluster).
     * With the default minimum of two families the fixture yields three years in
     * ascending order:
     *
     *  - 1850: family F1 (three children — its third recorded as an imprecise
     *    "between Jan and Dec 1850" range that webtrees stores as two same-year
     *    rows, so resolving each child to one death year keeps F1 at three) and
     *    family F2 (two children) → two families, five siblings.
     *  - 1866: families F3 (two), F4 (two) and F5 (three) → three families,
     *    seven siblings.
     *  - 1879: family F8 (two children, one recorded "between 1879 and 1880" —
     *    that range crosses a year boundary, so webtrees stores two rows in
     *    *different* years; resolving the child to its earliest year counts it
     *    once in 1879 and never leaks a phantom 1880) and family F9 (two
     *    children) → two families, four siblings.
     *
     * 1880 must NOT qualify: only family F7 lost two children there, while family
     * F6's single 1880 child stays below the per-family floor, so just one family
     * clears it — below the two-family requirement. That row guards both the
     * per-family floor and the cross-family gate at once.
     */
    #[Test]
    public function getSiblingDeathClustersAggregatesYearsWhereMultipleFamiliesLostChildren(): void
    {
        $tree   = $this->importFixtureTree('sibling-death-clusters.ged');
        $result = (new FamilyRepository($tree))->getSiblingDeathClusters();

        self::assertSame(
            [
                [1850, 5, 2],
                [1866, 7, 3],
                [1879, 4, 2],
            ],
            $this->clusterTriples($result),
        );
    }

    /**
     * Raising the minimum to three families drops the two-family years 1850 and
     * 1879; only 1866 — where families F3, F4 and F5 each lost at least two
     * children — still qualifies.
     */
    #[Test]
    public function getSiblingDeathClustersHonoursAConfigurableMinimumFamilyCount(): void
    {
        $tree   = $this->importFixtureTree('sibling-death-clusters.ged');
        $result = (new FamilyRepository($tree))->getSiblingDeathClusters(3);

        self::assertSame(
            [
                [1866, 7, 3],
            ],
            $this->clusterTriples($result),
        );
    }

    /**
     * A tree with no qualifying cross-family cluster year yields an empty list.
     */
    #[Test]
    public function getSiblingDeathClustersReturnsEmptyWhenNoClusterQualifies(): void
    {
        $tree = $this->importFixtureTree('empty-tree.ged');

        self::assertSame([], (new FamilyRepository($tree))->getSiblingDeathClusters());
    }
}
