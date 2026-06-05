<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Support\Gedcom;

use MagicSunday\Webtrees\Statistic\Support\Gedcom\RecordName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that {@see RecordName::plain()} strips the markup webtrees wraps
 * names in, decodes HTML entities, and trims the edges, so a ranked label never
 * keeps an `&amp;` / `&#039;` form nor the trailing space an empty surname or an
 * unknown-name placeholder leaves behind.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(RecordName::class)]
final class RecordNameTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function nameProvider(): array
    {
        return [
            'plain text is unchanged'        => ['Müller', 'Müller'],
            'tags are stripped'              => ['<span class="NAME">Anna Maria</span>', 'Anna Maria'],
            'ampersand entity is decoded'    => ['Smith &amp; Sons', 'Smith & Sons'],
            'apostrophe entity is decoded'   => ['<span>O&#039;Brien</span>', "O'Brien"],
            'angle-bracket entities decoded' => ['van &lt;Berg&gt;', 'van <Berg>'],
            'tags and entities together'     => ['<bdo dir="auto">Müller &amp; Co.</bdo>', 'Müller & Co.'],
            // An empty surname renders as a trailing space (and the unknown-name
            // placeholder as a bare ellipsis with one); both must be trimmed so
            // no edge whitespace leaks into a label or a hover sample.
            'trailing space from empty surname'   => ['<span>Anton </span>', 'Anton'],
            'unknown-name placeholder is trimmed' => ['… ', '…'],
        ];
    }

    /**
     * Each input mirrors a shape `GedcomRecord::fullName()` can return; the
     * output must be the bare, entity-decoded label.
     */
    #[Test]
    #[DataProvider('nameProvider')]
    public function plainStripsTagsAndDecodesEntities(string $input, string $expected): void
    {
        self::assertSame($expected, RecordName::plain($input));
    }
}
