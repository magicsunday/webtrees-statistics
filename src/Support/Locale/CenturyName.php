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

use function abs;
use function intdiv;
use function str_contains;
use function strip_tags;

/**
 * Pure helper mirroring webtrees core's private `centuryName()` so widgets that
 * produce century data outside of `StatisticsData::countEventsByCentury` (e.g.
 * the child-mortality aggregator, which needs a self-joined dates query) can
 * label their cohorts consistently across the chart.
 *
 * The ordinals are the ones webtrees core translates under its `CENTURY`
 * context, stripped of markup. The module carries no ordinal of its own,
 * because an entry under that context would replace the century labels core
 * renders on its own pages. A language whose core ordinal already names the
 * century (Chinese and Korean) is not followed by the century noun a second
 * time.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class CenturyName
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Map a year to its 1-based century number using the Gregorian convention
     * where year 1 lives in the 1st century and year 100 still does, but year
     * 101 starts the 2nd. This is the single source of truth that every
     * century-bucketing repository call site routes through.
     *
     * BCE (negative) years are folded symmetrically — the century is derived
     * from the absolute year and re-signed — so that 1–100 BCE map to century
     * -1, 101–200 BCE to -2, and so on. `intdiv()` alone truncates toward zero,
     * which would collapse 1–100 BCE into the 1st century CE and 101–200 BCE
     * into the degenerate century 0; the explicit re-sign floors toward
     * negative infinity instead, landing every BCE year in a negative century
     * that {@see compactLabel()} / {@see longLabel()} render with a trailing
     * "%s BCE" era marker.
     */
    public static function fromYear(int $year): int
    {
        if ($year < 0) {
            return -self::fromYear(-$year);
        }

        return intdiv($year - 1, 100) + 1;
    }

    /**
     * Localise a positive 1-based century number to its bare ordinal string
     * ("1st", "21st", …) from the core catalogue. The single place the ordinals
     * are looked up. {@see compactLabel()} and {@see longLabel()} both build on
     * it so the BCE era marker can be appended LAST, after the century noun.
     */
    private static function ordinal(int $century): string
    {
        return strip_tags(match ($century) {
            21      => I18N::translateContext('CENTURY', '21st'),
            20      => I18N::translateContext('CENTURY', '20th'),
            19      => I18N::translateContext('CENTURY', '19th'),
            18      => I18N::translateContext('CENTURY', '18th'),
            17      => I18N::translateContext('CENTURY', '17th'),
            16      => I18N::translateContext('CENTURY', '16th'),
            15      => I18N::translateContext('CENTURY', '15th'),
            14      => I18N::translateContext('CENTURY', '14th'),
            13      => I18N::translateContext('CENTURY', '13th'),
            12      => I18N::translateContext('CENTURY', '12th'),
            11      => I18N::translateContext('CENTURY', '11th'),
            10      => I18N::translateContext('CENTURY', '10th'),
            9       => I18N::translateContext('CENTURY', '9th'),
            8       => I18N::translateContext('CENTURY', '8th'),
            7       => I18N::translateContext('CENTURY', '7th'),
            6       => I18N::translateContext('CENTURY', '6th'),
            5       => I18N::translateContext('CENTURY', '5th'),
            4       => I18N::translateContext('CENTURY', '4th'),
            3       => I18N::translateContext('CENTURY', '3rd'),
            2       => I18N::translateContext('CENTURY', '2nd'),
            1       => I18N::translateContext('CENTURY', '1st'),
            default => I18N::translate('%s century', (string) $century),
        });
    }

    /**
     * Whether an ordinal already carries the word for "century". Core spells the
     * Chinese and Korean ordinals with it, so appending the noun a second time would read
     * "二十世纪 世纪".
     *
     * @param string $ordinal The localised ordinal of a century
     * @param string $noun    The localised word for "century"
     */
    private static function namesTheCentury(string $ordinal, string $noun): bool
    {
        return str_contains($ordinal, $noun);
    }

    /**
     * Long-form century label widget tooltips use ("20th Century" / "20.
     * Jahrhundert"). The BCE era marker is appended LAST, after the "Century"
     * noun, so a negative century reads "2nd Century BCE" / "2. Jahrhundert
     * v. u. Z." — never "2nd BCE Century". Takes the integer century directly so
     * the noun and the era marker compose in the right order.
     */
    public static function longLabel(int $century): string
    {
        $ordinal = self::ordinal(abs($century));
        $noun    = I18N::translate('Century');

        return self::labelFrom($century, $ordinal, $noun, $ordinal . ' ' . $noun);
    }

    /**
     * Compact-form label for tight legend / axis tick contexts — "20th cent."
     * in English, "20. Jh." in German. The BCE era marker is appended LAST,
     * after the abbreviated "cent." noun, so a negative century reads "2nd cent.
     * BCE" / "2. Jh. v. u. Z." — never "2nd BCE cent.". Takes the integer
     * century directly so the noun and the era marker compose in the right
     * order.
     */
    public static function compactLabel(int $century): string
    {
        $ordinal = self::ordinal(abs($century));

        return self::labelFrom(
            $century,
            $ordinal,
            I18N::translate('Century'),
            I18N::translate('%s cent.', $ordinal),
        );
    }

    /**
     * Compose a century label from the localised parts. The ordinal alone is the
     * label when it already holds the century noun (Chinese and Korean), the fallback is the
     * label otherwise, and the BCE era marker comes last. The parts are
     * parameters so a test can feed the wording of a language the runtime does
     * not load.
     *
     * @param int    $century  The signed century number, negative for BCE
     * @param string $ordinal  The localised ordinal of the century
     * @param string $noun     The localised word for "century"
     * @param string $fallback The label for an ordinal that lacks the noun ("20. Jahrhundert", "20. Jh.")
     */
    public static function labelFrom(int $century, string $ordinal, string $noun, string $fallback): string
    {
        $label = self::namesTheCentury($ordinal, $noun) ? $ordinal : $fallback;

        if ($century < 0) {
            return I18N::translate('%s BCE', $label);
        }

        return $label;
    }
}
