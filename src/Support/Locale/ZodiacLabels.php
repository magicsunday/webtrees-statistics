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

/**
 * Translates the canonical English zodiac sign keys (Aries, Taurus, …)
 * used by `EventRepository::getBirthsByZodiacSign()` into the display
 * label of the active locale. The repository keeps the keys language-
 * neutral so SQL aliases and downstream code stay stable; only the
 * view layer translates them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ZodiacLabels
{
    private function __construct()
    {
    }

    /**
     * Rebuild `[zodiacKey => count]` with the keys passed through
     * I18N::translate(). Unknown keys (callers must not invent any,
     * but defensively guard anyway) flow through unchanged so no
     * data row goes missing.
     *
     * @param array<string, int> $data Counts keyed by English zodiac sign
     *
     * @return array<string, int> Counts keyed by translated zodiac sign
     */
    public static function translateKeys(array $data): array
    {
        $translations = [
            'Aries'       => I18N::translate('Aries'),
            'Taurus'      => I18N::translate('Taurus'),
            'Gemini'      => I18N::translate('Gemini'),
            'Cancer'      => I18N::translate('Cancer'),
            'Leo'         => I18N::translate('Leo'),
            'Virgo'       => I18N::translate('Virgo'),
            'Libra'       => I18N::translate('Libra'),
            'Scorpio'     => I18N::translate('Scorpio'),
            'Sagittarius' => I18N::translate('Sagittarius'),
            'Capricornus' => I18N::translate('Capricornus'),
            'Aquarius'    => I18N::translate('Aquarius'),
            'Pisces'      => I18N::translate('Pisces'),
        ];

        $out = [];

        foreach ($data as $sign => $count) {
            $out[$translations[$sign] ?? $sign] = $count;
        }

        return $out;
    }
}
