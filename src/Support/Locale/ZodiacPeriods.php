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
use MagicSunday\Webtrees\Statistic\Support\ZodiacSigns;

/**
 * Builds the localised date-range label printed next to each zodiac sign, e.g.
 * "21 Mar – 20 Apr". The day numbers and the inclusive boundaries come from
 * {@see ZodiacSigns} — the very source the SQL birth-by-sign tally buckets on —
 * so the period shown to the reader can never contradict the bucket a birth
 * lands in. The month is rendered as the active locale's abbreviated name via
 * {@see MonthName::abbreviated()}; the day/month order and any punctuation are
 * the translators' to set through the format string.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ZodiacPeriods
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * The twelve localised period labels keyed by the canonical English sign
     * name, in the same Aries-first order {@see ZodiacSigns} declares. Keeping
     * the English keys lets the view bridge each period to its translated sign
     * label without a fragile localised-key match.
     *
     * @return array<string, string>
     */
    public static function all(): array
    {
        // Localised, abbreviated month names indexed 0..11 (January first), so a
        // 1-based month maps to `$months[$month - 1]`.
        $months = MonthName::abbreviated();

        $out = [];

        foreach (ZodiacSigns::ranges() as $sign => $range) {
            [$fromMonth, $fromDay] = $range['from'];
            [$toMonth, $toDay]     = $range['to'];

            /* I18N: A zodiac sign's date range, e.g. “21 Mar – 20 Apr”. %1$s and %3$s are day numbers, %2$s and %4$s the abbreviated month names; reorder them and adjust the punctuation to suit the locale. */
            $out[$sign] = I18N::translate(
                '%1$s %2$s – %3$s %4$s',
                I18N::number($fromDay),
                $months[$fromMonth - 1],
                I18N::number($toDay),
                $months[$toMonth - 1],
            );
        }

        return $out;
    }
}
