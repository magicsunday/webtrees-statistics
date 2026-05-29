<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Ranking;

use JsonSerializable;

/**
 * One row of a Top-N podium ranking: the entity's XREF, a human-readable
 * `label` and the ranked `value`. Podiums return a LIST of these rows rather
 * than a `label => value` map, which is what keeps two distinct individuals or
 * families that share a display name as separate rows: a name-keyed map
 * collapses them onto one key, where the lower-ranked one overwrites the higher
 * and the rendered list stops descending. The XREF is the row's stable identity
 * — what callers rank and deduplicate on, and the handle a later change can use
 * to link the name to its record page. The label stays plain text.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class RankingEntry implements JsonSerializable
{
    public function __construct(
        public string $xref,
        public string $label,
        public int $value,
    ) {
    }

    /**
     * @return array{xref: string, label: string, value: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'xref'  => $this->xref,
            'label' => $this->label,
            'value' => $this->value,
        ];
    }
}
