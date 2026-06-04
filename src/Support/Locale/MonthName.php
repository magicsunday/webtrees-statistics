<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Locale;

use Fisharebest\Webtrees\I18N;

use function array_map;
use function array_values;
use function mb_substr;

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
     * @return array<string, string>
     */
    public static function byAbbreviation(): array
    {
        return [
            'JAN' => I18N::translateContext('NOMINATIVE', 'January'),
            'FEB' => I18N::translateContext('NOMINATIVE', 'February'),
            'MAR' => I18N::translateContext('NOMINATIVE', 'March'),
            'APR' => I18N::translateContext('NOMINATIVE', 'April'),
            'MAY' => I18N::translateContext('NOMINATIVE', 'May'),
            'JUN' => I18N::translateContext('NOMINATIVE', 'June'),
            'JUL' => I18N::translateContext('NOMINATIVE', 'July'),
            'AUG' => I18N::translateContext('NOMINATIVE', 'August'),
            'SEP' => I18N::translateContext('NOMINATIVE', 'September'),
            'OCT' => I18N::translateContext('NOMINATIVE', 'October'),
            'NOV' => I18N::translateContext('NOMINATIVE', 'November'),
            'DEC' => I18N::translateContext('NOMINATIVE', 'December'),
        ];
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
