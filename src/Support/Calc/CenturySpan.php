<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Calc;

use function array_filter;
use function array_values;
use function max;
use function min;
use function range;

/**
 * The century axis of a timeline.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class CenturySpan
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Every century from the earliest to the latest of the given ones, so a
     * century without enough data stays on the axis between two that have it.
     * Centuries are signed, negative for BCE, and there is no century zero.
     *
     * @param list<int> $qualifying Centuries with enough data, in any order
     *
     * @return list<int>
     */
    public static function covering(array $qualifying): array
    {
        if ($qualifying === []) {
            return [];
        }

        return array_values(
            array_filter(
                range(min($qualifying), max($qualifying)),
                static fn (int $century): bool => $century !== 0,
            )
        );
    }
}
