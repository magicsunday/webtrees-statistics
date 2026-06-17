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
use MagicSunday\Webtrees\Statistic\Model\Chord\ChordMatrixPayload;
use MagicSunday\Webtrees\Statistic\Repository\MarriageMatrixRepository;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_sum;
use function count;

/**
 * End-to-end test of {@see MarriageMatrixRepository} against a curated fixture
 * with four surnames and five cross-surname marriages:
 *
 *   F1: Gustav /Klein/  × Hilde  /Weaver/
 *   F2: Anton  /Miller/ × Berta  /Smith/
 *   F3: Carl   /Miller/ × Doris  /Weaver/
 *   F4: Emil   /Smith/  × Frieda /Miller/
 *   F5: Ingo   /Smith/  × Jutta  /Klein/
 *
 * The Klein×Weaver family carries the lowest f_id (F1) on purpose, so the
 * count-2 surnames head the natural row order: the top-N cap test thus genuinely
 * exercises the frequency ranking (which must put the count-3 Miller/Smith
 * ahead) rather than passing on row order alone.
 *
 * Surname marriage counts (each marriage contributes once per partner, only
 * when the surnames differ — no endogamy in this fixture):
 *   Miller → 3 (F2 husband, F3 husband, F4 wife)
 *   Smith  → 3 (F2 wife, F4 husband, F5 husband)
 *   Klein  → 2 (F1 husband, F5 wife)
 *   Weaver → 2 (F1 wife, F3 wife)
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
#[CoversClass(MarriageMatrixRepository::class)]
#[UsesClass(ChordMatrixPayload::class)]
#[UsesClass(RowCast::class)]
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
        self::assertSame(2, $matrix[1][2], 'Miller × Smith = F2 + F4 = 2');
        self::assertSame(1, $matrix[1][3], 'Miller × Weaver = F3 = 1');
        self::assertSame(1, $matrix[0][3], 'Klein × Weaver = F1 = 1');
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
     * (Miller=3, Smith=3) survive — the alphabetical display sort favours
     * Miller-then-Smith.
     *
     * The Klein×Weaver family carries the lowest f_id (F1), so the count-2
     * surnames Klein and Weaver head the natural row order. A regression that
     * dropped the frequency ranking in {@see TopNAggregator::rankKeys()} before
     * the cap would slice those two off the front instead of Miller/Smith and
     * fail this assertion.
     */
    #[Test]
    public function surnameMarriageMatrixHonoursTopNCap(): void
    {
        $tree   = $this->importFixtureTree('surname-marriage-matrix.ged');
        $result = $this->repository($tree)->surnameMarriageMatrix(2);

        // The surviving set must be EXACTLY the two highest-count surnames, in
        // the production's alphabetical order. This catches a dropped frequency
        // ranking: the Klein×Weaver family is @F1@, so the count-2 surnames
        // Klein and Weaver head the natural row order — without the count-first
        // ranking the cap would slice them off the front, yielding
        // ['Klein', 'Weaver'] and failing this exact-match assertion.
        self::assertSame(['Miller', 'Smith'], $result->labels);
        self::assertCount(2, $result->matrix);
        self::assertCount(2, $result->matrix[0]);
    }

    /**
     * A spouse carrying an alternate name form on the primary record — a `ROMN`
     * transliteration here, representative of the `FONE`/`_HEB`/`_AKA` family —
     * must contribute exactly one surname to the matrix. webtrees stores each
     * name form as its own `name` row sharing the individual's `n_id`, so the
     * surname join must restrict to the primary `NAME` row rather than merely
     * excluding `_MARNM`; otherwise the single marriage fans out across every
     * name form and is double-counted.
     *
     * The fixture marries Iwan /Roman/ (primary) — who also carries the `ROMN`
     * surname /Romanov/ — to Olga /Petrov/. The matrix must hold only the two
     * primary surnames Petrov and Roman; the romanised /Romanov/ must never
     * surface as a third surname, and the single marriage must contribute a
     * total mass of exactly two mirrored cells (not four).
     */
    #[Test]
    public function surnameMarriageMatrixCountsAlternateNameFormSpouseOnce(): void
    {
        $tree   = $this->importFixtureTree('surname-marriage-matrix-name-fanout.ged');
        $result = $this->repository($tree)->surnameMarriageMatrix(8);

        self::assertSame(['Petrov', 'Roman'], $result->labels);

        $total = 0;

        foreach ($result->matrix as $row) {
            $total += array_sum($row);
        }

        self::assertSame(2, $total, 'One marriage mirrors to two cells — the ROMN form must not double-count it');
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

    /**
     * Equal-count surnames at the Top-N boundary are broken on the surname in
     * PHP byte order via the shared {@see TopNAggregator::rankKeys()}, never on
     * the database row order. The fixture has Zander (×2) plus the count-1 pair
     * Zulu (family @F1@, the lower f_id) and Alpha (family @F2@). At Top-2 the
     * tie between Zulu and Alpha decides the second slot: byte order keeps the
     * lower "Alpha" — yielding the alphabetical labels ['Alpha', 'Zander'] —
     * whereas a row-order tie-break would keep the f_id-first "Zulu" and produce
     * ['Zander', 'Zulu']. This pins the engine-independent tie-break across
     * SQLite (CI) and MySQL.
     */
    #[Test]
    public function surnameMarriageMatrixBreaksTopNTiesByByteOrderNotRowOrder(): void
    {
        $tree   = $this->importFixtureTree('surname-marriage-matrix-tiebreak.ged');
        $result = $this->repository($tree)->surnameMarriageMatrix(2);

        self::assertSame(['Alpha', 'Zander'], $result->labels);
        self::assertCount(2, $result->matrix);
    }
}
