<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use Fisharebest\Webtrees\I18N;

use function uksort;

/**
 * Orders a `label => count` distribution alphabetically by its display label
 * using webtrees' locale-aware collation, so the bar lists read by name rather
 * than by frequency. A single catch-all label (e.g. an "Other" bucket) can be
 * pinned to the end so it does not sort into the middle of the alphabet.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class LabelSorter
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Sort a label → count map by its label using the active locale's collation.
     *
     * @param array<string, int> $map     Label → count, in any order
     * @param string|null        $pinLast Label forced to the end regardless of collation (e.g. a catch-all bucket), or null
     *
     * @return array<string, int> The same entries, ordered by label
     */
    public static function byLabel(array $map, ?string $pinLast = null): array
    {
        $comparator = I18N::comparator();

        uksort(
            $map,
            static function (string $a, string $b) use ($comparator, $pinLast): int {
                if ($pinLast !== null) {
                    if ($a === $pinLast) {
                        return 1;
                    }

                    if ($b === $pinLast) {
                        return -1;
                    }
                }

                return $comparator($a, $b);
            }
        );

        return $map;
    }
}
