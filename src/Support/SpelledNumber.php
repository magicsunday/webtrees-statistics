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

/**
 * Spell out small integers (1-12) for prose card copy that reads
 * better with words than with digits ("over six centuries" vs.
 * "over 6 centuries"). Numbers outside the small-integer range
 * fall through to the locale-formatted numeric string.
 */
final class SpelledNumber
{
    /**
     * Return the word for $n in the current locale when $n is in
     * 1..12; otherwise return the digit-formatted I18N::number.
     */
    public static function for(int $n): string
    {
        return match ($n) {
            1       => I18N::translateContext('count word', 'one'),
            2       => I18N::translateContext('count word', 'two'),
            3       => I18N::translateContext('count word', 'three'),
            4       => I18N::translateContext('count word', 'four'),
            5       => I18N::translateContext('count word', 'five'),
            6       => I18N::translateContext('count word', 'six'),
            7       => I18N::translateContext('count word', 'seven'),
            8       => I18N::translateContext('count word', 'eight'),
            9       => I18N::translateContext('count word', 'nine'),
            10      => I18N::translateContext('count word', 'ten'),
            11      => I18N::translateContext('count word', 'eleven'),
            12      => I18N::translateContext('count word', 'twelve'),
            default => I18N::number($n),
        };
    }
}
