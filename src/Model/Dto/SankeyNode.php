<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Dto;

use JsonSerializable;

/**
 * One arc on a sankey diagram — the named endpoint a {@see SankeyLink}
 * connects to. The position in the parent payload's `nodes` list
 * defines the integer index links reference via `source` / `target`.
 *
 * Serialises to `{name: string}` for the chart-lib sankey-flow widget.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class SankeyNode implements JsonSerializable
{
    public function __construct(
        public string $name,
    ) {
    }

    /**
     * @return array{name: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
        ];
    }
}
