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

use function restore_error_handler;
use function set_error_handler;
use function uksort;

use const E_USER_DEPRECATED;

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
        // webtrees marks I18N::comparator() deprecated on dev-main (removed in 2.3,
        // superseded by I18N::compare()), but compare() does not exist on the 2.2.x
        // line this module also supports — so comparator() stays the only portable
        // call. Swallow the transitional E_USER_DEPRECATED at this single call site
        // (a scoped handler restored immediately after) so it neither fails the suite
        // nor leaks as test output; migrate to compare() once the 2.2.x floor is dropped.
        set_error_handler(static fn (): bool => true, E_USER_DEPRECATED);

        try {
            $comparator = I18N::comparator();
        } finally {
            restore_error_handler();
        }

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
