<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Gedcom;

use function html_entity_decode;
use function strip_tags;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Pure helper that reduces a record's `fullName()` output to a plain-text label.
 * webtrees returns names as HTML (wrapping markup plus escaped entities), so a
 * ranked list that wants the bare name must both strip the tags AND decode the
 * entities — otherwise a name containing `&` or an apostrophe keeps its
 * `&amp;` / `&#039;` form. Several repositories inlined the strip-and-decode
 * idiom (and one omitted the decode, leaking entities into ranked labels);
 * routing them through one helper keeps the two steps together.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class RecordName
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Strip the markup from a record's `fullName()` HTML and decode the HTML
     * entities, returning a plain-text label suitable for a ranked list.
     *
     * @param string $fullNameHtml The HTML returned by `GedcomRecord::fullName()`
     */
    public static function plain(string $fullNameHtml): string
    {
        return html_entity_decode(strip_tags($fullNameHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
