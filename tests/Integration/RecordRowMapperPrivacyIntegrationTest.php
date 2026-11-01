<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Closure;
use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Aggregator\RecordRowMapper;
use MagicSunday\Webtrees\Statistic\Model\Record\FamilyCountRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\FamilyDurationDaysRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\FamilyDurationYearsRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualAgeRecord;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualCountRecord;
use MagicSunday\Webtrees\Statistic\View\RecordCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_keys;

/**
 * Privacy gate and row shape of the Hall-of-Fame record mapper. A record whose
 * holder is not visible to the current viewer is dropped (`null`), so the
 * derived value and the record URL do not reach the records grid for a living
 * holder a visitor cannot see. For a viewer who can see the holder the mapper
 * returns the full row.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(RecordRowMapper::class)]
#[UsesClass(IndividualAgeRecord::class)]
#[UsesClass(FamilyCountRecord::class)]
#[UsesClass(FamilyDurationDaysRecord::class)]
#[UsesClass(FamilyDurationYearsRecord::class)]
#[UsesClass(IndividualCountRecord::class)]
#[UsesClass(RecordCategory::class)]
final class RecordRowMapperPrivacyIntegrationTest extends AbstractIntegrationTestCase
{
    private function livingTree(): Tree
    {
        $tree = $this->importFixtureTree('record-living-holder.ged');
        $this->hideLivingPeople($tree);

        return $tree;
    }

    /**
     * One case per factory method of the mapper. Each builds the record for the
     * living holder I1 or the living family F1 and maps it, and states the row
     * value the importing admin gets.
     *
     * @return array<string, array{string, Closure(Tree): ?array{cat: string, label: string, value: string, who: string, url: string}, string}>
     */
    public static function recordFactories(): array
    {
        return [
            'individual age' => [
                'individual',
                static fn (Tree $tree): ?array => RecordRowMapper::years(
                    RecordCategory::Life,
                    'Oldest living',
                    new IndividualAgeRecord(self::individual($tree), 15),
                ),
                '15 years',
            ],
            'family years' => [
                'family',
                static fn (Tree $tree): ?array => RecordRowMapper::familyYears(
                    RecordCategory::Family,
                    'Longest marriage',
                    new FamilyDurationYearsRecord(self::family($tree), 16),
                ),
                '16 years',
            ],
            'family days' => [
                'family',
                static fn (Tree $tree): ?array => RecordRowMapper::familyDays(
                    RecordCategory::Family,
                    'Shortest marriage',
                    new FamilyDurationDaysRecord(self::family($tree), 17),
                ),
                '17 days',
            ],
            'individual marriages' => [
                'individual',
                static fn (Tree $tree): ?array => RecordRowMapper::marriages(
                    RecordCategory::Family,
                    'Most spouses',
                    new IndividualCountRecord(self::individual($tree), 2),
                ),
                '2 marriages',
            ],
            'individual children' => [
                'individual',
                static fn (Tree $tree): ?array => RecordRowMapper::children(
                    RecordCategory::Family,
                    'Most children',
                    new IndividualCountRecord(self::individual($tree), 3),
                ),
                '3 children',
            ],
            'family children' => [
                'family',
                static fn (Tree $tree): ?array => RecordRowMapper::familyChildren(
                    RecordCategory::Family,
                    'Largest family',
                    new FamilyCountRecord(self::family($tree), 4),
                ),
                '4 children',
            ],
        ];
    }

    /**
     * A record whose holder is a living, non-visible record maps to a full row
     * for the importing admin and is dropped for a visitor. The admin row shows
     * that the holder exists and that the gate is what drops it for the visitor.
     *
     * @param string                                                                                     $holderKind `individual` or `family`
     * @param Closure(Tree): ?array{cat: string, label: string, value: string, who: string, url: string} $map
     * @param string                                                                                     $value      Value text of the admin row
     */
    #[Test]
    #[DataProvider('recordFactories')]
    public function recordIsMappedForTheAdminAndDroppedForAVisitor(string $holderKind, Closure $map, string $value): void
    {
        $tree = $this->livingTree();

        $holder = ($holderKind === 'individual') ? self::individual($tree) : self::family($tree);

        self::assertTrue($holder->canShow(), 'The importing admin has to see the living holder');

        $row = $map($tree);

        self::assertIsArray($row);
        self::assertSame(['cat', 'label', 'value', 'who', 'url'], array_keys($row));
        self::assertSame($value, $row['value']);
        self::assertSame($holder->fullName(), $row['who']);
        self::assertSame($holder->url(), $row['url']);

        Auth::logout();

        $visitorHolder = ($holderKind === 'individual') ? self::individual($tree) : self::family($tree);

        self::assertFalse($visitorHolder->canShow(), 'The visitor must not see the living holder');
        self::assertNull($map($tree), 'the derived value and the URL must not reach a visitor');
    }

    /**
     * The living holder I1, resolved for the current viewer.
     */
    private static function individual(Tree $tree): Individual
    {
        $individual = Registry::individualFactory()->make('I1', $tree);
        self::assertInstanceOf(Individual::class, $individual);

        return $individual;
    }

    /**
     * The living family F1, resolved for the current viewer.
     */
    private static function family(Tree $tree): Family
    {
        $family = Registry::familyFactory()->make('F1', $tree);
        self::assertInstanceOf(Family::class, $family);

        return $family;
    }
}
