<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Catalogue;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The placeholder reading that the catalogue check compares translations with.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class PlaceholdersTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}> A text and its placeholders
     */
    public static function texts(): iterable
    {
        yield 'a doubled percent sign hides no placeholder' => ['%%s generations', []];
        yield 'a literal percent next to a placeholder' => ['%s%% of %d', ['%s', '%d']];
        yield 'unnumbered placeholders keep their order' => ['%d von %s', ['%d', '%s']];
        yield 'numbered placeholders may swap' => ['%2$s of %1$s', ['%1$s', '%2$s']];
        yield 'a text without a placeholder' => ['generations', []];
    }

    /**
     * A text gives the placeholders that a formatted value would fill.
     *
     * @param string       $text     The text
     * @param list<string> $expected The placeholders
     */
    #[Test]
    #[DataProvider('texts')]
    public function readsThePlaceholdersOfAText(string $text, array $expected): void
    {
        self::assertSame($expected, Placeholders::of($text));
    }
}
