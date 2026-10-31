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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_column;

/**
 * Fact-level boundary of the {@see IndividualWire} flattener. A record the
 * viewer may see still keeps a restricted SEX fact back, either through an
 * explicit `RESN` on the fact itself or through a default restriction of the
 * tree or of the individual, and the wire row must then carry the unknown sex
 * instead of the stored value. A viewer who is allowed to see the fact still
 * receives the real value, and both partnership report serializers flatten
 * their people through the same path.
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
    /**
     * Import the fixture and set the tree privacy so living people are hidden
     * while dead people stay visible to everyone, the situation of a visible
     * deceased person whose SEX fact alone is restricted.
     */
    private function tree(): Tree
    {
        $tree = $this->importFixtureTree('individual-wire-sex-restriction.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        return $tree;
    }

    /**
     * Resolve one fixture individual through the registry as the current user.
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
     * Rebuild the individual against a tree object that reads the default
     * restriction rows afresh, because a tree caches them on first use.
     *
     * @param Tree   $tree The imported fixture tree
     * @param string $xref The XREF of the individual
     *
     * @return Individual The individual bound to the reloaded tree
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

    /**
     * Log in a user whose role in the tree is member, which is the access level
     * of a `RESN privacy` restriction but below a `RESN confidential` one.
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
     * The record of I1 is visible, but its SEX fact carries `RESN confidential`.
     * A guest gets the unknown sex, while the unrestricted birth and death years
     * of the same visible record stay on the wire row.
     */
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

    /**
     * The importing administrator may see a confidential fact, so the real sex
     * of I1 reaches the wire row.
     */
    #[Test]
    public function explicitFactRestrictionKeepsTheRealSexForAnAdministrator(): void
    {
        $tree = $this->tree();

        self::assertSame('M', IndividualWire::row($this->individual($tree, 'I1'))['sex']);
    }

    /**
     * I3 carries `RESN privacy` on its SEX fact. A guest gets the unknown sex,
     * a member is released and gets the real value.
     */
    #[Test]
    public function memberLevelFactRestrictionIsHonoredForGuestAndReleasedForMember(): void
    {
        $tree = $this->tree();

        Auth::logout();
        self::assertSame('U', IndividualWire::row($this->individual($tree, 'I3'))['sex']);

        $this->loginAsMember($tree);
        self::assertSame('F', IndividualWire::row($this->individual($tree, 'I3'))['sex']);
    }

    /**
     * A `RESN confidential` fact is for managers only, so a member still gets
     * the unknown sex for I1.
     */
    #[Test]
    public function managerOnlyFactRestrictionStaysWithheldFromAMember(): void
    {
        $tree = $this->tree();

        $this->loginAsMember($tree);

        self::assertSame('U', IndividualWire::row($this->individual($tree, 'I1'))['sex']);
    }

    /**
     * The tree restricts every SEX fact by default. I2 has no restriction of
     * its own, so the administrator keeps the real sex and a guest gets the
     * unknown one.
     */
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

    /**
     * A default restriction that names one individual and the SEX tag withholds
     * the fact of I2 from a guest, although the record itself is visible.
     */
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

    /**
     * A visible record without any restriction keeps its sex, birth year and
     * page link for a guest, so the fix does not blank unrestricted people.
     */
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

    /**
     * The value X is a recognised sex and passes through for a guest.
     */
    #[Test]
    public function nonBinarySexValueSurvivesForGuest(): void
    {
        $tree = $this->tree();

        Auth::logout();

        self::assertSame('X', IndividualWire::row($this->individual($tree, 'I5'))['sex']);
    }

    /**
     * A visible record whose SEX fact is the explicit unknown value, carries a
     * value outside the recognised set or has no SEX fact at all gives a guest
     * the unknown sex, never the stored text.
     *
     * @return array<string, array{0: string}>
     */
    public static function unrecognisedSexProvider(): array
    {
        return [
            'explicit unknown value' => ['I6'],
            'unrecognised value'     => ['I7'],
            'no SEX fact'            => ['I8'],
        ];
    }

    /**
     * The record is visible to the guest, so only the SEX value decides, and
     * anything outside the recognised set reads as the unknown sex.
     */
    #[Test]
    #[DataProvider('unrecognisedSexProvider')]
    public function unrecognisedOrMissingSexReadsAsUnknownForGuest(string $xref): void
    {
        $tree = $this->tree();

        Auth::logout();

        $individual = $this->individual($tree, $xref);
        self::assertTrue($individual->canShow(), 'precondition: the record itself is visible');

        self::assertSame('U', IndividualWire::row($individual)['sex']);
    }

    /**
     * I4 is a living record with a record-level `RESN confidential`. The node
     * stays but every derived field is withheld, as before the fact-level gate.
     */
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

    /**
     * The partnership reach report and its group excerpt flatten their people
     * through the wire row, so the restricted sex of I1 reads unknown in the
     * chain, in the group nodes and in the excerpt serialized on its own.
     */
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
