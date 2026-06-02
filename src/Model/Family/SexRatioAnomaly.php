<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Family;

/**
 * One family whose recorded children are heavily skewed toward one sex. Carries
 * the family XREF so two families that share a display label stay distinct, the
 * resolved display label, and the son / daughter counts the view renders as the
 * two-segment bar.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class SexRatioAnomaly
{
    /**
     * @param string $familyXref The family's XREF, kept so equal labels stay distinct
     * @param string $label      The human-readable display label (the couple's names)
     * @param int    $sons       Number of sons recorded in the family
     * @param int    $daughters  Number of daughters recorded in the family
     */
    public function __construct(
        public string $familyXref,
        public string $label,
        public int $sons,
        public int $daughters,
    ) {
    }

    /**
     * Total number of sexed children — the denominator of the skew ratio.
     */
    public function total(): int
    {
        return $this->sons + $this->daughters;
    }
}
