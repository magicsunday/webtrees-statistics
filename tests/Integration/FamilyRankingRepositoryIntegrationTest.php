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
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Ranking\RankingEntry;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRankingRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;
use function count;

/**
 * Integration tests for {@see FamilyRankingRepository} — the top-N largest
 * families and top-N grandchild rankings. `topLargestFamilies` rides on
 * `children.ged`; the grandchild ranking rides on `grandchild-families.ged`,
 * whose family layout is documented per test.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(FamilyRankingRepository::class)]
#[UsesClass(RankingEntry::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class FamilyRankingRepositoryIntegrationTest extends AbstractIntegrationTestCase
{
    private function repository(Tree $tree): FamilyRankingRepository
    {
        return new FamilyRankingRepository(
            $tree,
            $this->statisticsData($tree),
        );
    }

    /**
     * Top-N largest families list F1 first (3 children); F2 with 0 children
     * should still appear (the accessor sorts descending by child count, not
     * "only those > 0").
     */
    #[Test]
    public function topLargestFamiliesRanksByChildCount(): void
    {
        $tree   = $this->importFixtureTree('children.ged');
        $result = $this->repository($tree)->topLargestFamilies(10);

        // Two families in the fixture.
        self::assertCount(2, $result);
        // F1 wins with 3 children → first entry carries its XREF and count.
        self::assertSame('F1', $result[0]->xref);
        self::assertSame(3, $result[0]->value);
        // F2 with zero children still appears as a distinct row, not filtered out.
        self::assertSame('F2', $result[1]->xref);
        self::assertSame(0, $result[1]->value);
    }

    /**
     * Top-N families by grandchildren — the children of the family's own
     * children — matching webtrees core's `topTenGrandFamily`. The fixture has
     * three grandparent families with distinct counts whose ranking is
     * deliberately NOT in XREF order, so a result sorted by f_id instead of by
     * grandchild count would fail: G1 (children C1 + C2 with 3 + 2 children of
     * their own → 5), G2 (child C3 with 6 → 6), G3 (child C4 with 1 → 1). The
     * descending-count ranking is therefore G2, G1, G3 while the XREF order is
     * G1, G2, G3. A fourth family G4 has a child C5 who marries but stays
     * childless, so G4 has zero grandchildren and never surfaces (see
     * {@see topGrandchildFamiliesExcludesFamiliesWithoutGrandchildren}).
     */
    #[Test]
    public function topGrandchildFamiliesRanksByGrandchildCount(): void
    {
        $tree   = $this->importFixtureTree('grandchild-families.ged');
        $result = $this->repository($tree)->topGrandchildFamilies(10);

        self::assertCount(3, $result);
        self::assertSame('G2', $result[0]->xref);
        self::assertSame(6, $result[0]->value);
        self::assertSame('G1', $result[1]->xref);
        self::assertSame(5, $result[1]->value);
        self::assertSame('G3', $result[2]->xref);
        self::assertSame(1, $result[2]->value);
    }

    /**
     * The `$limit` caps the result to the highest-ranked families. With the
     * fixture's three qualifying families ranked 6 / 5 / 1, a limit of two keeps
     * G2 and G1 (the two highest by grandchild count) and drops G3 — proving the
     * cap is applied after the descending COUNT order, not in XREF order (which
     * would wrongly keep G1, G2).
     */
    #[Test]
    public function topGrandchildFamiliesRespectsTheLimit(): void
    {
        $tree   = $this->importFixtureTree('grandchild-families.ged');
        $result = $this->repository($tree)->topGrandchildFamilies(2);

        self::assertCount(2, $result);
        self::assertSame('G2', $result[0]->xref);
        self::assertSame('G1', $result[1]->xref);
    }

    /**
     * A grandparent family whose only child marries but stays childless (G4 /
     * child C5 / childless spouse-family C5F) contributes zero grandchildren and
     * must not appear at all — the families → CHIL → FAMS → CHIL inner join
     * yields no row for it. Guards against an accidental switch to a LEFT join
     * that would surface such families with a count of zero.
     */
    #[Test]
    public function topGrandchildFamiliesExcludesFamiliesWithoutGrandchildren(): void
    {
        $tree   = $this->importFixtureTree('grandchild-families.ged');
        $result = $this->repository($tree)->topGrandchildFamilies(10);

        // A LEFT-join regression would surface G4 as a fourth, count-zero row,
        // so anchor both the cardinality and the specific absence.
        self::assertCount(3, $result);

        $xrefs = array_map(static fn (RankingEntry $entry): string => $entry->xref, $result);

        self::assertNotContains('G4', $xrefs);
    }

    /**
     * The grandchild count must come from a single grouped aggregation, not from
     * a per-family record-layer re-count (children → spouse families →
     * grandchildren), which issued one deep traversal per candidate family and
     * scaled with the grandchild population (GH-115).
     *
     * Rather than a loose absolute bound (which both lets a partial per-family
     * re-count slip under it and flakes on incidental upstream query-count
     * shifts), this pins the contract by scaling-independence — the same shape as
     * {@see GenerationDepthRepositoryIntegrationTest::summaryResolvesChainWithoutPerMemberQueries}.
     * The sparse and dense fixtures hold the SAME four grandparent families, but
     * the dense one packs ten extra grandchildren under the `/Two/` family.
     * `topGrandchildFamilies(10)` returns the same number of families from each,
     * so the record-resolution cost is identical; only a per-grandchild
     * traversal would make the dense fixture issue more queries.
     */
    #[Test]
    public function topGrandchildFamiliesQueryCountIsIndependentOfGrandchildPopulation(): void
    {
        $sparse = $this->countTopGrandchildFamilyQueries('grandchild-families.ged');
        $dense  = $this->countTopGrandchildFamilyQueries('grandchild-families-dense.ged');

        // Precondition: the same number of grandparent families rank in both, so
        // a query-count difference can only come from the grandchild population.
        self::assertSame(
            $sparse['families'],
            $dense['families'],
            'both fixtures must rank the same number of grandparent families',
        );
        self::assertGreaterThan(0, $sparse['families'], 'the ranking must actually return families');

        self::assertSame(
            $sparse['queries'],
            $dense['queries'],
            'Query count must not grow with the grandchild population (no per-family re-count traversal)',
        );
    }

    /**
     * Run `topGrandchildFamilies(10)` against a fixture and return the number of
     * ranked families and the SQL query count it cost.
     *
     * @return array{families: int, queries: int}
     */
    private function countTopGrandchildFamilyQueries(string $fixture): array
    {
        $tree = $this->importFixtureTree($fixture);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $result     = $this->repository($tree)->topGrandchildFamilies(10);
        $queryCount = count(DB::connection()->getQueryLog());

        DB::connection()->disableQueryLog();

        return ['families' => count($result), 'queries' => $queryCount];
    }

    /**
     * When two families share the same grandchild count and the limit cuts
     * through the tie, the deterministic `f_id ASC` tie-break keeps the
     * lexicographically smaller XREF so the capped Top-N is stable across runs.
     * The fixture declares family FB before FA, both with exactly two
     * grandchildren, and a limit of one cuts the tie; the ASC ordering keeps FA.
     *
     * Note on engine coverage: this asserts the tie-break DIRECTION (flipping it
     * to `f_id DESC` surfaces FB and fails here). It cannot prove a fully removed
     * tie-break on the SQLite test engine, whose `GROUP BY` happens to emit rows
     * in grouping-key (`f_id`) order; on MySQL an absent `ORDER BY f_id` is the
     * real non-determinism the production clause guards against.
     */
    #[Test]
    public function topGrandchildFamiliesBreaksCountTiesByXref(): void
    {
        $tree   = $this->importFixtureTree('grandchild-families-tie.ged');
        $result = $this->repository($tree)->topGrandchildFamilies(1);

        self::assertCount(1, $result);
        self::assertSame('FA', $result[0]->xref, 'The f_id ASC tie-break keeps the smaller XREF despite FB being declared first');
        self::assertSame(2, $result[0]->value);
    }

    /**
     * The displayed grandchild figure is the raw grandchild-link count, not a
     * privacy-filtered record-layer re-count. The fixture's grandparent family
     * GP reaches its three grandchildren through one intermediate spouse-family
     * CF that is marked `1 RESN confidential`. With privacy enabled and private
     * relationships hidden, the old per-family re-count walked
     * `Individual::spouseFamilies()`, which drops CF for an anonymous visitor
     * (`Family::canShow()` is false), so it would have reported zero
     * grandchildren. The raw link `COUNT(*)` is privacy-blind and reports three
     * under both the importing admin and the visitor — asserting that pins the
     * module's raw-rank stance and guards against a regression that
     * re-introduces privacy filtering into the count.
     */
    #[Test]
    public function topGrandchildFamiliesCountsRawAndIgnoresGrandchildPrivacy(): void
    {
        $tree = $this->importFixtureTree('grandchild-families-privacy.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);
        // Without this the relationship walk bypasses privacy (access level
        // PRIV_HIDE), so the confidential spouse-family would stay visible and
        // the record-layer/raw divergence could not be exercised.
        $tree->setPreference('SHOW_PRIVATE_RELATIONSHIPS', '0');

        // Control: as the importing admin everything is visible, so the raw
        // count and a privacy-filtered re-count would both read three.
        $asAdmin = $this->repository($tree)->topGrandchildFamilies(1);

        self::assertCount(1, $asAdmin);
        self::assertSame('GP', $asAdmin[0]->xref);
        self::assertSame(3, $asAdmin[0]->value);

        // Drop to an anonymous visitor: the confidential intermediate
        // spouse-family is invisible at the record layer (a re-count would yield
        // zero), but the raw grandchild-link count still reports three.
        Auth::logout();

        // Guard the precondition so the RESN marker is a real discriminator and
        // not silently inert: as the visitor the confidential spouse-family must
        // genuinely drop out of the record-layer relationship walk. Remove the
        // `1 RESN confidential` line from the fixture and this assertion fails,
        // proving the raw/record-layer divergence below is actually exercised.
        $child = Registry::individualFactory()->make('C', $tree);

        self::assertInstanceOf(Individual::class, $child);
        self::assertCount(
            0,
            $child->spouseFamilies(),
            'The confidential spouse-family must be hidden from the visitor — otherwise the raw-vs-re-count divergence is not exercised',
        );

        $asVisitor = $this->repository($tree)->topGrandchildFamilies(1);

        self::assertCount(1, $asVisitor);
        self::assertSame('GP', $asVisitor[0]->xref);
        self::assertSame(3, $asVisitor[0]->value, 'The raw grandchild-link count is privacy-blind; a record-layer re-count would drop the confidential branch to zero');
    }
}
