<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Normalization;

use MagicSunday\Webtrees\Statistic\Normalization\Contract\OccupationNormalizerInterface;
use MagicSunday\Webtrees\Statistic\Normalization\Support\StringList;

use function array_fill_keys;

/**
 * The identity normalizer used when no occupation-standardization provider is
 * available. It resolves every term to null, which every call site reads as
 * "unknown — keep the raw value". Wiring this default therefore leaves the
 * occupation aggregations byte-identical to their pre-normalization behaviour:
 * raw strings are still folded only by case, exactly as before.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class RawOccupationNormalizer implements OccupationNormalizerInterface
{
    /**
     * A null result for every distinct input, keyed by the input string.
     *
     * @param iterable<string> $rawOccupations The distinct raw `1 OCCU` values to resolve
     * @param string|null      $language       Ignored; this normalizer has no language-dependent behaviour
     *
     * @return array<string, NormalizedOccupation|null> Every input mapped to null
     */
    public function normalizeMany(iterable $rawOccupations, ?string $language = null): array
    {
        return array_fill_keys(StringList::of($rawOccupations), null);
    }
}
