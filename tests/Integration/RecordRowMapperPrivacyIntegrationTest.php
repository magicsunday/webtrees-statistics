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
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

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
final class RecordRowMapperPrivacyIntegrationTest extends AbstractIntegrationTestCase
{
    private function livingTree(): Tree
    {
        $tree = $this->importFixtureTree('record-living-holder.ged');
        $this->hideLivingPeople($tree);

        return $tree;
    }

    /**
     * One case per factory method of the mapper. Each resolves the living holder
     * I1 or the living family F1, builds the record for it and maps it, and states
     * the row value, the category and the label the importing admin gets.
     *
     * @return array<string, array{Closure(Tree): (Individual|Family), Closure(Tree): ?array{cat: string, label: string, value: string, who: string, url: string}, string, string, string}>
     */
    public static function recordFactories(): array
    {
        return [
            'individual age' => [
                self::livingIndividual(...),
                static fn (Tree $tree): ?array => RecordRowMapper::years(
                    RecordCategory::Life,
                    'Oldest living',
                    new IndividualAgeRecord(self::livingIndividual($tree), 15),
                ),
                '15 years',
                'life',
                'Oldest living',
            ],
            'family years' => [
                self::family(...),
                static fn (Tree $tree): ?array => RecordRowMapper::familyYears(
                    RecordCategory::Family,
                    'Longest marriage',
                    new FamilyDurationYearsRecord(self::family($tree), 16),
                ),
                '16 years',
                'family',
                'Longest marriage',
            ],
            'family days' => [
                self::family(...),
                static fn (Tree $tree): ?array => RecordRowMapper::familyDays(
                    RecordCategory::Family,
                    'Shortest marriage',
                    new FamilyDurationDaysRecord(self::family($tree), 17),
                ),
                '17 days',
                'family',
                'Shortest marriage',
            ],
            'individual marriages' => [
                self::livingIndividual(...),
                static fn (Tree $tree): ?array => RecordRowMapper::marriages(
                    RecordCategory::Family,
                    'Most spouses',
                    new IndividualCountRecord(self::livingIndividual($tree), 2),
                ),
                '2 marriages',
                'family',
                'Most spouses',
            ],
            'individual children' => [
                self::livingIndividual(...),
                static fn (Tree $tree): ?array => RecordRowMapper::children(
                    RecordCategory::Family,
                    'Most children',
                    new IndividualCountRecord(self::livingIndividual($tree), 3),
                ),
                '3 children',
                'family',
                'Most children',
            ],
            'family children' => [
                self::family(...),
                static fn (Tree $tree): ?array => RecordRowMapper::familyChildren(
                    RecordCategory::Family,
                    'Largest family',
                    new FamilyCountRecord(self::family($tree), 4),
                ),
                '4 children',
                'family',
                'Largest family',
            ],
        ];
    }

    /**
     * A record whose holder is a living, non-visible record maps to a full row
     * for the importing admin and is dropped for a visitor. The admin row shows
     * that the holder exists and that the gate is what drops it for the visitor.
     *
     * @param Closure(Tree): (Individual|Family)                                                         $holderOf Resolves the living holder for the current viewer
     * @param Closure(Tree): ?array{cat: string, label: string, value: string, who: string, url: string} $map
     * @param string                                                                                     $value    Value text of the admin row
     * @param string                                                                                     $category Category class of the admin row
     * @param string                                                                                     $label    Label of the admin row
     */
    #[Test]
    #[DataProvider('recordFactories')]
    public function recordIsMappedForTheAdminAndDroppedForAVisitor(Closure $holderOf, Closure $map, string $value, string $category, string $label): void
    {
        $tree = $this->livingTree();

        $holder = $holderOf($tree);

        self::assertTrue($holder->canShow(), 'The importing admin has to see the living holder');

        $row = $map($tree);

        self::assertIsArray($row);
        self::assertSame(['cat', 'label', 'value', 'who', 'url'], array_keys($row));
        self::assertSame($category, $row['cat']);
        self::assertSame($label, $row['label']);
        self::assertSame($value, $row['value']);
        self::assertSame($holder->fullName(), $row['who']);
        self::assertSame($holder->url(), $row['url']);

        Auth::logout();

        $visitorHolder = $holderOf($tree);

        self::assertFalse($visitorHolder->canShow(), 'The visitor must not see the living holder');
        self::assertNull($map($tree), 'the derived value and the URL must not reach a visitor');
    }

    /**
     * The living holder I1, resolved for the current viewer.
     */
    private static function livingIndividual(Tree $tree): Individual
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
