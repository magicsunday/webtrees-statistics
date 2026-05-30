<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Heatmap;

use JsonSerializable;

/**
 * Wire-format payload for the chart-lib heatmap widget on the LifeSpan tab.
 * `rows` is the vertical axis (one period-start year per row, chronological
 * order); `cols` is the horizontal axis (twelve abbreviated month labels,
 * January first); `colTitles` is the parallel set of full month names the
 * widget shows in its tooltip; `values[rowIdx][colIdx]` is the event count that
 * fell in that period × month cell.
 *
 * Serialises to `{rows: list<string>, cols: list<string>, colTitles:
 * list<string>, values: list<list<int>>}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class HeatmapPayload implements JsonSerializable
{
    /**
     * @param list<string>    $rows      Period-start year labels in chronological order (top to bottom)
     * @param list<string>    $cols      Abbreviated month labels, January first (left to right)
     * @param list<list<int>> $values    Per-period row of month counts, one entry per column
     * @param list<string>    $colTitles Full month names parallel to $cols, shown in the tooltip
     */
    public function __construct(
        public array $rows,
        public array $cols,
        public array $values,
        public array $colTitles = [],
    ) {
    }

    /**
     * @return array{rows: list<string>, cols: list<string>, colTitles: list<string>, values: list<list<int>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'rows'      => $this->rows,
            'cols'      => $this->cols,
            'colTitles' => $this->colTitles,
            'values'    => $this->values,
        ];
    }
}
