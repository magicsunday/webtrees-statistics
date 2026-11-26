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
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Repository\OccupationInheritanceRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use MagicSunday\Webtrees\Statistic\Repository\PartnershipMapRepository;
use MagicSunday\Webtrees\Statistic\Test\Support\Normalization\StubOccupationNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_keys;
use function array_map;
use function implode;
use function range;
use function sort;
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
     * The visible parent map applies the rules of the full map to what the
     * viewer may see: the family with more parents wins, equal families resolve
     * to the lowest family xref although the child record lists it last, and a
     * hidden family leaves the next one.
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
        ];

        foreach (range(1, 5) as $parent) {
            $records[] = "0 @P{$parent}@ INDI\n1 NAME P{$parent} /Test/\n1 DEAT Y\n";
        }

        $tree = $this->importGedcomTree('visible-parents', "0 HEAD\n1 CHAR UTF-8\n" . implode('', $records) . "0 TRLR\n");
        $this->hideLivingPeople($tree);
        Auth::logout();

        $parents = (new ParentMapRepository($tree))->buildVisible(['C1' => $records[0], 'C2' => $records[1]]);

        self::assertSame(['P1', null], $parents['C1'] ?? null);
        self::assertSame(['P5', null], $parents['C2'] ?? null);
    }

    /**
     * A tree with the records of the tests above. Every person but one child of
     * the third family is dead and public.
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
