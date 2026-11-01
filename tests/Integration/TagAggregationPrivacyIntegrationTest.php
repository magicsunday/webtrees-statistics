<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

// The import block below converges with the sibling integration tests of the
// tag repositories, because all of them exercise the same repositories and
// collaborators. Exempt only the imports from clone detection so the test
// bodies stay covered.
// jscpd:ignore-start
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Aggregator\TopNAggregator;
use MagicSunday\Webtrees\Statistic\Normalization\OccupationFolding;
use MagicSunday\Webtrees\Statistic\Normalization\Support\ContentLanguage;
use MagicSunday\Webtrees\Statistic\Normalization\Support\StringList;
use MagicSunday\Webtrees\Statistic\Repository\DeathCauseRepository;
use MagicSunday\Webtrees\Statistic\Repository\OccupationRepository;
use MagicSunday\Webtrees\Statistic\Repository\ReligionRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\VisibleGedcomRow;
use MagicSunday\Webtrees\Statistic\Test\Support\Normalization\StubOccupationNormalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function is_string;
use function sort;
use function str_contains;
use function str_repeat;

// jscpd:ignore-end

/**
 * Record and fact visibility of the value aggregations over OCCU, RELI and the
 * DEAT cause. A value counts for a viewer when the host shows the record
 * and the fact that carries the value. The fixture people are dead and visible
 * unless a record restriction hides them, with living people hidden by the tree
 * preference, so the tests pin which restriction removes which value.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(OccupationRepository::class)]
#[CoversClass(ReligionRepository::class)]
#[CoversClass(DeathCauseRepository::class)]
#[CoversClass(VisibleGedcomRow::class)]
#[UsesClass(TopNAggregator::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(GedcomScanner::class)]
#[UsesClass(OccupationFolding::class)]
#[UsesClass(ContentLanguage::class)]
#[UsesClass(StringList::class)]
#[UsesClass(StubOccupationNormalizer::class)]
final class TagAggregationPrivacyIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * Import the fixture with living people hidden and dead people visible.
     *
     * @return Tree The imported fixture tree
     */
    private function tree(): Tree
    {
        $tree = $this->importFixtureTree('tag-aggregation-privacy.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        return $tree;
    }

    /**
     * Insert a tree default fact restriction and return a tree object that
     * reads the default restriction rows afresh, because a tree caches them on
     * first use.
     *
     * @param Tree   $tree The imported fixture tree
     * @param string $tag  The fact tag to restrict
     * @param string $resn The restriction notice (privacy, confidential or hidden)
     *
     * @return Tree The tree object that sees the new default
     */
    private function treeWithDefault(Tree $tree, string $tag, string $resn): Tree
    {
        DB::table('default_resn')->insert([
            'gedcom_id' => $tree->id(),
            'xref'      => null,
            'tag_type'  => $tag,
            'resn'      => $resn,
        ]);

        Registry::cache()->array()->forget('all-trees');

        return (new TreeService(new GedcomImportService()))->find($tree->id());
    }

    /**
     * The occupation counts of the tree for the current viewer.
     *
     * @param Tree $tree The tree to aggregate
     *
     * @return array<string, int> Label to count, as ranked by the aggregation
     */
    private function occupations(Tree $tree): array
    {
        return (new OccupationRepository($tree, new StubOccupationNormalizer([])))->top(50);
    }

    /**
     * The religion counts of the tree for the current viewer.
     *
     * @param Tree $tree The tree to aggregate
     *
     * @return array<string, int> Label to count, as ranked by the aggregation
     */
    private function religions(Tree $tree): array
    {
        return (new ReligionRepository($tree))->top(50);
    }

    /**
     * The death cause counts of the tree for the current viewer.
     *
     * @param Tree $tree The tree to aggregate
     *
     * @return array<string, int> Label to count, as ranked by the aggregation
     */
    private function causes(Tree $tree): array
    {
        return (new DeathCauseRepository($tree))->top(50);
    }

    /**
     * A guest gets no value from the record I2 with a confidential record
     * restriction and none from the confidential facts of I3. The unrestricted
     * values of the same visible records stay.
     */
    #[Test]
    public function guestMissesValuesOfHiddenRecordsAndWithheldFacts(): void
    {
        $tree = $this->tree();

        Auth::logout();

        self::assertFalse($this->individual($tree, 'I2')->canShow(), 'precondition: the record I2 is hidden');
        self::assertTrue($this->individual($tree, 'I3')->canShow(), 'precondition: the record I3 is visible');
        self::assertCount(1, $this->individual($tree, 'I3')->facts(['OCCU']), 'precondition: one OCCU of I3 is withheld');
        self::assertCount(0, $this->individual($tree, 'I3')->facts(['RELI']), 'precondition: the RELI of I3 is withheld');
        self::assertCount(0, $this->individual($tree, 'I3')->facts(['DEAT']), 'precondition: the DEAT of I3 is withheld');

        self::assertSame(['Baker' => 1, 'Mason' => 1, 'Weaver' => 1], $this->occupations($tree));
        self::assertSame(
            ['Alpha' => 1, 'Baptist' => 1, 'Beta' => 1, 'Gamma' => 1, 'Lutheran' => 1, 'Orthodox' => 1],
            $this->religions($tree),
        );
        self::assertSame(['Fever' => 1, 'Visible' => 1], $this->causes($tree));
    }

    /**
     * A member gets the values that carry a member level restriction and the
     * values of the living person, but not the ones that need a manager.
     */
    #[Test]
    public function memberGetsMemberLevelValuesOnly(): void
    {
        $tree = $this->tree();

        $this->loginAsMember($tree);

        self::assertCount(1, $this->individual($tree, 'I4')->facts(['RELI']), 'precondition: the RELI of I4 is released');
        self::assertCount(0, $this->individual($tree, 'I3')->facts(['RELI']), 'precondition: the RELI of I3 stays withheld');
        self::assertFalse($this->individual($tree, 'I2')->canShow(), 'precondition: the record I2 stays hidden');

        self::assertSame(['Baker' => 1, 'Mason' => 1, 'Miller' => 1, 'Tanner' => 1, 'Weaver' => 1], $this->occupations($tree));
        self::assertSame(
            ['Alpha' => 1, 'Baptist' => 1, 'Beta' => 1, 'Gamma' => 1, 'Lutheran' => 1, 'Methodist' => 1, 'Orthodox' => 1, 'Shaker' => 1],
            $this->religions($tree),
        );
        self::assertSame(['Fever' => 1, 'Gout' => 1, 'Visible' => 1], $this->causes($tree));
    }

    /**
     * The administrator gets the values of the restricted records and facts, the
     * confidential ones among them. From the person I6 with two death facts the
     * first one supplies the cause.
     */
    #[Test]
    public function administratorGetsTheValuesOfTheRestrictedRecordsAndFacts(): void
    {
        $tree = $this->tree();

        self::assertTrue($this->individual($tree, 'I2')->canShow(), 'precondition: the record I2 is visible');
        self::assertCount(1, $this->individual($tree, 'I3')->facts(['DEAT']), 'precondition: the DEAT of I3 is visible');

        self::assertSame(
            ['Baker' => 1, 'Cooper' => 1, 'Mason' => 1, 'Miller' => 1, 'Smith' => 1, 'Tanner' => 1, 'Weaver' => 1],
            $this->occupations($tree),
        );
        self::assertSame(
            [
                'Alpha'     => 1,
                'Baptist'   => 1,
                'Beta'      => 1,
                'Gamma'     => 1,
                'Hussite'   => 1,
                'Lutheran'  => 1,
                'Mennonite' => 1,
                'Methodist' => 1,
                'Orthodox'  => 1,
                'Quaker'    => 1,
                'Shaker'    => 1,
            ],
            $this->religions($tree),
        );
        self::assertSame(
            ['Cholera' => 1, 'Fever' => 1, 'Gout' => 1, 'Hidden' => 1, 'Plague' => 1],
            $this->causes($tree),
        );
    }

    /**
     * A tree default restriction of the confidential kind on OCCU withholds the
     * occupations from a guest and leaves them to the administrator.
     */
    #[Test]
    public function treeDefaultOccupationRestrictionFollowsTheViewer(): void
    {
        $tree = $this->tree();

        $confidential = $this->treeWithDefault($tree, 'OCCU', 'confidential');

        self::assertSame(
            ['Baker' => 1, 'Cooper' => 1, 'Mason' => 1, 'Miller' => 1, 'Smith' => 1, 'Tanner' => 1, 'Weaver' => 1],
            $this->occupations($confidential),
        );

        Auth::logout();

        self::assertCount(0, $this->individual($confidential, 'I1')->facts(['OCCU']), 'precondition: the default withholds the OCCU facts');
        self::assertSame([], $this->occupations($confidential));
    }

    /**
     * A tree default of the hidden kind removes the values of unrestricted
     * RELI facts from the administrator as well, while a fact with an explicit
     * restriction notice keeps following that notice.
     */
    #[Test]
    public function hiddenTreeDefaultRemovesTheValuesFromTheAdministrator(): void
    {
        $tree = $this->tree();

        $hidden = $this->treeWithDefault($tree, 'RELI', 'hidden');

        self::assertCount(0, $this->individual($hidden, 'I1')->facts(['RELI']), 'precondition: the default hides the RELI fact from the administrator');
        self::assertSame(
            ['Alpha' => 1, 'Beta' => 1, 'Gamma' => 1, 'Hussite' => 1, 'Mennonite' => 1, 'Orthodox' => 1, 'Shaker' => 1],
            $this->religions($hidden),
        );
    }

    /**
     * An unrestricted record gives the values of the level two RELI lines under
     * an event fact and under a non-event fact. For a guest, the two lines under
     * the one event fact (Alpha and Beta) and the line under the non-event fact
     * (Gamma) each count once.
     */
    #[Test]
    public function unrestrictedSubTagValuesCountAsRead(): void
    {
        $tree = $this->tree();

        Auth::logout();

        $religions = $this->religions($tree);

        self::assertSame(1, $religions['Alpha'] ?? 0);
        self::assertSame(1, $religions['Beta'] ?? 0);
        self::assertSame(1, $religions['Gamma'] ?? 0);
    }

    /**
     * A person with two death facts, the first without a cause, gives no cause
     * for an administrator, as the scanner reads the first death fact only.
     */
    #[Test]
    public function firstDeathFactWithoutCauseGivesNoCause(): void
    {
        $tree = $this->tree();

        self::assertArrayNotHasKey('Drowning', $this->causes($tree));
    }

    /**
     * A guest gets the cause of the second death fact of I6 because the first
     * one is withheld.
     */
    #[Test]
    public function secondDeathFactCountsWhenTheFirstIsWithheld(): void
    {
        $tree = $this->tree();

        Auth::logout();

        self::assertCount(1, $this->individual($tree, 'I6')->facts(['DEAT']), 'precondition: one death fact of I6 is visible');
        self::assertSame(1, $this->causes($tree)['Visible'] ?? 0);
    }

    /**
     * A malformed record row contributes no values and does not fail the
     * aggregation of the others.
     */
    #[Test]
    public function malformedRowContributesNothing(): void
    {
        $tree = $this->tree();

        DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->where('i_id', '=', 'I5')
            ->update(['i_gedcom' => "0 @I5@ INDI\n1 NAME Five /Default/\n1 !BROKEN\n1 OCCU Weaver"]);

        self::assertArrayNotHasKey('Weaver', $this->occupations($tree));
        self::assertSame(1, $this->occupations($tree)['Baker'] ?? 0);
    }

    /**
     * A RELI line inside a level-1 pointer fact does not count, while the other
     * values of the same person do. The test checks the returned values for a
     * person with a pointer fact next to a value fact and for a person with
     * pointer facts only. It does not show whether the tag was passed to the
     * host.
     */
    #[Test]
    public function valueInsidePointerFactIsNotRead(): void
    {
        $tree = $this->tree();

        DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->where('i_id', '=', 'I5')
            ->update([
                'i_gedcom' => "0 @I5@ INDI\n1 NAME Five /Default/\n1 RELI Baptist\n1 SOUR @S1@\n2 RELI Cited",
            ]);

        DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->where('i_id', '=', 'I4')
            ->update(['i_gedcom' => "0 @I4@ INDI\n1 FAMS @F1@\n2 RELI OnlyPointer"]);

        $religions = $this->religions($tree);

        self::assertSame(1, $religions['Baptist'] ?? 0);
        self::assertArrayNotHasKey('Cited', $religions);
        self::assertArrayNotHasKey('OnlyPointer', $religions);
    }

    /**
     * The normalizer receives the values of visible facts only. A value that
     * is reachable through a hidden record or a withheld fact only does not
     * reach it for a guest.
     */
    #[Test]
    public function normalizerReceivesVisibleValuesOnly(): void
    {
        $tree = $this->tree();

        Auth::logout();

        $normalizer = new StubOccupationNormalizer([]);

        (new OccupationRepository($tree, $normalizer))->top(50);

        $received = $normalizer->receivedValues();
        sort($received);

        self::assertSame(['Baker', 'Mason', 'Weaver'], $received);
    }

    /**
     * The row prefilter keeps the rows the scanners read a value from. The
     * fixture has the tags at level one, at level two under an event fact and
     * under a non-event fact, and also longer tags that only start with the
     * scanned tag, a stored row with lower case tags and tags without a value.
     * Only the first group gives values. The test pins the values the
     * repositories return, and the rows the prefilter loads are pinned by the
     * test of the row loader.
     */
    #[Test]
    public function rowPrefilterKeepsEveryRowTheScannersReadFrom(): void
    {
        $tree = $this->importFixtureTree('tag-aggregation-prefilter.ged');

        // The import turns tags into upper case, so the lower case lines are
        // written into a stored row directly.
        DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->where('i_id', '=', 'I9')
            ->update([
                'i_gedcom' => "0 @I9@ INDI\n1 NAME Nine /Lower/\n1 occu Cobbler\n1 reli Quaker\n1 DEAT\n2 DATE 1 FEB 1922\n2 caus Flu",
            ]);

        self::assertSame(['Baker' => 1], $this->occupations($tree));
        self::assertSame(['Jain' => 1, 'Lutheran' => 1, 'Orthodox' => 1], $this->religions($tree));
        self::assertSame(['Fever' => 1], $this->causes($tree));
    }

    /**
     * A free text value that starts with an escaped at sign is no pointer and
     * counts, for an occupation, a religion and a death cause. So do values
     * that end with an at sign but are no record reference for the host, one
     * with an escaped start, one with a space and one that is a calendar escape.
     */
    #[Test]
    public function escapedAtSignValuesCount(): void
    {
        $tree = $this->tree();

        DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->where('i_id', '=', 'I5')
            ->update([
                'i_gedcom' => "0 @I5@ INDI\n1 NAME Five /Escaped/\n1 OCCU @@Home\n1 RELI @@Faith\n1 DEAT\n2 DATE 1 FEB 1924\n2 CAUS @@Cause",
            ]);

        self::assertSame(1, $this->occupations($tree)['@@Home'] ?? 0);
        self::assertSame(1, $this->religions($tree)['@@Faith'] ?? 0);
        self::assertSame(1, $this->causes($tree)['@@Cause'] ?? 0);

        DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->where('i_id', '=', 'I5')
            ->update([
                'i_gedcom' => "0 @I5@ INDI\n1 NAME Five /Escaped/\n1 OCCU @@x@\n1 OCCU @Home town@\n1 OCCU @#DJULIAN@",
            ]);

        $occupations = $this->occupations($tree);

        self::assertSame(1, $occupations['@@x@'] ?? 0);
        self::assertSame(1, $occupations['@Home town@'] ?? 0);
        self::assertSame(1, $occupations['@#DJULIAN@'] ?? 0);
    }

    /**
     * A tag that occurs as a pointer fact next to an inline fact is read from
     * the inline fact only. The RELI line inside the pointer fact does not
     * count, and the one inside the inline fact does.
     */
    #[Test]
    public function pointerFactNextToInlineFactOfTheSameTagIsSkipped(): void
    {
        $tree = $this->tree();

        DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->where('i_id', '=', 'I5')
            ->update([
                'i_gedcom' => "0 @I5@ INDI\n1 NAME Five /Mixed/\n1 SOUR @S1@\n2 RELI Cited\n1 SOUR inline\n2 RELI Anabaptist",
            ]);

        $religions = $this->religions($tree);

        self::assertArrayNotHasKey('Cited', $religions);
        self::assertSame(1, $religions['Anabaptist'] ?? 0);
    }

    /**
     * A living person without any dates is hidden from a guest and visible to
     * the administrator. The values of the person are missing for the guest and
     * present for the administrator.
     */
    #[Test]
    public function livingPersonIsHiddenFromGuest(): void
    {
        $tree = $this->tree();

        self::assertTrue($this->individual($tree, 'I9')->canShow(), 'precondition: the administrator sees the living person');
        self::assertSame(1, $this->occupations($tree)['Miller'] ?? 0);
        self::assertSame(1, $this->religions($tree)['Methodist'] ?? 0);

        Auth::logout();

        self::assertFalse($this->individual($tree, 'I9')->canShow(), 'precondition: the guest cannot see the living person');
        self::assertArrayNotHasKey('Miller', $this->occupations($tree));
        self::assertArrayNotHasKey('Methodist', $this->religions($tree));
    }

    /**
     * Each repository hands its own LIKE patterns to the row loader. The string
     * bindings of the recorded queries whose SQL contains `like` are the
     * patterns of the scanned tag, at the level the scanner reads.
     */
    #[Test]
    public function eachRepositoryLoadsRowsWithItsOwnPatterns(): void
    {
        $tree = $this->tree();

        self::assertSame(
            ["%\n1 OCCU %", "%\n1 OCCU\n%", "%\n1 OCCU"],
            $this->likeBindings(fn (): array => $this->occupations($tree)),
        );
        self::assertSame(
            ["%\n1 RELI %", "%\n1 RELI\n%", "%\n1 RELI", "%\n2 RELI %", "%\n2 RELI\n%", "%\n2 RELI"],
            $this->likeBindings(fn (): array => $this->religions($tree)),
        );
        self::assertSame(
            ["%\n2 CAUS %", "%\n2 CAUS\n%", "%\n2 CAUS"],
            $this->likeBindings(fn (): array => $this->causes($tree)),
        );
    }

    /**
     * The string bindings of the queries whose SQL contains `like` that one
     * aggregation issues, in the order the database received them. The `finally`
     * only switches the query log off again.
     *
     * @param callable(): array<string, int> $aggregation The aggregation to run
     *
     * @return list<string>
     */
    private function likeBindings(callable $aggregation): array
    {
        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        try {
            $aggregation();
        } finally {
            DB::connection()->disableQueryLog();
        }

        $bindings = [];

        foreach (DB::connection()->getQueryLog() as $entry) {
            if (str_contains($entry['query'], 'like')) {
                foreach ($entry['bindings'] as $binding) {
                    if (is_string($binding)) {
                        $bindings[] = $binding;
                    }
                }
            }
        }

        return $bindings;
    }

    /**
     * Values in the form of a host record reference, an XREF between at signs,
     * with one sample for each range of the XREF class, each of its punctuation
     * characters and the longest allowed length.
     *
     * @return array<string, array{0: string}>
     */
    public static function pointerValueProvider(): array
    {
        return [
            'lowercase letter' => ['@a@'],
            'uppercase letter' => ['@Z@'],
            'digit'            => ['@1@'],
            'hyphen'           => ['@a-b@'],
            'colon'            => ['@a:b@'],
            'underscore'       => ['@a_b@'],
            'dot'              => ['@a.b@'],
            'longest xref'     => ['@' . str_repeat('x', 20) . '@'],
        ];
    }

    /**
     * Values that look like a record reference but exceed the XREF length.
     *
     * @return array<string, array{0: string}>
     */
    public static function textValueProvider(): array
    {
        return [
            'one character too long' => ['@' . str_repeat('x', 21) . '@'],
        ];
    }

    /**
     * An occupation that is a record reference is a pointer and does not count.
     *
     * @param string $value The value of the OCCU line
     */
    #[Test]
    #[DataProvider('pointerValueProvider')]
    public function recordReferenceValueIsNotCounted(string $value): void
    {
        $tree = $this->tree();

        $this->replaceRowOfI5($tree, '1 OCCU ' . $value);

        self::assertArrayNotHasKey($value, $this->occupations($tree));
    }

    /**
     * An occupation that is not a record reference for the host is a text and
     * counts.
     *
     * @param string $value The value of the OCCU line
     */
    #[Test]
    #[DataProvider('textValueProvider')]
    public function overlongReferenceValueCountsAsText(string $value): void
    {
        $tree = $this->tree();

        $this->replaceRowOfI5($tree, '1 OCCU ' . $value);

        self::assertSame(1, $this->occupations($tree)[$value] ?? 0);
    }

    /**
     * Replace the stored row of the person I5 by a row with the given fact line.
     *
     * @param Tree   $tree The imported fixture tree
     * @param string $fact The level one fact line to store
     */
    private function replaceRowOfI5(Tree $tree, string $fact): void
    {
        DB::table('individuals')
            ->where('i_file', '=', $tree->id())
            ->where('i_id', '=', 'I5')
            ->update(['i_gedcom' => "0 @I5@ INDI\n1 NAME Five /Pointer/\n" . $fact]);
    }
}
