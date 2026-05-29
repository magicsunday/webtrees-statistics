<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support;

use JsonSerializable;
use MagicSunday\Webtrees\Statistic\Support\WidgetJson;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chr;

/**
 * Verifies the chart-widget JSON encoder's two non-default flags: the
 * throw-on-error contract that prevents a `false` return from silently
 * corrupting the rendered DOM, and the UTF-8-substitute contract that absorbs
 * malformed byte sequences from imported GEDCOM without crashing the whole
 * statistics tab.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class WidgetJsonTest extends TestCase
{
    /**
     * Happy path: a regular array encodes to the canonical JSON string with no
     * extra escaping that would interfere with the `data-*` attribute
     * round-trip.
     */
    #[Test]
    public function encodeProducesCanonicalJsonForArrayValues(): void
    {
        $value = ['categories' => ['1900', '1910'], 'series' => [['name' => 'A', 'values' => [1, 2]]]];

        self::assertSame(
            '{"categories":["1900","1910"],"series":[{"name":"A","values":[1,2]}]}',
            WidgetJson::encode($value),
        );
    }

    /**
     * A JsonSerializable DTO is forwarded to its `jsonSerialize()` shape — the
     * same path every chart-payload DTO uses.
     */
    #[Test]
    public function encodeHonoursJsonSerializableImplementations(): void
    {
        $value = new class implements JsonSerializable {
            /**
             * @return array<string, int>
             */
            public function jsonSerialize(): array
            {
                return ['count' => 42];
            }
        };

        self::assertSame('{"count":42}', WidgetJson::encode($value));
    }

    /**
     * Malformed UTF-8 — a lone 0xC3 continuation byte that the GEDCOM-import
     * path can produce when a multi-byte character gets truncated mid-character
     * — substitutes to U+FFFD rather than throwing, so a single bad import
     * cannot crash the tab.
     */
    #[Test]
    public function encodeSubstitutesMalformedUtf8(): void
    {
        $value = ['name' => 'Sonntag' . chr(0xC3) . 'X'];

        // The expected literal carries the JSON-escaped U+FFFD
        // sequence `�` that JSON_INVALID_UTF8_SUBSTITUTE
        // inserts for the truncated 0xC3 byte.
        self::assertSame('{"name":"Sonntag\\ufffdX"}', WidgetJson::encode($value));
    }

    /**
     * `encodeAttribute()` HTML-escapes the JSON output so a stray double-quote
     * in a label cannot break out of the surrounding `data-payload="…"`
     * attribute. JSON already escapes the inner `"` to `\"`, and `e()` then
     * escapes the resulting backslash- quote pair plus the outer field quotes
     * into `\&quot;`.
     */
    #[Test]
    public function encodeAttributeEscapesDoubleQuotesForAttributeContext(): void
    {
        $value = ['label' => 'O\'Brien "the elder"'];

        $encoded = WidgetJson::encodeAttribute($value);

        self::assertStringContainsString('\\&quot;the elder\\&quot;', $encoded);
        self::assertStringNotContainsString('"the elder"', $encoded);
    }

    /**
     * Angle brackets and ampersands in encoded payloads escape so a label
     * cannot inject markup into the host element via the `data-*` attribute
     * round-trip. JSON leaves `&` and `<` raw (no `JSON_HEX_*` flags); `e()`
     * then turns them into `&amp;` / `&lt;` / `&gt;`.
     */
    #[Test]
    public function encodeAttributeEscapesAngleBracketsAndAmpersands(): void
    {
        $value = ['label' => '<b>foo</b> & bar'];

        $encoded = WidgetJson::encodeAttribute($value);

        self::assertStringContainsString('&lt;b&gt;foo&lt;\\/b&gt;', $encoded);
        self::assertStringContainsString('&amp; bar', $encoded);
        self::assertStringNotContainsString('<b>foo', $encoded);
    }

    /**
     * Clean-ASCII input where no escape is needed still round-trips through the
     * helper — confirms `encodeAttribute()` does not mangle plain values that
     * `encode()` already produces correctly.
     */
    #[Test]
    public function encodeAttributeEscapesCleanAsciiOnlyAtAttributeBoundary(): void
    {
        $value = ['categories' => ['1900', '1910']];

        self::assertSame(
            '{&quot;categories&quot;:[&quot;1900&quot;,&quot;1910&quot;]}',
            WidgetJson::encodeAttribute($value),
        );
    }
}
