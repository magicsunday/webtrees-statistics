<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use function array_keys;

/**
 * The single source of truth for the twelve tropical-zodiac sign boundaries.
 * Both the SQL birth-by-sign tally ({@see \MagicSunday\Webtrees\Statistic\Repository\EventRepository})
 * and the human-readable per-sign period label
 * ({@see Locale\ZodiacPeriods}) read these
 * ranges, so the bucket a birth falls into and the period printed next to the
 * sign can never drift apart.
 *
 * The dates follow the tropical-zodiac table of the German Wikipedia (western
 * astrology). They are mean values: leap years shift the true ingress by up to
 * ±1 day, which a fixed month/day bucket cannot model — acceptable for an
 * aggregate distribution. The ranges are contiguous and gap-free: each sign's
 * `to` day plus one is the following sign's `from` day, so every dated birth
 * lands in exactly one sign.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 * @link    https://de.wikipedia.org/wiki/Tierkreiszeichen
 */
final class ZodiacSigns
{
    /**
     * Canonical English sign key => inclusive `[month, day]` from/to boundary.
     * Ordered Aries-first so the radial clock opens at Aries, matching the
     * conventional zodiac wheel.
     *
     * @var array<string, array{from: array{int, int}, to: array{int, int}}>
     */
    private const array RANGES = [
        'Aries'       => ['from' => [3, 21], 'to' => [4, 20]],
        'Taurus'      => ['from' => [4, 21], 'to' => [5, 21]],
        'Gemini'      => ['from' => [5, 22], 'to' => [6, 21]],
        'Cancer'      => ['from' => [6, 22], 'to' => [7, 22]],
        'Leo'         => ['from' => [7, 23], 'to' => [8, 22]],
        'Virgo'       => ['from' => [8, 23], 'to' => [9, 22]],
        'Libra'       => ['from' => [9, 23], 'to' => [10, 22]],
        'Scorpio'     => ['from' => [10, 23], 'to' => [11, 22]],
        'Sagittarius' => ['from' => [11, 23], 'to' => [12, 20]],
        'Capricornus' => ['from' => [12, 21], 'to' => [1, 19]],
        'Aquarius'    => ['from' => [1, 20], 'to' => [2, 18]],
        'Pisces'      => ['from' => [2, 19], 'to' => [3, 20]],
    ];

    /**
     * Prevent instantiation — static-only data holder.
     */
    private function __construct()
    {
    }

    /**
     * The twelve sign boundaries keyed by their canonical English name, in
     * Aries-first wheel order.
     *
     * @return array<string, array{from: array{int, int}, to: array{int, int}}>
     */
    public static function ranges(): array
    {
        return self::RANGES;
    }

    /**
     * The twelve canonical English sign keys, in Aries-first wheel order.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::RANGES);
    }
}
