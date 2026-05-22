<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Dto\Sankey;

use JsonSerializable;

/**
 * Wire-format payload for the migration sankey diagram on the
 * Places tab. Produced by `MigrationRepository::flowsByCountry()`
 * and consumed by the chart-lib sankey-flow widget via JSON.
 *
 * Serialises to `{nodes: list<{name}>, links: list<{source, target, value, samples}>}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MigrationFlowsPayload implements JsonSerializable
{
    /**
     * @param list<SankeyNode> $nodes Distinct countries reachable as origin OR destination
     * @param list<SankeyLink> $links Directed flows; `source` / `target` index into `$nodes`
     */
    public function __construct(
        public array $nodes,
        public array $links,
    ) {
    }

    /**
     * @return array{nodes: list<SankeyNode>, links: list<SankeyLink>}
     */
    public function jsonSerialize(): array
    {
        return [
            'nodes' => $this->nodes,
            'links' => $this->links,
        ];
    }
}
