<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Catalogue;

use function array_filter;
use function array_values;
use function preg_match_all;
use function sort;
use function str_contains;
use function str_replace;

/**
 * The string and integer placeholders of a catalogue text.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class Placeholders
{
    /**
     * Prevent instantiation, this is a static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * The placeholders of a text. A doubled percent sign is literal. Numbered
     * placeholders may change their position, so they are sorted, while
     * unnumbered ones keep their order.
     *
     * @param string $text The text
     *
     * @return list<string> The placeholders
     */
    public static function of(string $text): array
    {
        preg_match_all('/%(?:\d+\$)?[sd]/', str_replace('%%', '', $text), $matches);

        $unnumbered = array_values(
            array_filter(
                $matches[0],
                static fn (string $placeholder): bool => !str_contains($placeholder, '$'),
            ),
        );
        $numbered = array_values(
            array_filter(
                $matches[0],
                static fn (string $placeholder): bool => str_contains($placeholder, '$'),
            ),
        );

        sort($numbered);

        return [...$unnumbered, ...$numbered];
    }
}
