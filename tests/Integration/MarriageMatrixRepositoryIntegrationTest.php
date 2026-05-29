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
use MagicSunday\Webtrees\Statistic\Repository\MarriageMatrixRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_sum;
use function count;

/**
 * End-to-end test of {@see MarriageMatrixRepository} against a curated fixture
 * with four surnames and five cross-surname marriages:
 *
 *   F1: Anton  /Miller/ × Berta  /Smith/
 *   F2: Carl   /Miller/ × Doris  /Weaver/
 *   F3: Emil   /Smith/  × Frieda /Miller/
 *   F4: Gustav /Klein/  × Hilde  /Weaver/
 *   F5: Ingo   /Smith/  × Jutta  /Klein/
 *
 * Surname marriage counts (each marriage contributes once per partner, only
 * when the surnames differ — no endogamy in this fixture):
 *   Miller → 3 (F1 husband, F2 husband, F3 wife)
 *   Smith  → 3 (F1 wife, F3 husband, F5 husband)
 *   Klein  → 2 (F4 husband, F5 wife)
 *   Weaver → 2 (F2 wife, F4 wife)
 *
 * The expected symmetric matrix (alphabetical label order — Klein, Miller,
 * Smith, Weaver):
 *
 *              Klein  Miller  Smith  Weaver
 *     Klein    0      0       1      1
 *     Miller   0      0       2      1
 *     Smith    1      2       0      0
 *     Weaver   1      1       0      0
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class MarriageMatrixRepositoryIntegrationTest extends IntegrationTestCase
{
    private function repository(Tree $tree): MarriageMatrixRepository
    {
        return new MarriageMatrixRepository($tree);
    }

    /**
     * The top-N selection picks all four surnames in the fixture (every surname
     * appears in at least one marriage) and returns them alphabetically sorted
     * so the chord layout stays stable.
     */
    #[Test]
    public function surnameMarriageMatrixReturnsAlphabeticallySortedTopNLabels(): void
    {
        $tree   = $this->importFixtureTree('surname-marriage-matrix.ged');
        $result = $this->repository($tree)->surnameMarriageMatrix(8);

        self::assertSame(['Klein', 'Miller', 'Smith', 'Weaver'], $result->labels);
    }

    /**
     * Every off-diagonal cell mirrors its counterpart — the chord widget
     * consumes a symmetric matrix and the repository's counting rule (mirror
     * increment per marriage) guarantees it.
     */
    #[Test]
    public function surnameMarriageMatrixIsSymmetric(): void
    {
        $tree   = $this->importFixtureTree('surname-marriage-matrix.ged');
        $result = $this->repository($tree)->surnameMarriageMatrix(8);

        $matrix = $result->matrix;
        $n      = count($matrix);

        for ($i = 0; $i < $n; ++$i) {
            for ($j = $i + 1; $j < $n; ++$j) {
                self::assertSame(
                    $matrix[$i][$j],
                    $matrix[$j][$i],
                    sprintf('Matrix asymmetric at [%d][%d] vs [%d][%d]', $i, $j, $j, $i),
                );
            }
        }
    }

    /**
     * Every off-diagonal pair holds exactly the marriage count the fixture
     * wires up. Diagonal cells stay zero — the fixture has no endogamous
     * (same-surname) marriages.
     */
    #[Test]
    public function surnameMarriageMatrixCountsEveryFixtureMarriage(): void
    {
        $tree   = $this->importFixtureTree('surname-marriage-matrix.ged');
        $result = $this->repository($tree)->surnameMarriageMatrix(8);

        // Labels resolve to indices Klein=0, Miller=1, Smith=2, Weaver=3.
        $matrix = $result->matrix;

        // Diagonal: zero across the board (no endogamous marriages).
        self::assertSame(0, $matrix[0][0], 'Klein-Klein: no endogamy');
        self::assertSame(0, $matrix[1][1], 'Miller-Miller: no endogamy');
        self::assertSame(0, $matrix[2][2], 'Smith-Smith: no endogamy');
        self::assertSame(0, $matrix[3][3], 'Weaver-Weaver: no endogamy');

        // Off-diagonal counts (already symmetric):
        self::assertSame(2, $matrix[1][2], 'Miller × Smith = F1 + F3 = 2');
        self::assertSame(1, $matrix[1][3], 'Miller × Weaver = F2 = 1');
        self::assertSame(1, $matrix[0][3], 'Klein × Weaver = F4 = 1');
        self::assertSame(1, $matrix[0][2], 'Klein × Smith = F5 = 1');
        self::assertSame(0, $matrix[1][0], 'Klein × Miller: no fixture marriage');

        // Total mass of the matrix is 2 × (number of marriages) because
        // every off-diagonal marriage gets mirrored. Five marriages
        // × 2 mirrors = 10.
        $total = 0;

        foreach ($matrix as $row) {
            $total += array_sum($row);
        }

        self::assertSame(10, $total, 'Symmetric mirror counts five marriages as ten cells');
    }

    /**
     * Top-N truncation keeps the top-ranked surnames and drops the tail. With
     * `topN = 2` only the two surnames with the highest marriage involvement
     * (Miller=3, Smith=3) survive — alphabet tie-break favours
     * Miller-then-Smith.
     */
    #[Test]
    public function surnameMarriageMatrixHonoursTopNCap(): void
    {
        $tree   = $this->importFixtureTree('surname-marriage-matrix.ged');
        $result = $this->repository($tree)->surnameMarriageMatrix(2);

        self::assertCount(2, $result->labels);
        self::assertContains('Miller', $result->labels);
        self::assertContains('Smith', $result->labels);
        self::assertCount(2, $result->matrix);
        self::assertCount(2, $result->matrix[0]);
    }

    /**
     * `topN = 0` (and any non-positive value) short-circuits to an empty result
     * so the view layer can skip the chord-diagram card without rendering an
     * empty chart.
     */
    #[Test]
    public function surnameMarriageMatrixReturnsEmptyForNonPositiveTopN(): void
    {
        $tree   = $this->importFixtureTree('surname-marriage-matrix.ged');
        $result = $this->repository($tree)->surnameMarriageMatrix(0);

        self::assertSame([], $result->labels);
        self::assertSame([], $result->matrix);
    }
}
