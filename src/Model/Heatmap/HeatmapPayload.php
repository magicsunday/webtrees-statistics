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
 * `rows` is the vertical axis (one localised decade label per row, chronological
 * order); `cols` is the horizontal axis (twelve localised month labels, January
 * first); `values[rowIdx][colIdx]` is the event count that fell in that decade ×
 * month cell.
 *
 * Serialises to `{rows: list<string>, cols: list<string>, values:
 * list<list<int>>}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class HeatmapPayload implements JsonSerializable
{
    /**
     * @param list<string>    $rows   Localised decade labels in chronological order (top to bottom)
     * @param list<string>    $cols   Localised month labels, January first (left to right)
     * @param list<list<int>> $values Per-decade row of month counts, one entry per column
     */
    public function __construct(
        public array $rows,
        public array $cols,
        public array $values,
    ) {
    }

    /**
     * @return array{rows: list<string>, cols: list<string>, values: list<list<int>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'rows'   => $this->rows,
            'cols'   => $this->cols,
            'values' => $this->values,
        ];
    }
}
