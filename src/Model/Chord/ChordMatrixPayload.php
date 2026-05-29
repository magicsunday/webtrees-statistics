<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Chord;

use JsonSerializable;

/**
 * Symmetric N×N matrix payload for the chart-lib chord-diagram widget.
 * `labels[i]` names the i-th arc; `matrix[i][j]` is the connection strength
 * between arc i and arc j. Endogamous self- connections sit on the diagonal.
 *
 * Currently produced by `MarriageMatrixRepository::surnameMarriageMatrix()` for
 * the Names-tab surname × surname marriage chord diagram, but the shape is
 * generic enough for any future symmetric-matrix consumer (family-pair kinship
 * density, source-citation overlap, …).
 *
 * Serialises to `{labels: list<string>, matrix: list<list<int>>}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ChordMatrixPayload implements JsonSerializable
{
    /**
     * @param list<string>    $labels Arc names in display order (typically alphabetical so the chord layout stays stable)
     * @param list<list<int>> $matrix Symmetric N×N connection counts; matrix[i][j] === matrix[j][i] for every off-diagonal cell
     */
    public function __construct(
        public array $labels,
        public array $matrix,
    ) {
    }

    /**
     * @return array{labels: list<string>, matrix: list<list<int>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'labels' => $this->labels,
            'matrix' => $this->matrix,
        ];
    }
}
