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
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Tree\PartnershipGroupExcerpt;
use MagicSunday\Webtrees\Statistic\Model\Tree\PartnershipReachReport;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\IndividualWire;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RecordName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Fact-level boundary of the {@see IndividualWire} flattener. A record the
 * viewer may see still keeps a restricted SEX fact back, either through an
 * explicit `RESN` on the fact or through the tree's default fact restriction,
 * and the wire row must then carry the unknown sex instead of the stored value.
 * A viewer who is allowed to see the fact still receives the real value, and
 * both partnership report serializers flatten their people through the same
 * path.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(IndividualWire::class)]
#[UsesClass(RecordName::class)]
#[UsesClass(PartnershipReachReport::class)]
#[UsesClass(PartnershipGroupExcerpt::class)]
final class IndividualWireSexRestrictionIntegrationTest extends AbstractIntegrationTestCase
{
    private function tree(): Tree
    {
        $tree = $this->importFixtureTree('individual-wire-sex-restriction.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        return $tree;
    }

    private function individual(Tree $tree, string $xref): Individual
    {
        $individual = Registry::individualFactory()->make($xref, $tree);
        self::assertInstanceOf(Individual::class, $individual);

        return $individual;
    }

    /**
     * Rebuild the individual against a tree object that reads the default
     * restriction rows afresh, because a tree caches them on first use.
     */
    private function individualWithFreshDefaults(Tree $tree, string $xref): Individual
    {
        Registry::cache()->array()->forget('all-trees');

        $freshTree = (new TreeService(new GedcomImportService()))->find($tree->id());

        return Registry::individualFactory()->new(
            $xref,
            $this->individual($tree, $xref)->gedcom(),
            null,
            $freshTree,
        );
    }

    private function loginAsMember(Tree $tree): void
    {
        $member = (new UserService())->create('member', 'Member', 'member@example.test', 'secret');
        $tree->setUserPreference($member, UserInterface::PREF_TREE_ROLE, UserInterface::ROLE_MEMBER);
        Auth::login($member);
    }

    #[Test]
    public function explicitFactRestrictionGivesGuestTheUnknownSex(): void
    {
        $tree = $this->tree();

        Auth::logout();

        $individual = $this->individual($tree, 'I1');
        self::assertTrue($individual->canShow(), 'precondition: the record itself is visible');
        self::assertCount(0, $individual->facts(['SEX']), 'precondition: the SEX fact is withheld from the guest');

        $row = IndividualWire::row($individual);

        self::assertSame('I1', $row['xref']);
        self::assertSame('U', $row['sex']);
        self::assertSame('1900', $row['birth'], 'the unrestricted facts of a visible record stay');
        self::assertSame('1960', $row['death']);
    }

    #[Test]
    public function explicitFactRestrictionKeepsTheRealSexForAnAdministrator(): void
    {
        $tree = $this->tree();

        self::assertSame('M', IndividualWire::row($this->individual($tree, 'I1'))['sex']);
    }

    #[Test]
    public function memberLevelFactRestrictionIsHonoredForGuestAndReleasedForMember(): void
    {
        $tree = $this->tree();

        Auth::logout();
        self::assertSame('U', IndividualWire::row($this->individual($tree, 'I3'))['sex']);

        $this->loginAsMember($tree);
        self::assertSame('F', IndividualWire::row($this->individual($tree, 'I3'))['sex']);
    }

    #[Test]
    public function managerOnlyFactRestrictionStaysWithheldFromAMember(): void
    {
        $tree = $this->tree();

        $this->loginAsMember($tree);

        self::assertSame('U', IndividualWire::row($this->individual($tree, 'I1'))['sex']);
    }

    #[Test]
    public function treeDefaultFactRestrictionGivesGuestTheUnknownSexAndAdministratorTheRealOne(): void
    {
        $tree = $this->tree();

        DB::table('default_resn')->insert([
            'gedcom_id' => $tree->id(),
            'xref'      => null,
            'tag_type'  => 'SEX',
            'resn'      => 'confidential',
        ]);

        self::assertSame('F', IndividualWire::row($this->individualWithFreshDefaults($tree, 'I2'))['sex'], 'the administrator keeps the real value');

        Auth::logout();

        $individual = $this->individualWithFreshDefaults($tree, 'I2');
        self::assertTrue($individual->canShow(), 'precondition: the record itself is visible');
        self::assertCount(0, $individual->facts(['SEX']), 'precondition: the tree default withholds the SEX fact');
        self::assertSame('U', IndividualWire::row($individual)['sex']);
    }

    #[Test]
    public function individualDefaultFactRestrictionGivesGuestTheUnknownSex(): void
    {
        $tree = $this->tree();

        DB::table('default_resn')->insert([
            'gedcom_id' => $tree->id(),
            'xref'      => 'I2',
            'tag_type'  => 'SEX',
            'resn'      => 'confidential',
        ]);

        Auth::logout();

        $individual = $this->individualWithFreshDefaults($tree, 'I2');
        self::assertCount(0, $individual->facts(['SEX']), 'precondition: the individual default withholds the SEX fact');
        self::assertSame('U', IndividualWire::row($individual)['sex']);
    }

    #[Test]
    public function visibleUnrestrictedRecordKeepsItsSexForGuest(): void
    {
        $tree = $this->tree();

        Auth::logout();

        $row = IndividualWire::row($this->individual($tree, 'I2'));

        self::assertSame('F', $row['sex']);
        self::assertSame('1902', $row['birth']);
        self::assertNotSame('', $row['url']);
    }

    #[Test]
    public function nonBinarySexValueSurvivesForGuest(): void
    {
        $tree = $this->tree();

        Auth::logout();

        self::assertSame('X', IndividualWire::row($this->individual($tree, 'I5'))['sex']);
    }

    #[Test]
    public function hiddenRecordStillWithholdsEveryDerivedFact(): void
    {
        $tree = $this->tree();

        Auth::logout();

        $individual = $this->individual($tree, 'I4');
        self::assertFalse($individual->canShow(), 'precondition: the record is hidden from the guest');

        $row = IndividualWire::row($individual);

        self::assertSame('I4', $row['xref']);
        self::assertSame('U', $row['sex']);
        self::assertSame('', $row['birth']);
        self::assertSame('', $row['death']);
        self::assertSame('', $row['url']);
    }

    #[Test]
    public function partnershipReportSerializersFlattenThroughTheRestrictedSexPath(): void
    {
        $tree = $this->tree();

        Auth::logout();

        $restricted = $this->individual($tree, 'I1');
        $open       = $this->individual($tree, 'I2');

        $group = new PartnershipGroupExcerpt([$restricted, $open], [['I1', 'I2']], ['I1', 'I2'], 'I1', 1, 2, 1, 2, 1930);
        $json  = (new PartnershipReachReport(2, [$restricted, $open], $group, 1, 2))->jsonSerialize();

        self::assertSame(['U', 'F'], array_column($json['chain'], 'sex'));
        self::assertSame(['U', 'F'], array_column($json['group']['nodes'], 'sex'));
        self::assertSame(['U', 'F'], array_column($group->jsonSerialize()['nodes'], 'sex'));
    }
}
