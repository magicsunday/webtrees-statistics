<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Calc;

use function intdiv;

/**
 * Shared bucket-label scheme used by every "X-year band" widget (age at
 * marriage, marriage duration, sibling gaps, age at first child, …). One place
 * to change the label format ("5–9" vs "5-9" vs "5 to 9") and one place to
 * evolve the overflow convention.
 *
 * The layout: `[min .. max)` divided into bands of `$width`, labelled
 * `"low–high"` (en-dash), plus a `"max+"` overflow bucket for values that land
 * at or beyond `$max`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class AgeBuckets
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Build a zero-initialised bucket map across `[minInclusive, maxExclusive)`
     * plus a `"maxExclusive+"` overflow band, all in insertion order so the
     * rendering preserves a stable axis.
     *
     * @return array<string, int>
     */
    public static function init(int $minInclusive, int $maxExclusive, int $width): array
    {
        $buckets = [];

        for ($lower = $minInclusive; $lower < $maxExclusive; $lower += $width) {
            $buckets[$lower . '–' . ($lower + $width - 1)] = 0;
        }

        $buckets[$maxExclusive . '+'] = 0;

        return $buckets;
    }

    /**
     * Resolve a value to the matching bucket label in {@see init()} layout.
     * Values at or above `$maxExclusive` collapse onto the overflow band.
     */
    public static function label(int $value, int $maxExclusive, int $width): string
    {
        if ($value >= $maxExclusive) {
            return $maxExclusive . '+';
        }

        $lower = intdiv($value, $width) * $width;

        return $lower . '–' . ($lower + $width - 1);
    }
}
