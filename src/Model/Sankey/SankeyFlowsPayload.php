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

use function array_map;

/**
 * Wire-format payload for a bipartite Sankey diagram: a flat list of node
 * labels plus directed links that index into it. Two repositories produce it —
 * `MigrationRepository::flowsByCountry()` (birth → death country flows on the
 * Places tab) and `OccupationInheritanceRepository::occupationInheritance()`
 * (parent → child occupation flows on the Overview tab) — and the chart-lib
 * sankey-flow widget consumes it via JSON.
 *
 * Serialises to `{nodes: list<{name}>, links: list<{source, target, value,
 * samples}>}` — the `{name}` shape on the node side is required by d3-sankey
 * even though we only carry a single label per node; nodes are therefore held
 * internally as plain `list<string>` and projected to the wire shape at
 * `jsonSerialize()` time.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class SankeyFlowsPayload implements JsonSerializable
{
    /**
     * @param list<string>     $nodes Node labels in display order; link `source`/`target` indices reference this list
     * @param list<SankeyLink> $links Directed flows; `source` / `target` index into `$nodes`
     */
    public function __construct(
        public array $nodes,
        public array $links,
    ) {
    }

    /**
     * @return array{nodes: list<array{name: string}>, links: list<SankeyLink>}
     */
    public function jsonSerialize(): array
    {
        return [
            'nodes' => array_map(static fn (string $name): array => ['name' => $name], $this->nodes),
            'links' => $this->links,
        ];
    }
}
