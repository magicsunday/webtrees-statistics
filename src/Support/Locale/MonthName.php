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

use function array_values;

/**
 * Pure helper for the localised NOMINATIVE month names every per-month widget
 * renders. Mirrors {@see DecadeName} and {@see CenturyName} so the by-month
 * donuts and the decade × month heatmap share one source of truth for the
 * twelve labels. Two display forms: the abbreviation-keyed map the
 * GEDCOM-driven month tallies fold their `JAN`/`FEB`/… buckets onto, and the
 * January-first ordered list a heatmap column axis consumes directly.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MonthName
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
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
     * The twelve localised month names in calendar order, January first — the
     * column axis a decade × month heatmap renders left to right.
     *
     * @return list<string>
     */
    public static function ordered(): array
    {
        return array_values(self::byAbbreviation());
    }
}
