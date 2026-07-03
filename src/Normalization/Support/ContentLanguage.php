<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Normalization\Support;

use Fisharebest\Webtrees\Site;

/**
 * Resolves the content-language hint handed to an occupation-standardization
 * provider so it can pick the matching language rules. webtrees has no per-tree
 * language *preference* — core moved `LANGUAGE` from a per-tree `gedcom_setting`
 * to a SITE-level `site_setting` (Migration 43), so it is now only configurable
 * under Control panel → Website preferences → Default language. That admin-set
 * default is the stable, viewer-independent proxy for the language the
 * occupation strings are written in; the current front-end language is
 * deliberately NOT used, because it is per-viewer and would fold the same tree
 * differently for every observer (an English viewer of a German tree would
 * suppress the German matches).
 *
 * Deliberate limitation: the GEDCOM header `HEAD.LANG` is a per-tree
 * content-language signal, but it is frequently absent and deprecated in
 * GEDCOM 7, so it is not consulted — a single site default is used for every
 * tree. On a multi-tree install whose trees hold different content languages
 * this hands the "wrong" language to the provider for the non-default trees;
 * that is harmless under the identity default (the hint is inert) and only
 * shifts grouping when a language-gated provider is installed, which is an
 * accepted trade-off for a single-language install.
 *
 * The returned value is a full webtrees locale tag and may carry a region
 * subtag (`en-US`, `pt-BR`); a consumer that keys on a bare language subtag must
 * take the primary subtag itself. An empty preference resolves to null so a
 * provider that gates recognition on a concrete language receives "no hint"
 * uniformly rather than an empty string (in practice the site default is
 * `en-US`, so this only fires when an admin explicitly blanks the value).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ContentLanguage
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * The site's configured default language as a BCP-47 tag, or null when no
     * language is configured.
     */
    public static function tag(): ?string
    {
        $language = Site::getPreference('LANGUAGE');

        return $language !== '' ? $language : null;
    }
}
