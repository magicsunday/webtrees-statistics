<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

// The import block below converges with the sibling PartnershipReach tests, because
// they exercise the same repository and its collaborators. Exempt only the
// imports from clone detection so the test bodies stay covered.
// jscpd:ignore-start
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Tree\PartnershipGroupExcerpt;
use MagicSunday\Webtrees\Statistic\Model\Tree\PartnershipReachReport;
use MagicSunday\Webtrees\Statistic\Repository\GenerationDepthRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use MagicSunday\Webtrees\Statistic\Repository\PartnershipMapRepository;
use MagicSunday\Webtrees\Statistic\Repository\PartnershipReachRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Calc\PartnershipChains;
use MagicSunday\Webtrees\Statistic\Support\Database\ChunkedWhereIn;
use MagicSunday\Webtrees\Statistic\Support\Database\GedcomByXref;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;
use function explode;
use function implode;
use function range;
use function str_starts_with;

/**
 * The median year of the largest partnership group follows the date rules of
 * the host fact accessors, and these tests pin that rule for the unusual date
 * shapes: a range with an unparsable bound counts as undated, a range written
 * in reverse order takes the date written first as its lower bound, a fact with
 * two date lines takes the first line, and the lower bound over several facts of
 * a tag is the smallest year. The shape under test sits on the middle person of
 * a chain of three people, and the years of the other two are fixed so that the
 * lower median is another year for each reading of the shape.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(PartnershipReachRepository::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(GregorianDate::class)]
#[UsesClass(GedcomByXref::class)]
#[UsesClass(ChunkedWhereIn::class)]
#[UsesClass(PartnershipChains::class)]
#[UsesClass(PartnershipGroupExcerpt::class)]
#[UsesClass(PartnershipReachReport::class)]
#[UsesClass(RowCast::class)]
// jscpd:ignore-end
final class PartnershipReachMedianDateShapesIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * GEDCOM of a chain of three people: I1 and I2 are partners, and so are I2
     * and I3. I1 and I3 carry the fixed years, I2 carries the lines that the
     * caller passes for its birth and its death.
     *
     * @param list<string>              $middleLines The level one and two lines of the middle person's BIRT and DEAT facts
     * @param array{int, int, int, int} $outerYears  The birth and death year of I1, then the birth and death year of I3
     *
     * @return string The GEDCOM text
     */
    private function chainGedcom(array $middleLines, array $outerYears): string
    {
        [$firstBirth, $firstDeath, $lastBirth, $lastDeath] = $outerYears;

        $lines = [
            '0 HEAD',
            '1 CHAR UTF-8',
            '1 GEDC',
            '2 VERS 5.5.1',
            '2 FORM LINEAGE-LINKED',
            '0 @I1@ INDI',
            '1 NAME First /Chain/',
            '1 SEX M',
            '1 BIRT',
            '2 DATE 1 JAN ' . $firstBirth,
            '1 DEAT',
            '2 DATE 1 JAN ' . $firstDeath,
            '1 FAMS @F1@',
            '0 @I2@ INDI',
            '1 NAME Middle /Chain/',
            '1 SEX F',
            ...$middleLines,
            '1 FAMS @F1@',
            '1 FAMS @F2@',
            '0 @I3@ INDI',
            '1 NAME Last /Chain/',
            '1 SEX M',
            '1 BIRT',
            '2 DATE 1 JAN ' . $lastBirth,
            '1 DEAT',
            '2 DATE 1 JAN ' . $lastDeath,
            '1 FAMS @F2@',
            '0 @F1@ FAM',
            '1 HUSB @I1@',
            '1 WIFE @I2@',
            '0 @F2@ FAM',
            '1 HUSB @I3@',
            '1 WIFE @I2@',
            '0 TRLR',
        ];

        return implode("\n", $lines);
    }

    /**
     * The median year of the largest group for the current viewer.
     *
     * @param Tree $tree        The tree to report on
     * @param int  $memberCount The expected size of the largest group
     *
     * @return int|null The median year of the group
     */
    private function medianYear(Tree $tree, int $memberCount): ?int
    {
        $report = $this->report($tree);

        self::assertSame($memberCount, $report->group->totalCount, 'precondition: the whole chain is one group');

        return $report->group->medianYear;
    }

    /**
     * The partnership report of the tree.
     *
     * @param Tree $tree The tree to report on
     */
    private function report(Tree $tree): PartnershipReachReport
    {
        $report = (new PartnershipReachRepository(
            $tree,
            new PartnershipMapRepository($tree),
            new GenerationDepthRepository($tree, new ParentMapRepository($tree)),
        ))->summary();

        self::assertInstanceOf(PartnershipReachReport::class, $report);

        return $report;
    }

    /**
     * A range with an unparsable second bound is no valid date for the host, so
     * the birth of the middle person counts as undated. The five remaining years
     * give a lower median of 1830, while a reading that kept the valid bound
     * would give 1810.
     */
    #[Test]
    public function rangeWithAnUnparsableBoundCountsAsUndated(): void
    {
        $tree = $this->importGedcomTree('shape-half-invalid', $this->chainGedcom(
            ['1 BIRT', '2 DATE BET 1790 AND xyz', '1 DEAT', '2 DATE 1 JAN 1900'],
            [1800, 1830, 1810, 1840],
        ));

        self::assertSame(1830, $this->medianYear($tree, 3));
    }

    /**
     * A range written in reverse order takes the date written first as its
     * lower bound. The lower median is 1820, while the smaller date of the range
     * would give 1810.
     */
    #[Test]
    public function reversedRangeTakesTheDateWrittenFirst(): void
    {
        $tree = $this->importGedcomTree('shape-reversed', $this->chainGedcom(
            ['1 BIRT', '2 DATE BET 1820 AND 1790', '1 DEAT', '2 DATE 1 JAN 1900'],
            [1800, 1830, 1810, 1840],
        ));

        self::assertSame(1820, $this->medianYear($tree, 3));
    }

    /**
     * A fact with two date lines takes the first line. The lower median is 1820,
     * while the smaller of the two lines would give 1810.
     */
    #[Test]
    public function factWithTwoDateLinesTakesTheFirstLine(): void
    {
        $tree = $this->importGedcomTree('shape-two-lines', $this->chainGedcom(
            ['1 BIRT', '2 DATE 1 JAN 1820', '2 DATE 1 JAN 1790', '1 DEAT', '2 DATE 1 JAN 1900'],
            [1800, 1830, 1810, 1840],
        ));

        self::assertSame(1820, $this->medianYear($tree, 3));
    }

    /**
     * Of several death facts the smallest year counts, wherever it stands in the
     * record. The middle person has the deaths of 1980 and 1905 in this order,
     * so the lower median is 1910, while the first written death would give
     * 1930.
     */
    #[Test]
    public function smallestOfSeveralDeathYearsCounts(): void
    {
        $tree = $this->importGedcomTree('shape-deaths', $this->chainGedcom(
            [
                '1 BIRT',
                '2 DATE 1 JAN 1930',
                '1 DEAT',
                '2 DATE 1 JAN 1980',
                '1 DEAT',
                '2 DATE 1 JAN 1905',
            ],
            [1900, 1950, 1910, 1960],
        ));

        self::assertSame(1910, $this->medianYear($tree, 3));
    }

    /**
     * Import the chain whose middle person has the accepted lines and a change
     * that waits for approval and gives the middle person the pending lines. The
     * viewer stays the administrator, who authors the change, so a test picks
     * the viewer that it wants to measure afterwards.
     *
     * @param list<string> $acceptedLines The accepted BIRT and DEAT lines of the middle person
     * @param list<string> $pendingLines  The BIRT and DEAT lines of the middle person after the change
     *
     * @return Tree The imported tree
     */
    private function treeWithPendingChange(array $acceptedLines, array $pendingLines): Tree
    {
        $outerYears = [1800, 1830, 1810, 1840];
        $tree       = $this->importGedcomTree('shape-pending', $this->chainGedcom($acceptedLines, $outerYears));

        $oldGedcom = DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->where('i_id', '=', 'I2')
            ->value('i_gedcom');

        self::assertIsString($oldGedcom);

        DB::table('change')->insert([
            'gedcom_id'  => $tree->id(),
            'xref'       => 'I2',
            'old_gedcom' => $oldGedcom,
            'new_gedcom' => $this->recordOf($this->chainGedcom($pendingLines, $outerYears), 'I2'),
            'status'     => 'pending',
            'user_id'    => Auth::id(),
        ]);

        return $tree;
    }

    /**
     * The lines of one record of a GEDCOM text, from its level zero line to the
     * line before the next level zero line.
     *
     * @param string $gedcom The GEDCOM text
     * @param string $xref   The XREF of the record
     *
     * @return string The record text
     */
    private function recordOf(string $gedcom, string $xref): string
    {
        $record = [];
        $inside = false;

        foreach (explode("\n", $gedcom) as $line) {
            if (str_starts_with($line, '0 ')) {
                $inside = ($line === '0 @' . $xref . '@ INDI');
            }

            if ($inside) {
                $record[] = $line;
            }
        }

        return implode("\n", $record);
    }

    /**
     * Accepted birth 1820, pending birth 1790 for the middle person.
     *
     * @return Tree The imported tree
     */
    private function treeWithPendingReplacement(): Tree
    {
        return $this->treeWithPendingChange(
            ['1 BIRT', '2 DATE 1 JAN 1820', '1 DEAT', '2 DATE 1 JAN 1900'],
            ['1 BIRT', '2 DATE 1 JAN 1790', '1 DEAT', '2 DATE 1 JAN 1900'],
        );
    }

    /**
     * Accepted data without a birth, pending birth 1790 for the middle person.
     *
     * @return Tree The imported tree
     */
    private function treeWithPendingAddition(): Tree
    {
        return $this->treeWithPendingChange(
            ['1 DEAT', '2 DATE 1 JAN 1900'],
            ['1 BIRT', '2 DATE 1 JAN 1790', '1 DEAT', '2 DATE 1 JAN 1900'],
        );
    }

    /**
     * Accepted birth 1820, the birth is pending deletion for the middle person.
     *
     * @return Tree The imported tree
     */
    private function treeWithPendingDeletion(): Tree
    {
        return $this->treeWithPendingChange(
            ['1 BIRT', '2 DATE 1 JAN 1820', '1 DEAT', '2 DATE 1 JAN 1900'],
            ['1 DEAT', '2 DATE 1 JAN 1900'],
        );
    }

    /**
     * A replacement that waits for approval counts for an editor who is no
     * administrator. The lower median is 1810, while the accepted data alone
     * would give 1820.
     */
    #[Test]
    public function pendingReplacementCountsForAnEditor(): void
    {
        $tree = $this->treeWithPendingReplacement();

        $this->loginAsEditor($tree);

        self::assertSame(1810, $this->medianYear($tree, 3));
    }

    /**
     * A guest sees the accepted data only, so the lower median is 1820. The
     * viewer logs out before the first read, because the host keeps the records
     * of one request in a cache that the viewer who builds them fills.
     */
    #[Test]
    public function pendingReplacementIsHiddenFromAGuest(): void
    {
        $tree = $this->treeWithPendingReplacement();

        Auth::logout();

        self::assertSame(1820, $this->medianYear($tree, 3));
    }

    /**
     * A birth that waits for approval as an addition counts for an editor. The
     * lower median is 1810, while the accepted data without a birth gives 1830.
     */
    #[Test]
    public function pendingAdditionCountsForAnEditor(): void
    {
        $tree = $this->treeWithPendingAddition();

        $this->loginAsEditor($tree);

        self::assertSame(1810, $this->medianYear($tree, 3));
    }

    /**
     * A guest sees no birth for the middle person, so the lower median is 1830.
     */
    #[Test]
    public function pendingAdditionIsHiddenFromAGuest(): void
    {
        $tree = $this->treeWithPendingAddition();

        Auth::logout();

        self::assertSame(1830, $this->medianYear($tree, 3));
    }

    /**
     * A birth that waits for deletion does not count for an editor, as the
     * birth date of the person on the wire rows does not. The lower median is
     * 1830, while counting the fact that is about to go would give 1820.
     */
    #[Test]
    public function pendingDeletionDoesNotCountForAnEditor(): void
    {
        $tree = $this->treeWithPendingDeletion();

        $this->loginAsEditor($tree);

        self::assertSame(1830, $this->medianYear($tree, 3));
    }

    /**
     * A guest still sees the accepted birth, so the lower median is 1820.
     */
    #[Test]
    public function pendingDeletionIsHiddenFromAGuest(): void
    {
        $tree = $this->treeWithPendingDeletion();

        Auth::logout();

        self::assertSame(1820, $this->medianYear($tree, 3));
    }

    /**
     * The median is formed over every member of a group that is larger than the
     * excerpt cap, not over the shown excerpt. The birth year of person n is 1000
     * plus n, so the years of the shown people are those of the smallest XREFs
     * in byte order and differ from the years of the whole group. The test first
     * checks that the two medians differ, so a median over the excerpt fails.
     */
    #[Test]
    public function medianCoversEveryMemberOfAGroupOverTheCap(): void
    {
        $people = PartnershipReachRepository::NETWORK_CAP * 3;
        $tree   = $this->importGedcomTree('shape-over-cap', $this->datedStarGedcom($people));
        $report = $this->report($tree);

        self::assertSame($people, $report->group->totalCount);
        self::assertSame(PartnershipReachRepository::NETWORK_CAP, $report->group->shownCount);

        $allYears   = array_map(static fn (int $number): int => 1000 + $number, range(1, $people));
        $shownYears = array_map(
            static fn (Individual $individual): int => 1000 + (int) $individual->xref(),
            $report->group->nodes,
        );

        self::assertNotSame(
            PartnershipChains::medianYear($allYears),
            PartnershipChains::medianYear($shownYears),
            'precondition: the median over the shown excerpt differs from the median over the group',
        );
        self::assertSame(PartnershipChains::medianYear($allYears), $report->group->medianYear);
    }

    /**
     * GEDCOM of one star of people with digit-only XREFs, the first one married
     * to each other person. The birth year of person n is 1000 plus n.
     *
     * @param int $people The number of people
     *
     * @return string The GEDCOM text
     */
    private function datedStarGedcom(int $people): string
    {
        $lines = ['0 HEAD', '1 CHAR UTF-8', '1 GEDC', '2 VERS 5.5.1', '2 FORM LINEAGE-LINKED'];

        for ($number = 1; $number <= $people; ++$number) {
            $lines[] = '0 @' . $number . '@ INDI';
            $lines[] = '1 NAME Person ' . $number . ' /Star/';
            $lines[] = '1 SEX ' . (($number === 1) ? 'M' : 'F');
            $lines[] = '1 BIRT';
            $lines[] = '2 DATE 1 JAN ' . (1000 + $number);
        }

        for ($number = 2; $number <= $people; ++$number) {
            $lines[] = '0 @F' . $number . '@ FAM';
            $lines[] = '1 HUSB @1@';
            $lines[] = '1 WIFE @' . $number . '@';
        }

        $lines[] = '0 TRLR';

        return implode("\n", $lines);
    }
}
