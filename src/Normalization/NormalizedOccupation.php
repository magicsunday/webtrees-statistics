<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Normalization;

/**
 * The result of resolving one raw `1 OCCU` string to a standardized trade. It
 * carries exactly the two things the occupation aggregations need — a stable
 * grouping key so spelling, casing and language variants of the same trade
 * collapse into one bucket, and a human-readable display label for that bucket
 * — plus the optional HISCO classification a provider may expose.
 *
 * The grouping key and the display label are deliberately separate: two records
 * of the same trade must fold onto the same {@see self::$groupingKey} even when
 * their {@see self::$displayLabel} would be rendered differently, and the
 * display label is chosen for readability, never for identity.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class NormalizedOccupation
{
    /**
     * @param string      $groupingKey  Stable identity under which every variant of this trade is counted (e.g. `de:Arzt`)
     * @param string      $displayLabel Human-readable label shown for the grouped trade (e.g. `Arzt`)
     * @param string|null $hiscoCode    Optional HISCO classification code, or null when the provider has none
     * @param float|null  $hiscamScore  Optional HISCAM social-status score, or null when the provider has none
     */
    public function __construct(
        public string $groupingKey,
        public string $displayLabel,
        public ?string $hiscoCode = null,
        public ?float $hiscamScore = null,
    ) {
    }
}
