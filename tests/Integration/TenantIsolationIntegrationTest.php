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
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Services\UserService;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualCountRecord;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRankingRepository;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRepository;
use MagicSunday\Webtrees\Statistic\Repository\GivenNameTrendsRepository;
use MagicSunday\Webtrees\Statistic\Repository\IslandRepository;
use MagicSunday\Webtrees\Statistic\Repository\MarriageMatrixRepository;
use MagicSunday\Webtrees\Statistic\Repository\NameRepository;
use MagicSunday\Webtrees\Statistic\Repository\TreeHealthRepository;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_column;
use function array_sum;
use function explode;
use function file_get_contents;
use function implode;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
use function print_r;
use function str_replace;

/**
 * Pins that a repository scoped to one tree returns the same result whether or
 * not other trees sit in the same database. The other trees are built from the
 * same fixture with shifted dates and renamed people, so they reuse the xrefs
 * of the first tree. A query that drops the tree condition of a join lets rows
 * of another tree match on the shared xref, and the result of the first tree
 * changes.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class TenantIsolationIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * Each case names a fixture and a query over the tree imported from it. The
     * query returns the digest of the repository result and a witness. The
     * witness is true when the result carries data that an empty tree cannot
     * yield, such as a counted row or a total above zero. The optional third
     * element rewrites the twin GEDCOM further, where shifted dates, names and
     * sexes alone leave the links the query reads identical to the links of the
     * tree under test.
     *
     * @return array<string, array{0: string, 1: Closure(Tree): array{digest: string, witnessed: bool}, 2?: Closure(string): string}>
     */
    public static function scopedQueries(): array
    {
        return [
            'top surnames' => [
                'name-trends.ged',
                static function (Tree $tree): array {
                    $result = (new NameRepository($tree))->topSurnames(20);

                    return self::outcome($result, $result !== []);
                },
            ],
            'distinct surnames' => [
                'name-trends.ged',
                static function (Tree $tree): array {
                    $result = (new NameRepository($tree))->countDistinctSurnames();

                    return self::outcome($result, $result > 0);
                },
            ],
            'top given names of one sex' => [
                'name-trends.ged',
                static function (Tree $tree): array {
                    $result = (new NameRepository($tree))->topGivenNames('M', 1, 20);

                    return self::outcome($result, $result !== []);
                },
            ],
            'top given names of all sexes' => [
                'name-trends.ged',
                static function (Tree $tree): array {
                    $result = (new NameRepository($tree))->topGivenNames(NameRepository::SEX_ALL, 1, 20);

                    return self::outcome($result, $result !== []);
                },
            ],
            'name passdown by century' => [
                'father-son-name-passdown.ged',
                static function (Tree $tree): array {
                    $result = (new NameRepository($tree))->sameSexNamePassdownByCentury();

                    return self::outcome($result, ($result->categories !== []) && ($result->series !== []));
                },
            ],
            'given names by decade' => [
                'name-trends.ged',
                static function (Tree $tree): array {
                    $result = (new GivenNameTrendsRepository($tree))->countByDecade(20);

                    return self::outcome($result, ($result->decades !== []) && ($result->names !== []));
                },
            ],
            'connected components' => [
                'islands.ged',
                static function (Tree $tree): array {
                    $result = (new IslandRepository($tree))->getConnectedComponents(20);

                    return self::outcome($result, $result->totalPersons > 0);
                },
            ],
            'surname marriage matrix' => [
                'surname-marriage-matrix.ged',
                static function (Tree $tree): array {
                    $result = (new MarriageMatrixRepository($tree))->surnameMarriageMatrix(20);

                    return self::outcome($result, $result->labels !== []);
                },
            ],
            'top grandchild families' => [
                'grandchild-families.ged',
                static function (Tree $tree): array {
                    $result = self::familyRanking($tree)->topGrandchildFamilies(20);

                    return self::outcome($result, $result !== []);
                },
            ],
            'most children of one person' => [
                'children.ged',
                static function (Tree $tree): array {
                    $record = self::familyRanking($tree)->mostChildrenPerPersonRecord();
                    $result = ($record instanceof IndividualCountRecord) ? [$record->individual->xref(), $record->count] : [];

                    return self::outcome($result, $result !== []);
                },
            ],
            'source citation coverage' => [
                'tree-health.ged',
                static function (Tree $tree): array {
                    $result = (new TreeHealthRepository($tree))->sourceCitationCoverage();

                    return self::outcome($result, ($result->total > 0) && ($result->value > 0));
                },
                static fn (string $gedcom): string => self::afterEachIndividual($gedcom, '1 SOUR @S1@'),
            ],
            'source citation coverage by century' => [
                'source-coverage-by-century.ged',
                static function (Tree $tree): array {
                    $result = (new TreeHealthRepository($tree))->sourceCitationCoverageByCentury();

                    return self::outcome($result, $result !== []);
                },
                static fn (string $gedcom): string => self::afterEachIndividual($gedcom, '1 SOUR @S1@'),
            ],
            'missing event gaps' => [
                'tree-health.ged',
                static function (Tree $tree): array {
                    $result = (new TreeHealthRepository($tree))->missingEventGaps();

                    return self::outcome($result, array_sum(array_column($result, 'value')) > 0);
                },
                static fn (string $gedcom): string => self::afterEachIndividual($gedcom, '1 FAMS @F1@'),
            ],
            'average generation length' => [
                'tree-health.ged',
                static function (Tree $tree): array {
                    $result = (new TreeHealthRepository($tree))->averageGenerationLength();

                    return self::outcome($result, $result !== null);
                },
                static fn (string $gedcom): string => str_replace(
                    ['1 CHIL @I3@', '1 CHIL @I4@'],
                    ['1 CHIL @I5@', '1 CHIL @I6@'],
                    $gedcom,
                ),
            ],
            'living marital status' => [
                'marital-status.ged',
                static function (Tree $tree): array {
                    $result = (new FamilyRepository($tree))->classifyLivingIndividuals();

                    return self::outcome($result, array_sum($result) > 0);
                },
                static fn (string $gedcom): string => str_replace(
                    '1 WIFE @I4@',
                    '1 WIFE @I2@',
                    self::afterEachIndividual($gedcom, '1 FAMS @F2@'),
                ),
            ],
            'sex ratio anomalies' => [
                'sex-ratio-anomalies.ged',
                static function (Tree $tree): array {
                    $result = (new FamilyRepository($tree))->getSexRatioAnomalies();

                    return self::outcome($result, $result !== []);
                },
            ],
            'sibling death clusters' => [
                'sibling-death-clusters.ged',
                static function (Tree $tree): array {
                    $result = (new FamilyRepository($tree))->getSiblingDeathClusters();

                    return self::outcome($result, $result !== []);
                },
            ],
        ];
    }

    /**
     * The result for a tree is the same as for the same data alone in the
     * database, with a twin tree imported before and after it. The tree alone
     * yields a result that the witness of the case recognises as data, so the
     * comparison is not made between empty results.
     *
     * @param Closure(Tree): array{digest: string, witnessed: bool} $query
     * @param Closure(string): string|null                          $tweakTwin
     */
    #[Test]
    #[DataProvider('scopedQueries')]
    public function resultIsIndependentOfAnotherTreeInTheDatabase(string $fixture, Closure $query, ?Closure $tweakTwin = null): void
    {
        $gedcom = file_get_contents(__DIR__ . '/../fixtures/' . $fixture);
        self::assertNotFalse($gedcom);

        // The reference result comes from a tree that is alone in the database.
        $alone    = $this->importGedcomTree('alone', $gedcom);
        $baseline = $query($alone);

        self::assertTrue($baseline['witnessed'], 'The tree alone has to yield data that the witness of the case accepts.');

        (new TreeService(new GedcomImportService()))->delete($alone);

        // The twin trees sit before and after the tree under test, so the row
        // order of the tables cannot hide a leak in either direction. The twin
        // after it also swaps the sexes, so a leak that a sex filter would hide
        // in that twin still shows in the other one.
        $twinBefore = $this->shiftedTwin($gedcom, false);
        $twinAfter  = $this->shiftedTwin($gedcom, true);

        if ($tweakTwin instanceof Closure) {
            $twinBefore = $tweakTwin($twinBefore);
            $twinAfter  = $tweakTwin($twinAfter);
        }

        $this->importGedcomTree('twin before', $twinBefore);
        $tree = $this->importGedcomTree('subject', $gedcom);
        $this->importGedcomTree('twin after', $twinAfter);

        self::assertSame($baseline['digest'], $query($tree)['digest']);
    }

    /**
     * The ranking repository for a tree.
     */
    private static function familyRanking(Tree $tree): FamilyRankingRepository
    {
        return new FamilyRankingRepository($tree, new StatisticsData($tree, new UserService()));
    }

    /**
     * Pair the digest of a repository result with the witness of its case.
     *
     * @param array<array-key, int|float|string|object|array<array-key, int|float|string|object|null>|null>|object|int|float|null $result
     *
     * @return array{digest: string, witnessed: bool}
     */
    private static function outcome(array|object|int|float|null $result, bool $witnessed): array
    {
        return ['digest' => print_r($result, true), 'witnessed' => $witnessed];
    }

    /**
     * Insert the given line below the header line of each individual record.
     */
    private static function afterEachIndividual(string $gedcom, string $line): string
    {
        return (string) preg_replace('/^(0 @[^@]+@ INDI)$/m', "$1\n" . $line, $gedcom);
    }

    /**
     * Build a GEDCOM that keeps the xrefs of the input and changes the dates and
     * the names, and the sexes on request, so a row of the twin that leaks into
     * a query of the original tree moves its result.
     */
    private function shiftedTwin(string $gedcom, bool $swapSexes): string
    {
        $lines = explode("\n", $gedcom);

        foreach ($lines as $index => $line) {
            if (preg_match('/^\d DATE /', $line) === 1) {
                $shift = (($index % 4) + 1) * (($index % 2 === 0) ? 1 : -1);

                $lines[$index] = (string) preg_replace_callback(
                    '/\b(\d{4})\b/',
                    static fn (array $match): string => (string) (((int) $match[1]) + $shift),
                    $line,
                );

                continue;
            }

            if ($swapSexes && (preg_match('/^1 SEX [MF]$/', $line) === 1)) {
                $lines[$index] = ($line === '1 SEX M') ? '1 SEX F' : '1 SEX M';

                continue;
            }

            $lines[$index] = (string) preg_replace('/^(\d NAME )([^\/]*[^\/ ])\s*\/([^\/]*)\/(.*)$/', '$1$2z /$3z/$4', $line);
        }

        return implode("\n", $lines);
    }
}
