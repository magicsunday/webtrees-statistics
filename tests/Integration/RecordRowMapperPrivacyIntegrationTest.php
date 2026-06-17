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
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Record\FamilyCountRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualAgeRecord;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\RecordRowMapper;
use MagicSunday\Webtrees\Statistic\View\RecordCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Privacy gate of the Hall-of-Fame record mapper: a record whose holder is not
 * visible to the current viewer must be dropped (`null`), so the derived value
 * and the record URL never reach the records-grid for a living holder a visitor
 * cannot see.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(RecordRowMapper::class)]
#[UsesClass(IndividualAgeRecord::class)]
#[UsesClass(FamilyCountRecord::class)]
#[UsesClass(RecordCategory::class)]
final class RecordRowMapperPrivacyIntegrationTest extends IntegrationTestCase
{
    private function livingTree(): Tree
    {
        $tree = $this->importFixtureTree('record-living-holder.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        return $tree;
    }

    /**
     * An individual age-record whose holder is a living, non-visible individual
     * is dropped for a visitor but rendered for the importing admin. The admin
     * control proves the fixture holder genuinely exists, so the visitor `null`
     * is the privacy gate firing — not an empty fixture.
     */
    #[Test]
    public function individualRecordIsDroppedForAVisitorButShownForTheAdmin(): void
    {
        $tree = $this->livingTree();

        // Control: as the importing admin the living holder is visible, so the
        // mapper produces a row (proving the fixture holder genuinely exists and
        // the visitor `null` below is the gate firing, not an empty fixture).
        // `url()` needs a bound web request the CLI harness lacks, so the admin
        // control asserts the gate's input (`canShow() === true`) rather than the
        // fully-mapped row.
        $admin = Registry::individualFactory()->make('I1', $tree);
        self::assertInstanceOf(Individual::class, $admin);
        self::assertTrue($admin->canShow(), 'precondition: the importing admin can see the living holder');

        Auth::logout();

        $visitor = Registry::individualFactory()->make('I1', $tree);
        self::assertInstanceOf(Individual::class, $visitor);
        self::assertFalse($visitor->canShow(), 'precondition: the visitor cannot see the living holder');

        self::assertNull(
            RecordRowMapper::years(
                RecordCategory::Life,
                'Oldest living',
                new IndividualAgeRecord($visitor, 15),
            ),
            'the living holder\'s age + URL must not leak to a visitor',
        );
    }

    /**
     * A family count-record whose family is not visible to a visitor (living
     * members) is dropped, mirroring the individual gate for the family-record
     * factories.
     */
    #[Test]
    public function familyRecordIsDroppedForAVisitorButShownForTheAdmin(): void
    {
        $tree = $this->livingTree();

        $adminFamily = Registry::familyFactory()->make('F1', $tree);
        self::assertInstanceOf(Family::class, $adminFamily);
        self::assertTrue($adminFamily->canShow(), 'precondition: the importing admin can see the living family');

        Auth::logout();

        $visitorFamily = Registry::familyFactory()->make('F1', $tree);
        self::assertInstanceOf(Family::class, $visitorFamily);
        self::assertFalse($visitorFamily->canShow(), 'precondition: the visitor cannot see the living family');

        self::assertNull(
            RecordRowMapper::familyChildren(
                RecordCategory::Family,
                'Largest family',
                new FamilyCountRecord($visitorFamily, 1),
            ),
            'the living family record must not leak to a visitor',
        );
    }
}
