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

use function mb_strtolower;

/**
 * Builds the raw-value → (fold key, display label) map both occupation
 * aggregations fold on. The normalizer is consulted ONCE for the whole distinct
 * set (so a provider initialises its data a single time), and every value the
 * provider cannot place falls back to the pre-normalization behaviour: the
 * case-folded raw string as the key, the raw string as the label. A normalized
 * value instead folds under its {@see NormalizedOccupation::$groupingKey} and
 * displays as its {@see NormalizedOccupation::$displayLabel}, so spelling,
 * casing and language variants of one trade collapse into a single bucket.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class OccupationFolding
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Resolve every distinct raw `1 OCCU` value to its fold key and display
     * label. Unknown values keep the pre-normalization fold — case-folded key,
     * raw label — so no occupation is ever lost.
     *
     * @param iterable<string>              $rawValues  The distinct raw `1 OCCU` values to resolve
     * @param OccupationNormalizerInterface $normalizer The provider consulted once for the whole set
     * @param string|null                   $language   BCP-47 language the values are written in, or null when unknown
     *
     * @return array<string, array{0: string, 1: string}> Raw value => [fold key, display label]
     */
    public static function map(iterable $rawValues, OccupationNormalizerInterface $normalizer, ?string $language): array
    {
        // Materialise to a string list first: the callers collect the distinct
        // set as array keys, which coerces a purely numeric value to an int, so
        // reading the raw values back as strings here is what keeps
        // mb_strtolower() from throwing under strict types. Materialising also
        // lets the input be both resolved and iterated without draining a
        // generator twice.
        $values     = StringList::of($rawValues);
        $normalized = $normalizer->normalizeMany($values, $language);

        $folds = [];

        foreach ($values as $rawValue) {
            $occupation = $normalized[$rawValue] ?? null;

            $folds[$rawValue] = $occupation instanceof NormalizedOccupation
                ? [$occupation->groupingKey, $occupation->displayLabel]
                : [mb_strtolower($rawValue), $rawValue];
        }

        return $folds;
    }
}
