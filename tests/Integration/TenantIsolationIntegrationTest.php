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

use function explode;
use function file_get_contents;
use function implode;
use function preg_match;
use function preg_replace;
use function preg_replace_callback;
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
     * Each case names a fixture and a query over the tree imported from it.
     * The query returns a value that carries the counts or measures the
     * repository computes. The optional third element rewrites the twin
     * GEDCOM further, where shifted dates, names and sexes alone leave the
     * links the query reads identical to the links of the tree under test.
     *
     * @return array<string, array{0: string, 1: Closure(Tree): mixed, 2?: Closure(string): string}>
     */
    public static function scopedQueries(): array
    {
        return [
            'top surnames' => [
                'name-trends.ged',
                static fn (Tree $tree): mixed => (new NameRepository($tree))->topSurnames(20),
            ],
            'distinct surnames' => [
                'name-trends.ged',
                static fn (Tree $tree): mixed => (new NameRepository($tree))->countDistinctSurnames(),
            ],
            'top given names of one sex' => [
                'name-trends.ged',
                static fn (Tree $tree): mixed => (new NameRepository($tree))->topGivenNames('M', 1, 20),
            ],
            'top given names of all sexes' => [
                'name-trends.ged',
                static fn (Tree $tree): mixed => (new NameRepository($tree))->topGivenNames(NameRepository::SEX_ALL, 1, 20),
            ],
            'name passdown by century' => [
                'father-son-name-passdown.ged',
                static fn (Tree $tree): mixed => (new NameRepository($tree))->sameSexNamePassdownByCentury(),
            ],
            'given names by decade' => [
                'name-trends.ged',
                static fn (Tree $tree): mixed => (new GivenNameTrendsRepository($tree))->countByDecade(20),
            ],
            'connected components' => [
                'islands.ged',
                static fn (Tree $tree): mixed => (new IslandRepository($tree))->getConnectedComponents(20),
            ],
            'surname marriage matrix' => [
                'surname-marriage-matrix.ged',
                static fn (Tree $tree): mixed => (new MarriageMatrixRepository($tree))->surnameMarriageMatrix(20),
            ],
            'top grandchild families' => [
                'grandchild-families.ged',
                static fn (Tree $tree): mixed => (new FamilyRankingRepository($tree, new StatisticsData($tree, new UserService())))->topGrandchildFamilies(20),
            ],
            'most children of one person' => [
                'children.ged',
                static function (Tree $tree): array {
                    $record = (new FamilyRankingRepository($tree, new StatisticsData($tree, new UserService())))->mostChildrenPerPersonRecord();

                    return ($record instanceof IndividualCountRecord) ? [$record->individual->xref(), $record->count] : [];
                },
            ],
            'source citation coverage' => [
                'tree-health.ged',
                static fn (Tree $tree): mixed => (new TreeHealthRepository($tree))->sourceCitationCoverage(),
                static fn (string $gedcom): string => self::afterEachIndividual($gedcom, '1 SOUR @S1@'),
            ],
            'source citation coverage by century' => [
                'source-coverage-by-century.ged',
                static fn (Tree $tree): mixed => (new TreeHealthRepository($tree))->sourceCitationCoverageByCentury(),
                static fn (string $gedcom): string => self::afterEachIndividual($gedcom, '1 SOUR @S1@'),
            ],
            'missing event gaps' => [
                'tree-health.ged',
                static fn (Tree $tree): mixed => (new TreeHealthRepository($tree))->missingEventGaps(),
                static fn (string $gedcom): string => self::afterEachIndividual($gedcom, '1 FAMS @F1@'),
            ],
            'average generation length' => [
                'tree-health.ged',
                static fn (Tree $tree): mixed => (new TreeHealthRepository($tree))->averageGenerationLength(),
                static fn (string $gedcom): string => str_replace(
                    ['1 CHIL @I3@', '1 CHIL @I4@'],
                    ['1 CHIL @I5@', '1 CHIL @I6@'],
                    $gedcom,
                ),
            ],
            'living marital status' => [
                'marital-status.ged',
                static fn (Tree $tree): mixed => (new FamilyRepository($tree))->classifyLivingIndividuals(),
                static fn (string $gedcom): string => str_replace(
                    '1 WIFE @I4@',
                    '1 WIFE @I2@',
                    self::afterEachIndividual($gedcom, '1 FAMS @F2@'),
                ),
            ],
            'sex ratio anomalies' => [
                'sex-ratio-anomalies.ged',
                static fn (Tree $tree): mixed => (new FamilyRepository($tree))->getSexRatioAnomalies(),
            ],
            'sibling death clusters' => [
                'sibling-death-clusters.ged',
                static fn (Tree $tree): mixed => (new FamilyRepository($tree))->getSiblingDeathClusters(),
            ],
        ];
    }

    /**
     * The result for a tree is the same as for the same data alone in the
     * database, with a twin tree imported before and after it, and it is not
     * trivially empty.
     *
     * @param Closure(Tree): mixed         $query
     * @param Closure(string): string|null $tweakTwin
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

        self::assertNotEmpty($baseline, 'The fixture has to produce a result, else the comparison is vacuous.');

        (new TreeService(new GedcomImportService()))->delete($alone);

        // The twin trees sit before and after the tree under test, so the row
        // order of the tables cannot hide a leak in either direction.
        // The twin after it also swaps the sexes, so a leak that a sex filter
        // would hide in that twin still shows in the other one.
        $twinBefore = $this->shiftedTwin($gedcom, false);
        $twinAfter  = $this->shiftedTwin($gedcom, true);

        if ($tweakTwin instanceof Closure) {
            $twinBefore = $tweakTwin($twinBefore);
            $twinAfter  = $tweakTwin($twinAfter);
        }

        $this->importGedcomTree('twin before', $twinBefore);
        $tree = $this->importGedcomTree('subject', $gedcom);
        $this->importGedcomTree('twin after', $twinAfter);

        self::assertEquals($baseline, $query($tree));
    }

    /**
     * Insert the given line below the header line of each individual record.
     */
    private static function afterEachIndividual(string $gedcom, string $line): string
    {
        return (string) preg_replace('/^(0 @[^@]+@ INDI)$/m', "$1\n" . $line, $gedcom);
    }

    /**
     * Build a GEDCOM that keeps the xrefs of the input and changes the dates
     * and the names, and the sexes on request, so a row of the twin that leaks into a query of the
     * original tree moves its result.
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
