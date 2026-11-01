<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

// The import block below converges with the sibling PartnershipReach repository
// test, because both exercise the same repository and its collaborators. Exempt
// only the imports from clone detection so the test bodies stay covered.
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

// jscpd:ignore-end

/**
 * Privacy boundary of the median year of the largest partnership group. The
 * median is formed over the birth and death years that the viewer can see on
 * the resolved people, so a hidden record, a restricted BIRT fact and a
 * restricted DEAT fact contribute nothing to a viewer who may not see them.
 * The fixture years are chosen so that the lower median is a different year
 * for a guest, a member and an administrator.
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
#[UsesClass(GenerationDepthRepository::class)]
#[UsesClass(PartnershipMapRepository::class)]
#[UsesClass(ParentMapRepository::class)]
final class PartnershipReachMedianPrivacyIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * Import the restricted fixture with living people hidden and dead people
     * visible to everyone.
     *
     * @param string $fixture The fixture file name
     *
     * @return Tree The imported fixture tree
     */
    private function restrictedTree(string $fixture = 'partnership-median-privacy.ged'): Tree
    {
        $tree = $this->importFixtureTree($fixture);
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        return $tree;
    }

    /**
     * Compute the median year of the largest group for the current viewer.
     *
     * @param Tree $tree        The tree to report on
     * @param int  $memberCount The expected size of the largest group
     *
     * @return int|null The median year of the group
     */
    private function medianYear(Tree $tree, int $memberCount): ?int
    {
        $report = (new PartnershipReachRepository(
            $tree,
            new PartnershipMapRepository($tree),
            new GenerationDepthRepository($tree, new ParentMapRepository($tree)),
        ))->summary();

        self::assertInstanceOf(PartnershipReachReport::class, $report);
        self::assertSame($memberCount, $report->group->totalCount, 'precondition: the whole chain is one group');

        return $report->group->medianYear;
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
     * A guest sees neither the member-level birth of I2, the manager-level
     * death of I3 nor the record I4 that carries a confidential restriction, so
     * the median is formed over the remaining years. The lower bound of the
     * imprecise birth of I5 is the year that counts.
     */
    #[Test]
    public function guestMedianExcludesEveryWithheldYear(): void
    {
        $tree = $this->restrictedTree();

        Auth::logout();

        self::assertCount(0, $this->individual($tree, 'I2')->facts(['BIRT']), 'precondition: the BIRT of I2 is withheld');
        self::assertCount(0, $this->individual($tree, 'I3')->facts(['DEAT']), 'precondition: the DEAT of I3 is withheld');
        self::assertFalse($this->individual($tree, 'I4')->canShow(), 'precondition: the record of I4 is hidden');

        self::assertSame(1796, $this->medianYear($tree, 5));
    }

    /**
     * A member sees the member-level birth of I2, but still not the
     * manager-level death of I3 and not the confidential record I4.
     */
    #[Test]
    public function memberMedianIncludesOnlyTheMemberLevelYears(): void
    {
        $tree = $this->restrictedTree();

        $this->loginAsMember($tree);

        self::assertCount(1, $this->individual($tree, 'I2')->facts(['BIRT']), 'precondition: the BIRT of I2 is released');
        self::assertCount(0, $this->individual($tree, 'I3')->facts(['DEAT']), 'precondition: the DEAT of I3 stays withheld');
        self::assertFalse($this->individual($tree, 'I4')->canShow(), 'precondition: the record of I4 stays hidden');

        self::assertSame(1856, $this->medianYear($tree, 5));
    }

    /**
     * The administrator also gets the years of the withheld facts and the years
     * of the record I4 that carries a confidential restriction.
     */
    #[Test]
    public function administratorMedianCoversTheRealYears(): void
    {
        $tree = $this->restrictedTree();

        self::assertCount(1, $this->individual($tree, 'I3')->facts(['DEAT']), 'precondition: the DEAT of I3 is visible');
        self::assertTrue($this->individual($tree, 'I4')->canShow(), 'precondition: the record of I4 is visible');

        self::assertSame(1838, $this->medianYear($tree, 5));
    }

    /**
     * A group without a restriction gives a guest the same median as an
     * administrator, and the Hebrew birth counts with its Gregorian year
     * rather than its native year number.
     */
    #[Test]
    public function unrestrictedGroupGivesGuestTheFullMedian(): void
    {
        $tree = $this->importFixtureTree('partnership-median-plain.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        $administratorMedian = $this->medianYear($tree, 4);

        Auth::logout();

        self::assertTrue($this->individual($tree, 'I2')->canShow(), 'precondition: the group is visible to the guest');
        self::assertSame($administratorMedian, $this->medianYear($tree, 4));
        self::assertSame(1839, $administratorMedian);
    }

    /**
     * A tree default restriction on DEAT withholds the death years of the group
     * from a guest, so the birth years of the group remain.
     */
    #[Test]
    public function treeDefaultDeathRestrictionIsHonoredForGuest(): void
    {
        $tree = $this->importFixtureTree('partnership-median-plain.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        DB::table('default_resn')->insert([
            'gedcom_id' => $tree->id(),
            'xref'      => null,
            'tag_type'  => 'DEAT',
            'resn'      => 'confidential',
        ]);

        Registry::cache()->array()->forget('all-trees');
        $freshTree = (new TreeService(new GedcomImportService()))->find($tree->id());

        Auth::logout();

        self::assertCount(0, $this->individual($freshTree, 'I1')->facts(['DEAT']), 'precondition: the tree default withholds the DEAT facts');
        self::assertSame(1820, $this->medianYear($freshTree, 4));
    }

    /**
     * I1 has a BIRT that only the administrator may see and a visible
     * christening with another year. A guest gets no birth year for I1 at all,
     * since the christening is no substitute for the withheld birth. The
     * administrator gets the birth year and not the christening year.
     */
    #[Test]
    public function withheldBirthIsNotReplacedByAVisibleChristening(): void
    {
        $tree = $this->restrictedTree('partnership-median-fallback-birth.ged');

        self::assertSame(1832, $this->medianYear($tree, 3));

        Auth::logout();

        self::assertCount(0, $this->individual($tree, 'I1')->facts(['BIRT']), 'precondition: the BIRT of I1 is withheld');
        self::assertCount(1, $this->individual($tree, 'I1')->facts(['CHR']), 'precondition: the christening of I1 is visible');
        self::assertSame(1866, $this->medianYear($tree, 3));
    }

    /**
     * I1 has a burial but no death fact, so even the administrator gets no
     * death year for I1.
     */
    #[Test]
    public function burialWithoutDeathFactContributesNoDeathYear(): void
    {
        $tree = $this->restrictedTree('partnership-median-fallback-death.ged');

        self::assertCount(0, $this->individual($tree, 'I1')->facts(['DEAT']), 'precondition: I1 has no death fact');
        self::assertCount(1, $this->individual($tree, 'I1')->facts(['BURI']), 'precondition: I1 has a burial');
        self::assertSame(1878, $this->medianYear($tree, 3));
    }

    /**
     * I1 has four birth facts. The smallest year is neither the first nor the
     * last of them, and it is the one that counts as the lower bound of the
     * person.
     */
    #[Test]
    public function smallestOfSeveralBirthYearsCounts(): void
    {
        $tree = $this->restrictedTree('partnership-median-two-births.ged');

        self::assertCount(4, $this->individual($tree, 'I1')->facts(['BIRT']), 'precondition: I1 has four birth facts');
        self::assertSame(1844, $this->medianYear($tree, 3));
    }
}
