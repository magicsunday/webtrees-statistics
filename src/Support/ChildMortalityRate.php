<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use MagicSunday\Webtrees\Statistic\Model\Dto\ChildMortalitySummary;

use function round;

/**
 * Pure helper for child-mortality computation. Given a list of
 * `{birthJd, deathJd}` julian-day pairs, returns the count of
 * individuals who died before reaching the threshold age (default
 * five years) and the resulting percentage.
 *
 * Pairs whose death julian-day precedes the birth julian-day
 * (recording error) are silently dropped so they don't artificially
 * inflate the mortality rate; the caller already filters out
 * individuals without both dates.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ChildMortalityRate
{
    /**
     * Five years expressed in julian days, used as the default age
     * cut-off. Five is the WHO/UN standard "under-5 mortality"
     * threshold and gives a directly-comparable indicator across
     * historical periods.
     */
    public const int DEFAULT_THRESHOLD_DAYS = 5 * 365;

    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Compute the child-mortality summary for a list of julian-day
     * pairs. Returns `null` when no valid pair survives the input
     * filter — the caller renders a "no data" placeholder rather
     * than a misleading "0 %".
     *
     * @param iterable<array{birthJd: int, deathJd: int}> $pairs         Iterable of valid BIRT + DEAT julian-day pairs
     * @param int                                         $thresholdDays Death-before-N-days cut-off, defaults to five years
     */
    public static function compute(iterable $pairs, int $thresholdDays = self::DEFAULT_THRESHOLD_DAYS): ?ChildMortalitySummary
    {
        $total = 0;
        $died  = 0;

        foreach ($pairs as $pair) {
            $birthJd = $pair['birthJd'];
            $deathJd = $pair['deathJd'];

            if ($birthJd <= 0) {
                continue;
            }

            if ($deathJd <= 0) {
                continue;
            }

            if ($deathJd < $birthJd) {
                continue;
            }

            ++$total;

            if (($deathJd - $birthJd) < $thresholdDays) {
                ++$died;
            }
        }

        if ($total === 0) {
            return null;
        }

        return new ChildMortalitySummary(
            total: $total,
            died: $died,
            rate: round(($died / $total) * 100, 1),
        );
    }
}
