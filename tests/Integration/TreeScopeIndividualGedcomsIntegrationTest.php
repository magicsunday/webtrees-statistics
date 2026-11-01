<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Illuminate\Support\Collection;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function sort;

/**
 * Row selection of {@see TreeScope::individualGedcoms()}. The fixture people
 * carry the scanned tag at several places, longer tags that only start with it,
 * tags without a value and no tag at all, so the tests pin which rows each
 * pattern argument loads.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(TreeScope::class)]
#[UsesClass(GedcomScanner::class)]
#[UsesClass(RowCast::class)]
final class TreeScopeIndividualGedcomsIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * The XREFs of the loaded rows in sorted order.
     *
     * @param Collection<int, object> $rows The rows returned by the loader
     *
     * @return list<string>
     */
    private function xrefs(Collection $rows): array
    {
        $xrefs = $rows->map(static fn (object $row): string => RowCast::string($row, 'xref'))->values()->all();

        sort($xrefs);

        return $xrefs;
    }

    /**
     * A pattern set loads the rows that match one of its patterns. The person
     * I1 has a level one OCCU with a value and I8 an OCCU line without a value,
     * while I6 has the longer tag OCCUX and I9 has no tag at all.
     */
    #[Test]
    public function patternSetLoadsOnlyTheRowsThatMatch(): void
    {
        $tree = $this->importFixtureTree('tag-aggregation-prefilter.ged');

        $rows = TreeScope::individualGedcoms($tree, GedcomScanner::anchoredLikePatterns('OCCU'));

        self::assertSame(['I1', 'I8'], $this->xrefs($rows));
    }

    /**
     * Patterns of the second level load the rows with such a line under a fact.
     * I3 carries it under an event fact, I4 under a non-event fact and I8 as a
     * line without a value, while I6 has the longer tag RELIX.
     */
    #[Test]
    public function subTagPatternsLoadTheRowsWithALevelTwoLine(): void
    {
        $tree = $this->importFixtureTree('tag-aggregation-prefilter.ged');

        $rows = TreeScope::individualGedcoms($tree, GedcomScanner::anchoredSubTagLikePatterns('RELI'));

        self::assertSame(['I3', 'I4'], $this->xrefs($rows));
    }

    /**
     * Without patterns every row of the tree is loaded.
     */
    #[Test]
    public function nullPatternsLoadEveryRow(): void
    {
        $tree = $this->importFixtureTree('tag-aggregation-prefilter.ged');

        self::assertSame(
            ['I1', 'I2', 'I3', 'I4', 'I5', 'I6', 'I8', 'I9'],
            $this->xrefs(TreeScope::individualGedcoms($tree)),
        );
    }

    /**
     * An empty pattern list adds no condition and loads every row too.
     */
    #[Test]
    public function emptyPatternListLoadsEveryRow(): void
    {
        $tree = $this->importFixtureTree('tag-aggregation-prefilter.ged');

        self::assertSame(
            ['I1', 'I2', 'I3', 'I4', 'I5', 'I6', 'I8', 'I9'],
            $this->xrefs(TreeScope::individualGedcoms($tree, [])),
        );
    }
}
