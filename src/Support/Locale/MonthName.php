<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Locale;

use Closure;
use Fisharebest\Webtrees\I18N;

use function array_map;
use function array_values;
use function mb_convert_case;
use function mb_substr;

use const MB_CASE_TITLE;

/**
 * Pure helper for the localised NOMINATIVE month names every per-month widget
 * renders. Mirrors {@see DecadeName} and {@see CenturyName} so the by-month
 * donuts and the period × month heatmap share one source of truth for the
 * twelve labels. Three display forms: the abbreviation-keyed map the
 * GEDCOM-driven month tallies fold their `JAN`/`FEB`/… buckets onto, the full
 * ordered list a heatmap shows in its tooltip, and the three-letter ordered
 * list a heatmap column axis consumes directly.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MonthName
{
    /**
     * The canonical GEDCOM three-letter month code per `dates.d_mon` integer
     * (1–12), in calendar order. The single source a numeric-month tally folds
     * onto — both {@see codes()} consumers and the abbreviation-keyed name map
     * below share this `1 => 'JAN'` association rather than re-declaring it.
     *
     * @var array<int, string>
     */
    private const array CODES = [
        1  => 'JAN',
        2  => 'FEB',
        3  => 'MAR',
        4  => 'APR',
        5  => 'MAY',
        6  => 'JUN',
        7  => 'JUL',
        8  => 'AUG',
        9  => 'SEP',
        10 => 'OCT',
        11 => 'NOV',
        12 => 'DEC',
    ];

    /**
     * The English source name of every month keyed by its GEDCOM three-letter
     * code, in calendar order. The webtrees core catalogue translates these
     * under the NOMINATIVE context, so the module ships no catalogue entry of
     * its own for them.
     *
     * @var array<string, string>
     */
    private const array NAMES = [
        'JAN' => 'January',
        'FEB' => 'February',
        'MAR' => 'March',
        'APR' => 'April',
        'MAY' => 'May',
        'JUN' => 'June',
        'JUL' => 'July',
        'AUG' => 'August',
        'SEP' => 'September',
        'OCT' => 'October',
        'NOV' => 'November',
        'DEC' => 'December',
    ];

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * The GEDCOM three-letter month codes keyed by their `dates.d_mon` integer
     * (1–12), in calendar order. A query that selects the numeric month maps it
     * to the abbreviation through this lookup instead of aggregating the GEDCOM
     * string column, which would order lexicographically rather than
     * chronologically on a cross-calendar julian-day tie.
     *
     * @return array<int, string>
     */
    public static function codes(): array
    {
        return self::CODES;
    }

    /**
     * Translated NOMINATIVE month names keyed by the GEDCOM 3-letter
     * abbreviation. The keys are the literals webtrees stores in `dates.d_mon`
     * adjacent lookups expose, so a month tally keyed by abbreviation folds onto
     * this map in one pass.
     *
     * The name is the one the webtrees core catalogue holds, with its first
     * character capitalised, because several locales spell the month lowercase
     * and a chart label wants a capital. Capitalising here keeps the module from
     * shipping a capitalised catalogue entry, which would replace the core month
     * name in every date webtrees renders.
     *
     * @param (Closure(string): string)|null $translate Maps an English month name to its translation. The NOMINATIVE core translation is used when omitted
     *
     * @return array<string, string>
     */
    public static function byAbbreviation(?Closure $translate = null): array
    {
        $translate ??= static fn (string $month): string => I18N::translateContext('NOMINATIVE', $month);

        return array_map(
            static fn (string $month): string => self::capitalise($translate($month)),
            self::NAMES,
        );
    }

    /**
     * Capitalise the first character of a month name, leaving the rest as it is.
     * The multibyte functions keep an initial such as the Czech "Ú" or "Č" intact,
     * which a byte-wise `ucfirst` would leave lowercase. The title-case mapping
     * is used and not the upper-case one, so a script without capitals keeps its
     * letters, where the upper-case mapping turns a Georgian initial into its
     * headline form, and a digraph initial such as "ǆ" becomes "ǅ" and not "Ǆ".
     *
     * @param string $name The month name as the webtrees core catalogue spells it
     *
     * @return string The same name with its first character capitalised
     */
    private static function capitalise(string $name): string
    {
        return mb_convert_case(mb_substr($name, 0, 1, 'UTF-8'), MB_CASE_TITLE, 'UTF-8') . mb_substr($name, 1, null, 'UTF-8');
    }

    /**
     * The twelve full localised month names in calendar order, January first —
     * the verbose column titles a heatmap shows in its tooltip, and the source
     * {@see abbreviated()} shortens for the compact axis.
     *
     * @return list<string>
     */
    public static function ordered(): array
    {
        return array_values(self::byAbbreviation());
    }

    /**
     * The twelve localised month names shortened to their first three
     * characters, January first — the compact column axis a heatmap renders
     * horizontally; `mb_substr` keeps multibyte initials (e.g. de "Mär") intact.
     * The cut reads cleanly in most locales (en "Jan".."Dec", de "Jan".."Dez")
     * but can repeat in a few (fr "juin"/"juillet" both yield "jui"); the
     * heatmap keys its columns by position, not label, so a repeated
     * abbreviation still gets its own column and the tooltip's full month name
     * disambiguates it.
     *
     * @return list<string>
     */
    public static function abbreviated(): array
    {
        return array_map(
            static fn (string $name): string => mb_substr($name, 0, 3),
            self::ordered(),
        );
    }
}
