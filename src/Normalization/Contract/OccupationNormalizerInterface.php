<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Normalization\Contract;

use MagicSunday\Webtrees\Statistic\Normalization\NormalizedOccupation;

/**
 * Narrow seam through which the occupation aggregations resolve a raw `1 OCCU`
 * string to a standardized trade. The module is a CONSUMER of whatever
 * occupation standardization a site has available — it never owns the data. The
 * default implementation ({@see RawOccupationNormalizer}) is a pure identity
 * that returns null for everything, so nothing changes until a real provider is
 * present; the adapter implementation resolves an installed standardization
 * module in-process.
 *
 * A `null` result always means "unknown — keep the raw value", so every call
 * site falls back to the raw string and no occupation is ever dropped.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
interface OccupationNormalizerInterface
{
    /**
     * Resolve a whole distinct-trade set at once. The occupation aggregations
     * fold on the batch result, so a provider can initialise its normalization
     * data a single time instead of once per term. Each input maps to its
     * standardized trade, or to null when the provider cannot place it (unknown
     * term, provider absent, status-only value) — null keeps the raw value.
     *
     * @param iterable<string> $rawOccupations The distinct raw `1 OCCU` values to resolve
     * @param string|null      $language       BCP-47 language every value is written in, or null when unknown
     *
     * @return array<string, NormalizedOccupation|null> Keyed by each distinct input string; null keeps the raw value
     */
    public function normalizeMany(iterable $rawOccupations, ?string $language = null): array;
}
