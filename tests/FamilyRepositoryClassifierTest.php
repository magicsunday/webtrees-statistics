<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use MagicSunday\Webtrees\Statistic\Model\FamilyRow;
use MagicSunday\Webtrees\Statistic\Model\MaritalBucket;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRepository;
use MagicSunday\Webtrees\Statistic\Support\GedcomScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

use function assert;
use function is_string;

/**
 * Exercises the pure-PHP classification rules of {@see FamilyRepository}
 * (partnerIdOf, hasAnyTagAnchored, classifyOneIndividual) without touching
 * the database. The DB-bound public method is covered separately by
 * browser-verified live trees; this test locks in the precedence semantics
 * that the audit-loop deemed correctness-critical.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class FamilyRepositoryClassifierTest extends TestCase
{
    /**
     * @return iterable<string, array{FamilyRow, ?string}>
     */
    public static function partnerIdRows(): iterable
    {
        yield 'wife-side returns husband' => [
            new FamilyRow('I_W', 'I_H', 'I_W', null),
            'I_H',
        ];
        yield 'husb-side returns wife' => [
            new FamilyRow('I_H', 'I_H', 'I_W', null),
            'I_W',
        ];
        yield 'empty husb is treated as null' => [
            new FamilyRow('I_W', null, 'I_W', null),
            null,
        ];
        yield 'empty wife is treated as null' => [
            new FamilyRow('I_H', 'I_H', null, null),
            null,
        ];
        yield 'both null returns null' => [
            new FamilyRow('I_X', null, null, null),
            null,
        ];
        yield 'self-marriage corruption returns null' => [
            new FamilyRow('I_S', 'I_S', 'I_S', null),
            null,
        ];
    }

    /**
     * The partner-resolution helper must cover every edge case that real
     * webtrees databases can produce.
     */
    #[Test]
    #[DataProvider('partnerIdRows')]
    public function partnerIdOfHandlesEdgeCases(FamilyRow $row, ?string $expected): void
    {
        $actual = $this->invokePartnerIdOf($row);
        self::assertSame($expected, $actual);
    }

    /**
     * @return iterable<string, array{string, list<string>, bool}>
     */
    public static function anchoredTagSamples(): iterable
    {
        yield 'DIV with continuation line is found' => ["\n0 @F1@ FAM\n1 DIV\n2 DATE 1 JAN 2010", ['DIV'], true];
        yield 'DIV with space-and-payload is found' => ["\n0 @F1@ FAM\n1 DIV Y", ['DIV'], true];
        yield 'DIV at end-of-string is found' => ["\n0 @F1@ FAM\n1 DIV", ['DIV'], true];
        yield 'DIVF does NOT match DIV anchor' => ["\n0 @F1@ FAM\n1 DIVF\n", ['DIV'], false];
        yield 'absence returns false' => ["\n0 @F1@ FAM\n1 MARR", ['DIV'], false];
        yield 'either of two tags matches' => ["\n0 @F1@ FAM\n1 ANUL\n", ['DIV', 'ANUL'], true];
    }

    /**
     * Anchored tag matching must distinguish `1 DIV` from `1 DIVF` so the
     * "Divorced" bucket does not absorb annulment-filed records. The
     * classifier delegates to {@see GedcomScanner::hasAnyTagAnchored()},
     * so the contract test runs against the shared helper directly.
     *
     * @param list<string> $tags
     */
    #[Test]
    #[DataProvider('anchoredTagSamples')]
    public function hasAnyTagAnchoredRecognisesLevelOneTags(string $gedcom, array $tags, bool $expected): void
    {
        self::assertSame($expected, GedcomScanner::hasAnyTagAnchored($gedcom, $tags));
    }

    /**
     * Remarried-after-divorce: living person has one DIV family AND one
     * current MARR family. Precedence current > divorced must put them in
     * the current bucket, never both.
     */
    #[Test]
    public function remarriedSurvivorClassifiesAsCurrent(): void
    {
        $rows = [
            new FamilyRow('I1', 'I1', 'I2', "\n0 @F1@ FAM\n1 MARR\n1 DIV\n"),
            new FamilyRow('I1', 'I1', 'I3', "\n0 @F2@ FAM\n1 MARR\n"),
        ];

        $bucket = $this->invokeClassify($rows, ['I2' => false, 'I3' => false]);

        self::assertSame(MaritalBucket::Current, $bucket);
    }

    /**
     * Remarried-after-widowed: precedence current > widowed.
     */
    #[Test]
    public function remarriedAfterWidowedClassifiesAsCurrent(): void
    {
        $rows = [
            new FamilyRow('I1', 'I1', 'I_DEAD', "\n0 @F1@ FAM\n1 MARR\n"),
            new FamilyRow('I1', 'I1', 'I_ALIVE', "\n0 @F2@ FAM\n1 MARR\n"),
        ];

        $bucket = $this->invokeClassify($rows, ['I_DEAD' => true, 'I_ALIVE' => false]);

        self::assertSame(MaritalBucket::Current, $bucket);
    }

    /**
     * Surviving spouse of a deceased partner classifies as widowed when
     * the only family carries MARR + dead partner.
     */
    #[Test]
    public function widowedSurvivorClassifiesAsWidowed(): void
    {
        $rows = [
            new FamilyRow('I1', 'I1', 'I_DEAD', "\n0 @F1@ FAM\n1 MARR\n"),
        ];

        $bucket = $this->invokeClassify($rows, ['I_DEAD' => true]);

        self::assertSame(MaritalBucket::Widowed, $bucket);
    }

    /**
     * Sole DIV family classifies the survivor as divorced.
     */
    #[Test]
    public function divorcedSurvivorClassifiesAsDivorced(): void
    {
        $rows = [
            new FamilyRow('I1', 'I1', 'I2', "\n0 @F1@ FAM\n1 MARR\n1 DIV\n"),
        ];

        $bucket = $this->invokeClassify($rows, ['I2' => false]);

        self::assertSame(MaritalBucket::Divorced, $bucket);
    }

    /**
     * Individual with no family-membership rows (LEFT JOIN miss) is single.
     */
    #[Test]
    public function noFamilyClassifiesAsSingle(): void
    {
        $rows   = [new FamilyRow('I1', null, null, null)];
        $bucket = $this->invokeClassify($rows, []);

        self::assertSame(MaritalBucket::Single, $bucket);
    }

    /**
     * Orphaned spouse XREF (partner record absent from individuals table)
     * does NOT class the survivor as current or widowed; abstention keeps
     * the bucket count conservative.
     */
    #[Test]
    public function orphanedSpouseXrefAbstainsFromClassification(): void
    {
        $rows = [
            new FamilyRow('I1', 'I1', 'I_GHOST', "\n0 @F1@ FAM\n1 MARR\n"),
        ];
        // I_GHOST is intentionally NOT in $partnerStates → partner unknown.
        $bucket = $this->invokeClassify($rows, []);

        self::assertSame(MaritalBucket::Single, $bucket);
    }

    /**
     * A `_NMR` family (Brothers-Keeper "not married") must NOT be treated
     * as a marriage even though Gedcom::MARRIAGE_EVENTS lists it. The
     * survivor stays in the single bucket.
     */
    #[Test]
    public function nmrFamilyDoesNotClassifyAsCurrent(): void
    {
        $rows = [
            new FamilyRow('I1', 'I1', 'I2', "\n0 @F1@ FAM\n1 _NMR\n"),
        ];

        $bucket = $this->invokeClassify($rows, ['I2' => false]);

        self::assertSame(MaritalBucket::Single, $bucket);
    }

    /**
     * A `_SEPR` family (separated but not legally divorced) is NOT a
     * divorce per webtrees Census semantics; with `1 MARR` still present
     * the survivor stays in the current bucket.
     */
    #[Test]
    public function seprFamilyDoesNotClassifyAsDivorced(): void
    {
        $rows = [
            new FamilyRow('I1', 'I1', 'I2', "\n0 @F1@ FAM\n1 MARR\n1 _SEPR\n"),
        ];

        $bucket = $this->invokeClassify($rows, ['I2' => false]);

        self::assertSame(MaritalBucket::Current, $bucket);
    }

    /**
     * @param list<FamilyRow>     $rows
     * @param array<string, bool> $partnerStates
     */
    private function invokeClassify(array $rows, array $partnerStates): MaritalBucket
    {
        $method = new ReflectionMethod(FamilyRepository::class, 'classifyOneIndividual');
        $repo   = $this->newRepoWithoutTree();
        $result = $method->invoke($repo, $rows, $partnerStates);

        assert($result instanceof MaritalBucket);

        return $result;
    }

    private function invokePartnerIdOf(FamilyRow $row): ?string
    {
        $method = new ReflectionMethod(FamilyRepository::class, 'partnerIdOf');
        $repo   = $this->newRepoWithoutTree();
        $result = $method->invoke($repo, $row);

        assert(($result === null) || is_string($result));

        return $result;
    }

    /**
     * Instantiate FamilyRepository without invoking the Tree-bound
     * constructor — the helpers under test never read $this->tree.
     */
    private function newRepoWithoutTree(): FamilyRepository
    {
        return (new ReflectionClass(FamilyRepository::class))->newInstanceWithoutConstructor();
    }
}
