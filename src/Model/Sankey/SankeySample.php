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
 * Representative individual surfaced inside a {@see SankeyLink}.
 * Each link in the migration sankey carries up to N samples so the
 * tooltip can show actual person names + xrefs behind the flow
 * width — the acceptance criterion from #12.
 *
 * Serialises to `{name: string, xref: string}` for the chart-lib
 * sankey-flow widget.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class SankeySample implements JsonSerializable
{
    public function __construct(
        public string $name,
        public string $xref,
    ) {
    }

    /**
     * @return array{name: string, xref: string}
     */
    public function jsonSerialize(): array
    {
        return [
            'name' => $this->name,
            'xref' => $this->xref,
        ];
    }
}
