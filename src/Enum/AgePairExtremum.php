<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Enum;

/**
 * Picks the lowest- or highest-`years` row out of a `{xref, years}` pair
 * iterator. Used by the mirror-twin record-holder methods (youngest vs oldest
 * spouse at marriage, youngest vs oldest parent at first child, …) that
 * previously duplicated the same min / max walk with only the comparison
 * operator changing between them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
enum AgePairExtremum
{
    case Lowest;
    case Highest;

    /**
     * Walk every `{xref: string, years: int}` row in the iterator and keep the
     * one whose `years` is the lowest (or highest, depending on this enum
     * case). Returns null when the iterator is empty.
     *
     * @param iterable<int, array{xref: string, years: int}> $entries
     *
     * @return array{xref: string, years: int}|null
     */
    public function pick(iterable $entries): ?array
    {
        $best = null;

        foreach ($entries as $entry) {
            if ($best === null) {
                $best = $entry;

                continue;
            }

            $beatsBest = match ($this) {
                self::Lowest  => $entry['years'] < $best['years'],
                self::Highest => $entry['years'] > $best['years'],
            };

            if ($beatsBest) {
                $best = $entry;
            }
        }

        return $best;
    }
}
