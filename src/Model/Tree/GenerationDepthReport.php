<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Tree;

use Fisharebest\Webtrees\Individual;
use JsonSerializable;

use function array_map;

/**
 * Maximum-generation-depth report for the Family tab. Combines the scalar
 * max-depth indicator with the distribution histogram of per-individual
 * descendant depths and a sample of verified leaf-anchored chains at the
 * maximum depth (so the view can advertise the verified line + "+N more" when
 * several exist).
 *
 * Carries live `Individual` instances in `chains` so the PHTML consumer can
 * render the chain as a row of names linking to the individual page.
 * `jsonSerialize` flattens chains down to their xrefs because a webtrees
 * `Individual` is not JSON-encodable.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class GenerationDepthReport implements JsonSerializable
{
    /**
     * @param int                    $maxDepth        Deepest verified parent-child chain length across the tree
     * @param array<int, int>        $distribution    Histogram `depthBucket → count of individuals`
     * @param bool                   $capped          Whether the walk hit the safety cap (typical sign of cyclic FAMC/FAMS edits)
     * @param list<list<Individual>> $chains          Sample of verified chains anchored at the maximum depth
     * @param int                    $totalChainCount Total number of chains found at the maximum depth (≥ `count($chains)`)
     */
    public function __construct(
        public int $maxDepth,
        public array $distribution,
        public bool $capped,
        public array $chains,
        public int $totalChainCount,
    ) {
    }

    /**
     * Flattens each chain to its xref list — the JSON form a JS consumer can
     * drive without an Individual proxy on the wire.
     *
     * @return array{maxDepth: int, distribution: array<int, int>, capped: bool, chains: list<list<string>>, totalChainCount: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'maxDepth'     => $this->maxDepth,
            'distribution' => $this->distribution,
            'capped'       => $this->capped,
            'chains'       => array_map(
                static fn (array $chain): array => array_map(
                    static fn (Individual $individual): string => $individual->xref(),
                    $chain,
                ),
                $this->chains,
            ),
            'totalChainCount' => $this->totalChainCount,
        ];
    }
}
