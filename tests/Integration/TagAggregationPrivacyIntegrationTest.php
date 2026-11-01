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
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function sort;

// jscpd:ignore-end

/**
 * Record and fact visibility of the value aggregations over OCCU, RELI and the
 * DEAT cause. A value counts for a viewer only when the host shows the record
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
     * Resolve one fixture individual as the current viewer.
     *
     * @param Tree   $tree The imported fixture tree
     * @param string $xref The XREF of the individual
     *
     * @return Individual The individual record
     */
    private function individual(Tree $tree, string $xref): Individual
    {
        $individual = Registry::individualFactory()->make($xref, $tree);
        self::assertInstanceOf(Individual::class, $individual);

        return $individual;
    }

    /**
     * Log in a user whose role in the tree is member.
     *
     * @param Tree $tree The imported fixture tree
     */
    private function loginAsMember(Tree $tree): void
    {
        $member = (new UserService())->create('member', 'Member', 'member@example.test', 'secret');
        $tree->setUserPreference($member, UserInterface::PREF_TREE_ROLE, UserInterface::ROLE_MEMBER);
        Auth::login($member);
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
     * The administrator gets every fixture value. From the person I6 the first
     * death fact carries the cause, as the scanner reads it.
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
     * A tree default restriction on OCCU withholds the occupations from a
     * guest and leaves them to the administrator. A default that hides the fact
     * from everyone removes it for the administrator too.
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
     * an event fact and under a non-event fact, and two such lines under one
     * fact count separately.
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
     * A level-1 pointer fact is not part of the visible text, so a RELI line
     * inside it does not count, while the other values of the same person do.
     * The same holds for a person whose only facts are pointer facts.
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
     * The row prefilter keeps every row the scanners read a value from. The
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
     * counts, for an occupation, a religion and a death cause.
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
     * the administrator, so the values of the person count for the
     * administrator only.
     */
    #[Test]
    public function livingPersonCountsForTheAdministratorOnly(): void
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
}
