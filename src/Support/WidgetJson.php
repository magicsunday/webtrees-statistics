<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use JsonException;
use JsonSerializable;

use function e;
use function json_encode;

use const JSON_INVALID_UTF8_SUBSTITUTE;
use const JSON_THROW_ON_ERROR;

/**
 * JSON encoder used by every chart-widget partial when it writes
 * the `data-payload` / `data-options` attributes. Bundles
 * `JSON_THROW_ON_ERROR` (fail loudly on encoding errors rather
 * than emit `false` to the DOM) and `JSON_INVALID_UTF8_SUBSTITUTE`
 * (substitute malformed UTF-8 byte sequences from GEDCOM imports
 * with U+FFFD rather than fatal-throwing) so a single bad import
 * cannot crash a whole statistics tab. Centralising the flag set
 * means a future flag addition (e.g. `JSON_UNESCAPED_SLASHES`)
 * lands once instead of being copy-pasted across 13 partials.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class WidgetJson
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Encode `$value` to the raw JSON string. Throws on encoding
     * failure; substitutes malformed UTF-8 with U+FFFD. Accepts
     * either a raw `array` (widget options) or a `JsonSerializable`
     * DTO (widget payload).
     *
     * Most callers want {@see encodeAttribute()} which additionally
     * HTML-escapes the result for direct embedding in a `data-*`
     * attribute.
     *
     * @param array<array-key, mixed>|JsonSerializable $value
     *
     * @throws JsonException
     */
    public static function encode(array|JsonSerializable $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Encode `$value` to the HTML-attribute-safe JSON string used by
     * the chart-widget `data-payload` / `data-options` attributes.
     * Combines {@see encode()} with webtrees' `e()` helper so widget
     * partials read as a single call instead of the more error-
     * prone `e(WidgetJson::encode(...))` nesting at every site.
     *
     * @param array<array-key, mixed>|JsonSerializable $value
     *
     * @throws JsonException
     */
    public static function encodeAttribute(array|JsonSerializable $value): string
    {
        return e(self::encode($value));
    }
}
