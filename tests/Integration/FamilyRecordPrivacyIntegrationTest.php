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
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Factories\CacheFactory;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Repository\OccupationInheritanceRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use MagicSunday\Webtrees\Statistic\Repository\PartnershipMapRepository;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\VisibleFamily;
use MagicSunday\Webtrees\Statistic\Test\Support\Normalization\StubOccupationNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\Attributes\Test;

use function array_filter;
use function array_keys;
use function array_map;
use function implode;
use function range;
use function sort;
use function str_contains;
use function str_replace;
use function strval;

/**
 * A family the viewer may not see gives no partnership edge and does not count
 * in the occupation flow, whether the family record carries a restriction of its
 * own or the host hides it because a member is private. A restriction on the
 * link of a child to a family keeps that child out of the flow of that family.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class FamilyRecordPrivacyIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * @return iterable<string, array{string, list<string>}> Viewer and the individuals the partnership map holds for it
     */
    public static function partnershipViewers(): iterable
    {
        yield 'guest' => ['guest', ['I1', 'I2']];
        yield 'member' => ['member', ['I1', 'I2', 'I5', 'I6']];
        yield 'administrator' => ['admin', ['I1', 'I2', 'I3', 'I4', 'I5', 'I6']];
    }

    /**
     * The open family between the first couple is an edge for every viewer. The
     * confidential family of the second couple is an edge for an administrator
     * only, and the family of the third couple with a living child is hidden
     * from a guest.
     *
     * @param string       $viewer   The viewer to test
     * @param list<string> $expected The individuals the map holds
     */
    #[Test]
    #[DataProvider('partnershipViewers')]
    public function thePartnershipMapSkipsFamiliesTheViewerMayNotSee(string $viewer, array $expected): void
    {
        $tree = $this->privacyTree();
        $this->logInAs($viewer, $tree);

        $keys = array_keys((new PartnershipMapRepository($tree))->build());
        $keys = array_map(strval(...), $keys);
        sort($keys);

        self::assertSame($expected, $keys);
    }

    /**
     * @return iterable<string, array{string, int}> Viewer and the weight of the flow for it
     */
    public static function occupationViewers(): iterable
    {
        yield 'guest' => ['guest', 2];
        yield 'member' => ['member', 3];
        yield 'administrator' => ['admin', 5];
    }

    /**
     * Five children inherit the same trade. Two are children of the open family,
     * one is a child of a confidential family, one carries a confidential link to
     * the open family and one is a dead sibling of a living child. A guest counts
     * the two of the open family, a member also the sibling and an administrator
     * all of them.
     *
     * @param string $viewer The viewer to test
     * @param int    $weight The expected weight of the flow
     */
    #[Test]
    #[DataProvider('occupationViewers')]
    public function theOccupationFlowCountsOnlyVisibleFamilyLinks(string $viewer, int $weight): void
    {
        $tree = $this->privacyTree();
        $this->logInAs($viewer, $tree);

        $payload = (new OccupationInheritanceRepository($tree, new ParentMapRepository($tree), new StubOccupationNormalizer([])))
            ->occupationInheritance(10, 1);

        self::assertCount(1, $payload->links);
        self::assertSame($weight, $payload->links[0]->value);
    }

    /**
     * The visible parent map resolves among the families the viewer may see. The
     * family with more parents wins, equal families resolve to the lowest family
     * xref although the child record lists it last, and a hidden family leaves
     * the next one, even when the hidden family names more parents.
     */
    #[Test]
    public function theVisibleParentMapResolvesAmongTheVisibleFamilies(): void
    {
        $records = [
            "0 @C1@ INDI\n1 NAME C1 /Test/\n1 FAMC @F2@\n1 FAMC @F1@\n1 DEAT Y\n",
            "0 @C2@ INDI\n1 NAME C2 /Test/\n1 FAMC @F3@\n1 FAMC @F4@\n1 DEAT Y\n",
            "0 @F1@ FAM\n1 HUSB @P1@\n1 CHIL @C1@\n",
            "0 @F2@ FAM\n1 HUSB @P2@\n1 CHIL @C1@\n",
            "0 @F3@ FAM\n1 RESN confidential\n1 HUSB @P3@\n1 WIFE @P4@\n1 CHIL @C2@\n",
            "0 @F4@ FAM\n1 HUSB @P5@\n1 CHIL @C2@\n",
            "0 @C3@ INDI\n1 NAME C3 /Test/\n1 FAMC @F5@\n1 FAMC @F6@\n1 DEAT Y\n",
            "0 @F5@ FAM\n1 HUSB @P1@\n1 CHIL @C3@\n",
            "0 @F6@ FAM\n1 HUSB @P2@\n1 WIFE @P4@\n1 CHIL @C3@\n",
            // A value that is no pointer links nothing.
            "0 @C4@ INDI\n1 NAME C4 /Test/\n1 FAMC F5\n1 DEAT Y\n",
        ];

        foreach (range(1, 5) as $parent) {
            $records[] = "0 @P{$parent}@ INDI\n1 NAME P{$parent} /Test/\n1 DEAT Y\n";
        }

        $tree = $this->importGedcomTree('visible-parents', "0 HEAD\n1 CHAR UTF-8\n" . implode('', $records) . "0 TRLR\n");
        $this->actAsVisitorWithLivingHidden($tree);

        $parents = (new ParentMapRepository($tree))->buildVisible(
            ['C1' => $records[0], 'C2' => $records[1], 'C3' => $records[6], 'C4' => $records[9]],
        );

        self::assertSame(['P1', null], $parents['C1'] ?? null);
        self::assertSame(['P5', null], $parents['C2'] ?? null);
        self::assertSame(['P2', 'P4'], $parents['C3'] ?? null);
        self::assertArrayNotHasKey('C4', $parents);
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: array{0: string|null, 1: string|null}|null, 3: bool, 4?: string}> The record to restrict, the link, the expected parents, whether the couple stays an edge and the line written in place of the link
     */
    public static function restrictedLinks(): iterable
    {
        yield 'child link of the family' => ['F1', '1 CHIL @C1@', null, true];
        yield 'husband link of the family' => ['F1', '1 HUSB @P1@', [null, 'P2'], false];
        yield 'wife link of the family' => ['F1', '1 WIFE @P2@', ['P1', null], false];
        yield 'family link of the husband' => ['P1', '1 FAMS @F1@', [null, 'P2'], false];
        yield 'family link of the wife' => ['P2', '1 FAMS @F1@', ['P1', null], false];
        yield 'family link of the child' => ['C1', '1 FAMC @F1@', null, true];
        yield 'husband link with trailing text' => ['F1', '1 HUSB @P1@', [null, 'P2'], false, '1 HUSB @P1@ x'];
        yield 'child link with another letter case' => ['F1', '1 CHIL @C1@', null, true, '1 CHIL @c1@'];
        yield 'family link of the husband with trailing text' => ['P1', '1 FAMS @F1@', [null, 'P2'], false, '1 FAMS @F1@ x'];
    }

    /**
     * A restriction on one link between a family and a person hides that relation,
     * also when the tree shows private relationships.
     *
     * @param 'C1'|'F1'|'P1'|'P2'                        $record  The record that holds the link
     * @param string                                     $link    The restricted link line
     * @param array{0: string|null, 1: string|null}|null $parents The expected parents of the child
     * @param bool                                       $edge    Whether the couple stays an edge
     * @param string|null                                $written The line written in place of the link
     */
    #[Test]
    #[DataProvider('restrictedLinks')]
    public function aRestrictedLinkHidesItsRelation(string $record, string $link, ?array $parents, bool $edge, ?string $written = null): void
    {
        // The sibling gives the family a second CHIL line and the second family
        // gives the husband a second FAMS line. Their XREFs extend the ones under
        // test, and neither open line may stand in for the restricted one.
        $records = [
            'C1'  => "0 @C1@ INDI\n1 NAME C1 /Test/\n1 FAMC @F1@\n1 DEAT Y\n",
            'P1'  => "0 @P1@ INDI\n1 NAME P1 /Test/\n1 FAMS @F1@\n1 FAMS @F10@\n1 DEAT Y\n",
            'P2'  => "0 @P2@ INDI\n1 NAME P2 /Test/\n1 FAMS @F1@\n1 DEAT Y\n",
            'F1'  => "0 @F1@ FAM\n1 HUSB @P1@\n1 WIFE @P2@\n1 CHIL @C1@\n1 CHIL @C10@\n",
            'C10' => "0 @C10@ INDI\n1 NAME C10 /Test/\n1 FAMC @F1@\n1 DEAT Y\n",
            'F10' => "0 @F10@ FAM\n1 HUSB @P1@\n",
        ];

        $records[$record] = str_replace($link . "\n", ($written ?? $link) . "\n2 RESN confidential\n", $records[$record]);

        $tree = $this->importGedcomTree('restricted-link', "0 HEAD\n1 CHAR UTF-8\n" . implode('', $records) . "0 TRLR\n");
        $tree->setPreference('SHOW_PRIVATE_RELATIONSHIPS', '1');
        $this->actAsVisitorWithLivingHidden($tree);

        $visible = (new ParentMapRepository($tree))->buildVisible(['C1' => $records['C1']]);

        self::assertSame($parents, $visible['C1'] ?? null);
        self::assertSame($edge, (new PartnershipMapRepository($tree))->build() !== []);
    }

    /**
     * More children and couples than one chunk holds are all resolved, each chunk
     * reads its family rows with one query, and neither lookup leaves its records
     * in the cache of the request.
     */
    #[Test]
    public function theLookupsWorkThroughEveryChunk(): void
    {
        $count    = VisibleFamily::CHUNK_SIZE + 1;
        $gedcom   = "0 HEAD\n1 CHAR UTF-8\n";
        $children = [];

        foreach (range(1, $count) as $number) {
            $child                  = "0 @C{$number}@ INDI\n1 NAME C /Test/\n1 FAMC @F{$number}@\n1 DEAT Y\n";
            $children["C{$number}"] = $child;
            $gedcom .= $child
                . "0 @H{$number}@ INDI\n1 NAME H /Test/\n1 FAMS @F{$number}@\n1 DEAT Y\n"
                . "0 @W{$number}@ INDI\n1 NAME W /Test/\n1 FAMS @F{$number}@\n1 DEAT Y\n"
                . "0 @F{$number}@ FAM\n1 HUSB @H{$number}@\n1 WIFE @W{$number}@\n1 CHIL @C{$number}@\n";
        }

        $tree = $this->importGedcomTree('chunks', $gedcom . "0 TRLR\n");
        $this->actAsVisitorWithLivingHidden($tree);

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        $parents = (new ParentMapRepository($tree))->buildVisible($children);
        $couples = (new PartnershipMapRepository($tree))->build();
        $log     = DB::connection()->getQueryLog();

        self::assertCount($count, $parents);
        self::assertSame(['H1', 'W1'], $parents['C1'] ?? null);
        self::assertCount(2 * $count, $couples);

        $rowQueries = array_filter(
            $log,
            // The engines quote identifiers differently.
            static function (array $query): bool {
                $sql = str_replace('`', '"', $query['query']);

                return str_contains($sql, '"f_gedcom"') && str_contains($sql, '"f_id" in');
            },
        );

        self::assertCount(4, $rowQueries, 'two chunks of each lookup');

        DB::connection()->flushQueryLog();
        Registry::familyFactory()->make('F1', $tree);

        self::assertNotSame([], DB::connection()->getQueryLog(), 'the family is not in the cache of the request');

        DB::connection()->disableQueryLog();
    }

    /**
     * The restriction that a pending change adds to a child record counts for an
     * editor, who sees the change, as it does for the host.
     */
    #[Test]
    public function aPendingRestrictionOfAChildCountsForAnEditor(): void
    {
        $child = "0 @C1@ INDI\n1 NAME C1 /Test/\n1 FAMC @F1@\n1 DEAT Y\n";
        $tree  = $this->importGedcomTree(
            'pending-child',
            "0 HEAD\n1 CHAR UTF-8\n" . $child
            . "0 @P1@ INDI\n1 NAME P1 /Test/\n1 FAMS @F1@\n1 DEAT Y\n"
            . "0 @F1@ FAM\n1 HUSB @P1@\n1 CHIL @C1@\n0 TRLR\n",
        );
        $this->hideLivingPeople($tree);
        Auth::logout();
        $this->loginAsEditor($tree);

        $this->individual($tree, 'C1')->updateRecord($child . "1 RESN confidential\n", false);
        Registry::cache(new CacheFactory());

        self::assertSame([], (new ParentMapRepository($tree))->buildVisible(['C1' => $child]));
    }

    /**
     * A member with relationship privacy sees the family of a relative in the
     * partnership map, and the host still shows it afterwards.
     */
    #[Test]
    #[RunInSeparateProcess]
    public function relationshipPrivacyOfTheViewerKeepsWorking(): void
    {
        $tree = $this->importGedcomTree(
            'relationship',
            "0 HEAD\n1 CHAR UTF-8\n"
            . "0 @ME@ INDI\n1 NAME Me /Test/\n1 BIRT\n2 DATE 1 JAN 2000\n1 FAMC @F1@\n"
            . "0 @P1@ INDI\n1 NAME P1 /Test/\n1 BIRT\n2 DATE 1 JAN 1970\n1 FAMS @F1@\n"
            . "0 @P2@ INDI\n1 NAME P2 /Test/\n1 BIRT\n2 DATE 1 JAN 1970\n1 FAMS @F1@\n"
            . "0 @F1@ FAM\n1 HUSB @P1@\n1 WIFE @P2@\n1 CHIL @ME@\n0 TRLR\n",
        );
        $this->hideLivingPeople($tree);
        Auth::logout();
        $this->loginAsMember($tree);
        $tree->setUserPreference(Auth::user(), UserInterface::PREF_TREE_ACCOUNT_XREF, 'ME');
        $tree->setUserPreference(Auth::user(), UserInterface::PREF_TREE_PATH_LENGTH, '1');

        $family = Registry::familyFactory()->make('F1', $tree) ?? self::fail('the family exists');

        self::assertTrue($family->canShow(), 'precondition: the host shows the family of a relative');

        $keys = array_map(strval(...), array_keys((new PartnershipMapRepository($tree))->build()));
        sort($keys);

        self::assertSame(['P1', 'P2'], $keys);
        self::assertTrue($this->individual($tree, 'P1')->canShow());
    }

    /**
     * @return iterable<string, array{string, string, string, string}> The role, the linked record, the path length and the tree preference that hides living people
     */
    public static function viewersWithoutRelationshipPrivacy(): iterable
    {
        yield 'member with a linked record only' => [UserInterface::ROLE_MEMBER, 'I1', '', '1'];
        yield 'member with a path length only' => [UserInterface::ROLE_MEMBER, '', '1', '1'];
        yield 'manager with both' => [UserInterface::ROLE_MANAGER, 'I1', '1', '1'];
        yield 'tree that shows living people' => [UserInterface::ROLE_MEMBER, 'I1', '1', '0'];
    }

    /**
     * A viewer to whom the host does not apply relationship privacy keeps the
     * families of the lookup out of the cache of the request.
     *
     * @param string $role       The tree role of the viewer
     * @param string $xref       The linked record
     * @param string $pathLength The relationship path length
     * @param string $hideLiving The tree preference that hides living people
     */
    #[Test]
    #[DataProvider('viewersWithoutRelationshipPrivacy')]
    public function viewersWithoutRelationshipPrivacyUseACacheOfTheirOwn(string $role, string $xref, string $pathLength, string $hideLiving): void
    {
        $tree = $this->privacyTree();
        $tree->setPreference('HIDE_LIVE_PEOPLE', $hideLiving);
        $this->logInAs('member', $tree);

        $tree->setUserPreference(Auth::user(), UserInterface::PREF_TREE_ROLE, $role);
        $tree->setUserPreference(Auth::user(), UserInterface::PREF_TREE_ACCOUNT_XREF, $xref);
        $tree->setUserPreference(Auth::user(), UserInterface::PREF_TREE_PATH_LENGTH, $pathLength);
        Registry::cache(new CacheFactory());

        (new PartnershipMapRepository($tree))->build();

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        Registry::familyFactory()->make('F1', $tree);

        self::assertNotSame([], DB::connection()->getQueryLog(), 'the family is not in the cache of the request');

        DB::connection()->disableQueryLog();
    }

    /**
     * A person with a digit-only XREF who is listed as a spouse of the own family
     * is not the own parent, in the full and in the visible parent map.
     */
    #[Test]
    public function aDigitOnlyXrefIsNeverTheOwnParent(): void
    {
        $child = "0 @54@ INDI\n1 NAME A /Test/\n1 FAMC @F1@\n1 FAMS @F1@\n1 DEAT Y\n";
        $tree  = $this->importGedcomTree(
            'digit-parent',
            "0 HEAD\n1 CHAR UTF-8\n" . $child
            . "0 @7@ INDI\n1 NAME B /Test/\n1 FAMS @F1@\n1 DEAT Y\n"
            . "0 @F1@ FAM\n1 HUSB @54@\n1 WIFE @7@\n1 CHIL @54@\n0 TRLR\n",
        );
        $this->actAsVisitorWithLivingHidden($tree);

        $repository = new ParentMapRepository($tree);

        self::assertSame([null, '7'], $repository->build()[54] ?? null);
        self::assertSame([null, '7'], $repository->buildVisible(['54' => $child])[54] ?? null);
    }

    /**
     * The records that the privacy checks resolve do not stay in the record cache
     * of the request.
     */
    #[Test]
    public function thePrivacyChecksLeaveTheRequestCacheAlone(): void
    {
        $tree = $this->privacyTree();
        $this->logInAs('guest', $tree);

        $cache = Registry::cache();

        (new PartnershipMapRepository($tree))->build();

        self::assertSame($cache, Registry::cache());

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();

        Registry::familyFactory()->make('F1', $tree);

        self::assertNotSame([], DB::connection()->getQueryLog(), 'the family is not in the cache of the request');

        DB::connection()->disableQueryLog();
    }

    /**
     * A tree with the records of the partnership map and occupation flow tests.
     * Every person but one child of the third family is dead and public.
     */
    private function privacyTree(): Tree
    {
        $person = static fn (string $xref, string $sex, string $links, string $occupation = ''): string => "0 @{$xref}@ INDI\n1 NAME {$xref} /Test/\n1 SEX {$sex}\n1 BIRT\n2 DATE 1 JAN 1800\n1 DEAT\n2 DATE 1 JAN 1860\n"
            . ($occupation === '' ? '' : "1 OCCU {$occupation}\n")
            . $links;

        $records = [
            $person('I1', 'M', "1 FAMS @F1@\n", 'Miller'),
            $person('I2', 'F', "1 FAMS @F1@\n"),
            $person('I3', 'M', "1 FAMS @F2@\n", 'Miller'),
            $person('I4', 'F', "1 FAMS @F2@\n"),
            $person('I5', 'M', "1 FAMS @F3@\n", 'Miller'),
            $person('I6', 'F', "1 FAMS @F3@\n"),
            $person('C1', 'M', "1 FAMC @F1@\n", 'Smith'),
            $person('C2', 'M', "1 FAMC @F1@\n", 'Smith'),
            $person('C3', 'M', "1 FAMC @F2@\n", 'Smith'),
            $person('C4', 'M', "1 FAMC @F1@\n2 RESN confidential\n", 'Smith'),
            $person('C6', 'M', "1 FAMC @F3@\n", 'Smith'),
            // A living person hides the third family from a guest.
            "0 @C5@ INDI\n1 NAME C5 /Test/\n1 SEX M\n1 BIRT\n2 DATE 1 JAN 2010\n1 FAMC @F3@\n",
            "0 @F1@ FAM\n1 HUSB @I1@\n1 WIFE @I2@\n1 CHIL @C1@\n1 CHIL @C2@\n1 CHIL @C4@\n",
            "0 @F2@ FAM\n1 RESN confidential\n1 HUSB @I3@\n1 WIFE @I4@\n1 CHIL @C3@\n",
            "0 @F3@ FAM\n1 HUSB @I5@\n1 WIFE @I6@\n1 CHIL @C5@\n1 CHIL @C6@\n",
        ];

        $tree = $this->importGedcomTree('family-privacy', "0 HEAD\n1 CHAR UTF-8\n" . implode('', $records) . "0 TRLR\n");
        $this->hideLivingPeople($tree);

        return $tree;
    }

    /**
     * Switch from the importing administrator to the viewer of the case.
     *
     * @param string $viewer One of guest, member or admin
     * @param Tree   $tree   The tree the viewer reads
     */
    private function logInAs(string $viewer, Tree $tree): void
    {
        if ($viewer === 'admin') {
            return;
        }

        Auth::logout();

        if ($viewer === 'member') {
            $this->loginAsMember($tree);
        }
    }
}
