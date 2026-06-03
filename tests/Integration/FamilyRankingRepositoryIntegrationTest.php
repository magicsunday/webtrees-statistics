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
use MagicSunday\Webtrees\Statistic\Model\Ranking\RankingEntry;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRankingRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;

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
final class FamilyRankingRepositoryIntegrationTest extends IntegrationTestCase
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
}
