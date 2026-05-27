<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Sankey;

use JsonSerializable;

/**
 * One ribbon on a sankey diagram — a directed flow from `source` to
 * `target` (both indices into the parent payload's `nodes` list)
 * carrying `value` individuals plus up to N representative samples
 * the tooltip can surface.
 *
 * Serialises to `{source: int, target: int, value: int, samples: list<{name, xref}>}`
 * for the chart-lib sankey-flow widget.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class SankeyLink implements JsonSerializable
{
    /**
     * @param int                $source  Index into the parent payload's `nodes` list
     * @param int                $target  Index into the parent payload's `nodes` list
     * @param int                $value   Number of individuals flowing along this link
     * @param list<SankeySample> $samples Representative people behind the flow (≤ N)
     */
    public function __construct(
        public int $source,
        public int $target,
        public int $value,
        public array $samples,
    ) {
    }

    /**
     * @return array{source: int, target: int, value: int, samples: list<SankeySample>}
     */
    public function jsonSerialize(): array
    {
        return [
            'source'  => $this->source,
            'target'  => $this->target,
            'value'   => $this->value,
            'samples' => $this->samples,
        ];
    }
}
