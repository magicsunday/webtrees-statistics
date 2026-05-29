<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Pyramid;

use JsonSerializable;

/**
 * Wire-format payload for the chart-lib population-pyramid widget on the
 * LifeSpan tab. `centuries` is the picker axis (one localised century label per
 * column, chronological order); `bands` is the shared age-at-death band axis in
 * top-to-bottom order (oldest band first so the pyramid reads conventionally);
 * `data[centuryIdx][bandIdx]` is the male/female count pair for that century ×
 * band cell.
 *
 * Serialises to `{centuries: list<string>, bands: list<string>, data:
 * list<list<array{m: int, f: int}>>}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class PopulationPyramidPayload implements JsonSerializable
{
    /**
     * @param list<string>                      $centuries Localised century labels in chronological order
     * @param list<string>                      $bands     Age-at-death band labels, oldest first (top of the pyramid)
     * @param list<list<array{m: int, f: int}>> $data      Per-century column of `{m, f}` count pairs, one entry per band
     */
    public function __construct(
        public array $centuries,
        public array $bands,
        public array $data,
    ) {
    }

    /**
     * @return array{centuries: list<string>, bands: list<string>, data: list<list<array{m: int, f: int}>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'centuries' => $this->centuries,
            'bands'     => $this->bands,
            'data'      => $this->data,
        ];
    }
}
