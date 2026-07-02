<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Normalization\Support;

use function array_map;
use function array_values;
use function is_array;
use function iterator_to_array;

/**
 * Materialises an iterable of raw occupation values into a plain `list<string>`.
 * Two invariants matter to the normalization seam and are easy to get subtly
 * wrong if open-coded at each call site: a Traversable is drained exactly once
 * (so a generator can be both resolved and re-iterated), and every element is
 * cast back to a string — a purely numeric value (e.g. `1234`) becomes an `int`
 * the moment it is used as an array key upstream, which would otherwise throw
 * under strict types when it reaches `mb_strtolower()`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class StringList
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Drain the iterable once and return its values as a re-indexed list of
     * strings.
     *
     * @param iterable<int|string> $values The raw values, possibly int-coerced by an upstream array key
     *
     * @return list<string> The same values as a plain list, each cast to a string
     */
    public static function of(iterable $values): array
    {
        $list = is_array($values) ? array_values($values) : iterator_to_array($values, false);

        return array_map(static fn (int|string $value): string => (string) $value, $list);
    }
}
