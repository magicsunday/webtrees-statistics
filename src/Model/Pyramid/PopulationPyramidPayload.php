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
 * Wire-format payload for the chart-lib pyramid widget on the LifeSpan tab.
 * Built for the deaths-by-sex use, but shaped to the widget's domain-neutral
 * contract: `groups` is the picker axis (one localised century label per
 * selectable column-set, chronological order); `bands` is the shared
 * age-at-death band axis in top-to-bottom order (oldest band first so the
 * pyramid reads conventionally); `data[groupIdx][bandIdx]` is the
 * `{left, right}` count pair — here left = male, right = female, the mapping
 * the LifeSpan card pins via its `leftLabel` / `rightLabel` captions.
 *
 * Serialises to `{groups: list<string>, bands: list<string>, data:
 * list<list<array{left: int, right: int}>>}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class PopulationPyramidPayload implements JsonSerializable
{
    /**
     * @param list<string>                             $groups Localised picker labels (centuries) in chronological order
     * @param list<string>                             $bands  Age-at-death band labels, oldest first (top of the pyramid)
     * @param list<list<array{left: int, right: int}>> $data   Per-group column of `{left, right}` count pairs, one entry per band
     */
    public function __construct(
        public array $groups,
        public array $bands,
        public array $data,
    ) {
    }

    /**
     * @return array{groups: list<string>, bands: list<string>, data: list<list<array{left: int, right: int}>>}
     */
    public function jsonSerialize(): array
    {
        return [
            'groups' => $this->groups,
            'bands'  => $this->bands,
            'data'   => $this->data,
        ];
    }
}
