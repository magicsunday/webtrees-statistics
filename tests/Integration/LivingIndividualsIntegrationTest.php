<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\ModuleBase\Support\Locale\IsoCountryMap;
use MagicSunday\Webtrees\Statistic\Model\Ranking\RankingEntry;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRepository;
use MagicSunday\Webtrees\Statistic\Repository\LifeSpanRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\LivingIndividuals;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use PHPUnit\Framework\Attributes\Test;

use function array_map;
use function array_sum;
use function date;
use function implode;
use function is_string;
use function strval;

/**
 * Pins the one definition of a living individual that every living-based card
 * shares. A person is living unless a death event is recorded, or the birth is
 * older than the maximum age that the tree allows for a living person. The
 * dates of the fixture are relative to today, so the test does not age.
 *
 * Fixture, with Y the current year:
 *  - I1 born 1850, no death record            deceased by age
 *  - I2 born Y-30                              living
 *  - I3 born Y-40, `1 DEAT Y` without a date  deceased by the death event
 *  - I4 born Y-50, a burial                    deceased by the burial event
 *  - I5 no date at all                         living, nothing shows otherwise
 *  - I6 born between 1850 and 1860            deceased, the whole range is old
 *  - I7 born Y-118                             living, inside the default ceiling
 *  - I8 born between 1850 and Y-30            living, the range ends after the horizon
 *  - I9 no birth, an occupation dated 1700     living, only the birth counts as evidence
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class LivingIndividualsIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * A death event and a birth older than the ceiling make a person deceased,
     * and a person without any evidence stays living.
     */
    #[Test]
    public function aPersonIsLivingUnlessAnEventShowsOtherwise(): void
    {
        $tree = $this->livingTree();

        self::assertSame(['I2', 'I5', 'I7', 'I8', 'I9'], $this->livingXrefs($tree));
    }

    /**
     * The maximum age is the preference of the tree, so a lower ceiling turns
     * the person born more than that long ago into a deceased one.
     */
    #[Test]
    public function theMaximumAgeOfTheTreeMovesTheHorizon(): void
    {
        $tree = $this->livingTree();
        $tree->setPreference('MAX_ALIVE_AGE', '50');

        self::assertSame(['I2', 'I5', 'I8', 'I9'], $this->livingXrefs($tree));
    }

    /**
     * The marital status counts every living person of the shared set once,
     * whatever the partner state.
     */
    #[Test]
    public function theMaritalStatusClassifiesTheSharedLivingSet(): void
    {
        $tree = $this->livingTree();

        $buckets = (new FamilyRepository($tree))->classifyLivingIndividuals();

        self::assertSame(5, array_sum($buckets));
    }

    /**
     * The living and deceased counts split the individuals of the tree along
     * the shared definition.
     */
    #[Test]
    public function theCountsSplitTheTreeAlongTheSharedSet(): void
    {
        $tree = $this->livingTree();

        self::assertSame(
            ['living' => 5, 'deceased' => 4],
            (new FamilyRepository($tree))->countLivingAndDeceased(),
        );
    }

    /**
     * The life-stage donut bands the living persons that carry a birth date,
     * so the person born in 1850 does not appear in the oldest band.
     */
    #[Test]
    public function theLifeStageDonutBandsOnlyTheSharedLivingSet(): void
    {
        $tree = $this->livingTree();

        $slices = (new LifeSpanRepository($tree, $this->statisticsData($tree), new IsoCountryMap()))->livingByAgeBand();

        self::assertSame(
            3,
            array_sum(array_map(static fn (array $slice): int => $slice['value'], $slices)),
        );
    }

    /**
     * The oldest living list names the living persons by birth, and neither the
     * ancestor without a death record nor the range that starts before the
     * horizon appears in it.
     */
    #[Test]
    public function theOldestLivingListSkipsThePersonsWhoAreDeceasedByAge(): void
    {
        $tree = $this->livingTree();

        $entries = (new LifeSpanRepository($tree, $this->statisticsData($tree), new IsoCountryMap()))->topOldestLiving(10);

        self::assertSame(
            ['I7', 'I2'],
            array_map(static fn (RankingEntry $entry): string => $entry->xref, $entries),
        );
    }

    /**
     * A spouse who is deceased by age without a death record makes the living
     * partner a widow or widower. The married couple is the only family of the
     * tree, the husband was born in 1850 and the wife is a living woman.
     */
    #[Test]
    public function aPartnerWhoIsDeceasedByAgeMakesTheSurvivorWidowed(): void
    {
        $year  = (int) date('Y');
        $lines = [
            '0 HEAD',
            '1 CHAR UTF-8',
            '1 GEDC',
            '2 VERS 5.5.1',
            '2 FORM LINEAGE-LINKED',
            '0 @I1@ INDI',
            '1 NAME Old /Husband/',
            '1 SEX M',
            '1 BIRT',
            '2 DATE 1850',
            '1 FAMS @F1@',
            '0 @I2@ INDI',
            '1 NAME Young /Wife/',
            '1 SEX F',
            '1 BIRT',
            '2 DATE 1 JAN ' . ($year - 60),
            '1 FAMS @F1@',
            '0 @F1@ FAM',
            '1 HUSB @I1@',
            '1 WIFE @I2@',
            '1 MARR',
            '0 TRLR',
        ];

        $tree = $this->importGedcomTree('living-partner-' . strval($year), implode("\n", $lines));

        self::assertSame(
            ['current' => 0, 'divorced' => 0, 'widowed' => 1, 'single' => 0],
            (new FamilyRepository($tree))->classifyLivingIndividuals(),
        );
    }

    /**
     * Import the fixture tree with dates relative to the current year.
     */
    private function livingTree(): Tree
    {
        $year = (int) date('Y');

        $lines = [
            '0 HEAD',
            '1 CHAR UTF-8',
            '1 GEDC',
            '2 VERS 5.5.1',
            '2 FORM LINEAGE-LINKED',
            '0 @I1@ INDI',
            '1 NAME Old /Ancestor/',
            '1 BIRT',
            '2 DATE 1850',
            '0 @I2@ INDI',
            '1 NAME Young /Living/',
            '1 BIRT',
            '2 DATE 1 JAN ' . ($year - 30),
            '0 @I3@ INDI',
            '1 NAME With /Death/',
            '1 BIRT',
            '2 DATE 1 JAN ' . ($year - 40),
            '1 DEAT Y',
            '0 @I4@ INDI',
            '1 NAME Only /Burial/',
            '1 BIRT',
            '2 DATE 1 JAN ' . ($year - 50),
            '1 BURI',
            '0 @I5@ INDI',
            '1 NAME Undated /Person/',
            '0 @I6@ INDI',
            '1 NAME Old /Range/',
            '1 BIRT',
            '2 DATE BET 1850 AND 1860',
            '0 @I7@ INDI',
            '1 NAME Near /Ceiling/',
            '1 BIRT',
            '2 DATE 1 JAN ' . ($year - 118),
            '0 @I8@ INDI',
            '1 NAME Open /Range/',
            '1 BIRT',
            '2 DATE BET 1850 AND ' . ($year - 30),
            '0 @I9@ INDI',
            '1 NAME Old /Occupation/',
            '1 OCCU Farmer',
            '2 DATE 1700',
            '0 TRLR',
        ];

        return $this->importGedcomTree('living-definition-' . strval($year), implode("\n", $lines));
    }

    /**
     * XREFs of the individuals that the shared definition keeps as living.
     *
     * @return list<string>
     */
    private function livingXrefs(Tree $tree): array
    {
        $query = LivingIndividuals::restrict(TreeScope::table($tree, 'individuals'), $tree);
        $xrefs = [];

        foreach ($query->orderBy('i_id')->pluck('i_id') as $xref) {
            if (is_string($xref)) {
                $xrefs[] = $xref;
            }
        }

        return $xrefs;
    }
}
