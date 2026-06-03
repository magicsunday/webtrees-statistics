<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Metric;

use JsonSerializable;

use function round;

/**
 * Connected-component ("unconnected islands") summary for the Tree-health tab. Two
 * individuals belong to the same island when a chain of family memberships
 * (spouse or parent-child) connects them; an individual in no family is an
 * island of size one. `top` is the descending list of the largest islands the
 * treemap renders — each `{rank, members, label}`, where `label` is the most
 * common surname of that island (empty when no surname is recorded);
 * `restMembers` aggregates every island beyond the top slice into a single
 * tile. `totalIslands`, `largestSize` and `totalPersons` express how much of the
 * tree the biggest island covers — the topology signal that separates a
 * single-family tree from an address-book-like scatter.
 *
 * Serialises to `{totalIslands, totalPersons, largestPct, top, restMembers}`
 * (`largestPct` rounded to a whole percent for the diagnosis card).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class IslandSummary implements JsonSerializable
{
    /**
     * @param list<array{rank: int, members: int, label: string}> $top          Largest islands, descending
     * @param int                                                 $totalIslands Total number of components (incl. singletons)
     * @param int                                                 $totalPersons Individuals across all islands
     * @param int                                                 $largestSize  Member count of the biggest island
     * @param int                                                 $restMembers  Members in the islands beyond the top slice
     */
    public function __construct(
        public array $top,
        public int $totalIslands,
        public int $totalPersons,
        public int $largestSize,
        public int $restMembers,
    ) {
    }

    /**
     * Share of the whole tree covered by the largest island, 0–100. Returns 0.0
     * when the tree has no individuals. Backs {@see largestPercent()}.
     */
    private function largestSharePercent(): float
    {
        if ($this->totalPersons <= 0) {
            return 0.0;
        }

        return ($this->largestSize / $this->totalPersons) * 100;
    }

    /**
     * The largest island's share of the tree rounded to a whole percent — the
     * figure the diagnosis card and the card subtitle display.
     */
    public function largestPercent(): int
    {
        return (int) round($this->largestSharePercent());
    }

    /**
     * @return array{totalIslands: int, totalPersons: int, largestPct: int, top: list<array{rank: int, members: int, label: string}>, restMembers: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'totalIslands' => $this->totalIslands,
            'totalPersons' => $this->totalPersons,
            'largestPct'   => $this->largestPercent(),
            'top'          => $this->top,
            'restMembers'  => $this->restMembers,
        ];
    }
}
