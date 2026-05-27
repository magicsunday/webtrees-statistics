<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Model\Chord\ChordMatrixPayload;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function array_flip;
use function array_keys;
use function array_slice;
use function array_values;
use function arsort;
use function count;
use function sort;

/**
 * Surname × surname marriage matrix for the chord-diagram widget
 * on the Names tab. Each arc is one of the top-N surnames in the
 * tree; the ribbon between two arcs encodes the number of marriages
 * between those surnames across the whole tree.
 *
 * The matrix is symmetric — a marriage with husband-surname A and
 * wife-surname B contributes one unit to both `matrix[A][B]` and
 * `matrix[B][A]`. Endogamous marriages (same surname on both sides)
 * land on the diagonal once. That mirrors what the chord-diagram
 * widget expects and keeps the visual ribbon-thickness honest.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MarriageMatrixRepository
{
    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Surname × surname marriage matrix, restricted to the top-N
     * surnames by marriage count (each marriage contributes to two
     * surname totals — husband-side and wife-side). Labels are
     * alphabetically sorted so the chord layout stays stable
     * across renders.
     *
     * @param int $topN Cap on the number of arcs in the chord diagram. 8 is a comfortable upper bound for most trees; the widget gets unreadable beyond ~12 arcs.
     */
    public function surnameMarriageMatrix(int $topN): ChordMatrixPayload
    {
        if ($topN <= 0) {
            return new ChordMatrixPayload(labels: [], matrix: []);
        }

        $pairs = $this->collectMarriagePairs();

        if ($pairs === []) {
            return new ChordMatrixPayload(labels: [], matrix: []);
        }

        $labels = $this->selectTopSurnames($pairs, $topN);

        if ($labels === []) {
            return new ChordMatrixPayload(labels: [], matrix: []);
        }

        return new ChordMatrixPayload(
            labels: $labels,
            matrix: $this->buildMatrix($pairs, $labels),
        );
    }

    /**
     * Pull every (husband-surname, wife-surname) pair from the tree.
     * Husband and wife surnames come from the primary `NAME` record
     * (anything other than `_MARNM` — webtrees' married-name type)
     * so a wife stays under her birth surname even when her record
     * also carries a married-name alias.
     *
     * Empty surnames and the NOMEN_NESCIO placeholder are dropped:
     * those rows can't carry information in a surname-pair matrix.
     *
     * @return list<array{h: string, w: string}>
     */
    private function collectMarriagePairs(): array
    {
        $rows = DB::table('families AS f')
            ->join('name AS hn', static function (JoinClause $join): void {
                $join
                    ->on('hn.n_file', '=', 'f.f_file')
                    ->on('hn.n_id', '=', 'f.f_husb')
                    ->where('hn.n_type', '<>', '_MARNM');
            })
            ->join('name AS wn', static function (JoinClause $join): void {
                $join
                    ->on('wn.n_file', '=', 'f.f_file')
                    ->on('wn.n_id', '=', 'f.f_wife')
                    ->where('wn.n_type', '<>', '_MARNM');
            })
            ->where('f.f_file', '=', $this->tree->id())
            ->where('f.f_husb', '<>', '')
            ->where('f.f_wife', '<>', '')
            ->whereNotIn('hn.n_surn', ['', Individual::NOMEN_NESCIO])
            ->whereNotIn('wn.n_surn', ['', Individual::NOMEN_NESCIO])
            ->select(['f.f_id', 'hn.n_surn AS h_surn', 'wn.n_surn AS w_surn'])
            ->distinct()
            ->get();

        $pairs = [];

        foreach ($rows as $row) {
            $h = RowCast::string($row, 'h_surn');
            $w = RowCast::string($row, 'w_surn');

            if ($h === '') {
                continue;
            }

            if ($w === '') {
                continue;
            }

            $pairs[] = ['h' => $h, 'w' => $w];
        }

        return $pairs;
    }

    /**
     * Rank every surname by the count of marriages it appears in
     * (husband-side OR wife-side), then keep the top N. Returned
     * labels are alphabetically sorted so the chord layout stays
     * stable across renders.
     *
     * @param list<array{h: string, w: string}> $pairs
     * @param int                               $topN
     *
     * @return list<string>
     */
    private function selectTopSurnames(array $pairs, int $topN): array
    {
        $totals = [];

        foreach ($pairs as $pair) {
            $totals[$pair['h']] = ($totals[$pair['h']] ?? 0) + 1;

            if ($pair['w'] !== $pair['h']) {
                $totals[$pair['w']] = ($totals[$pair['w']] ?? 0) + 1;
            }
        }

        arsort($totals);

        $top = array_slice(array_keys($totals), 0, $topN);
        sort($top);

        return $top;
    }

    /**
     * Build the symmetric N×N matrix. Counting rule: one marriage
     * with husband-surname A and wife-surname B contributes 1 to
     * `matrix[A][B]` AND 1 to `matrix[B][A]` (the symmetric mirror),
     * unless A == B (endogamous), in which case it contributes 1
     * to `matrix[A][A]` once.
     *
     * @param list<array{h: string, w: string}> $pairs
     * @param list<string>                      $labels Alphabetically sorted top-N surnames
     *
     * @return list<list<int>>
     */
    private function buildMatrix(array $pairs, array $labels): array
    {
        $index  = array_flip($labels);
        $n      = count($labels);
        $matrix = [];

        for ($i = 0; $i < $n; ++$i) {
            $row = [];

            for ($j = 0; $j < $n; ++$j) {
                $row[] = 0;
            }

            $matrix[] = $row;
        }

        foreach ($pairs as $pair) {
            $hi = $index[$pair['h']] ?? null;
            $wi = $index[$pair['w']] ?? null;

            if ($hi === null) {
                continue;
            }

            if ($wi === null) {
                continue;
            }

            ++$matrix[$hi][$wi];

            if ($hi !== $wi) {
                ++$matrix[$wi][$hi];
            }
        }

        // Re-pack each row through array_values so PHPStan sees the
        // increment-only updates as list<int>, not array<int, int>.
        $out = [];

        foreach ($matrix as $row) {
            $out[] = array_values($row);
        }

        return $out;
    }
}
