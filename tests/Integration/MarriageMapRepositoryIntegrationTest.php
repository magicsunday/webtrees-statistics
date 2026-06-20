<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\MarriageMapRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_unique;
use function array_values;

/**
 * End-to-end test of {@see MarriageMapRepository} against the
 * `partner-chains.ged` fixture. The fixture records 50 families that carry both
 * a HUSB and a WIFE pointer; folded into a symmetric spouse-adjacency map that
 * is exactly 52 distinct individuals (the spouses that appear in at least one
 * complete couple). Individuals are only one side of a couple — a lone HUSB or
 * WIFE with no partner — are absent from the map.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(MarriageMapRepository::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class MarriageMapRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * The map keys one individual XREF onto the list of its spouse XREFs, with
     * every FAMS pair contributing both directions so the relation stays
     * symmetric, and a partner pointer that recurs across families collapsing to
     * a single entry.
     */
    #[Test]
    public function buildReturnsDedupedSymmetricSpouseAdjacency(): void
    {
        $tree = $this->importFixtureTree('partner-chains.ged');
        $map  = (new MarriageMapRepository($tree))->build();

        self::assertCount(
            52,
            $map,
            '50 complete couples fold to 52 distinct spouses appearing in at least one HUSB+WIFE family',
        );

        // A HUSB with no WIFE (I100 in family F1) is an incomplete couple and
        // is therefore absent from the adjacency map.
        self::assertArrayNotHasKey('I100', $map);

        // Maria Jungbluth (I6) is married to Johann Jacob August Braun (I21).
        self::assertArrayHasKey('I6', $map);
        self::assertContains('I21', $map['I6']);

        // The relation is stored from both sides.
        self::assertArrayHasKey('I21', $map);
        self::assertContains('I6', $map['I21']);

        // Every spouse list is free of duplicate XREFs.
        foreach ($map as $spouses) {
            self::assertSame(array_values(array_unique($spouses)), $spouses);
        }

        // Symmetry holds for every recorded edge.
        foreach ($map as $individual => $spouses) {
            foreach ($spouses as $spouse) {
                self::assertArrayHasKey($spouse, $map);
                self::assertContains((string) $individual, $map[$spouse]);
            }
        }
    }
}
