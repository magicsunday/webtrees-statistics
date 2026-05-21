<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use Fisharebest\Webtrees\I18N;

use function strip_tags;

/**
 * Pure helper mirroring webtrees core's private `centuryName()`
 * so widgets that produce century data outside of
 * `StatisticsData::countEventsByCentury` (e.g. the child-mortality
 * aggregator, which needs a self-joined dates query) can label
 * their cohorts identically to the rest of the chart.
 *
 * Reuses the existing core PO translation context `CENTURY`, so no
 * new translation strings are introduced.
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
     * Localise a 1-based century number (1, 2, …, 21) to the
     * ordinal string ("1st", "21st", …) the active locale uses for
     * centuries. Negative inputs are wrapped as "{N} BCE".
     */
    public static function for(int $century): string
    {
        if ($century < 0) {
            return I18N::translate('%s BCE', self::for(-$century));
        }

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
     * Append the localised "Century" noun to a short ordinal label,
     * producing the long form widget tooltips use ("20th Century" /
     * "20. Jahrhundert"). Accepts either an already-formatted short
     * label (the output of `self::for()` or core's
     * `countEventsByCentury` map keys) so PHTML loops and repository
     * code share the same suffix logic.
     */
    public static function longLabel(string $short): string
    {
        return $short . ' ' . I18N::translate('Century');
    }
}
