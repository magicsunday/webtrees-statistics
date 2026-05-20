<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model;

/**
 * Immutable DTO that captures one row of the
 * (living-individual × family-membership) projection consumed by the
 * marital-state classifier. Lifted out of `stdClass` so that the
 * classifier can rely on a typed shape instead of `mixed` properties.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class FamilyRow
{
    /**
     * @param string      $individualId XREF of the living individual being classified
     * @param string|null $husbandId    XREF of the family's first HUSB (null if absent or empty)
     * @param string|null $wifeId       XREF of the family's first WIFE (null if absent or empty)
     * @param string|null $familyGedcom Raw f_gedcom blob, or null when the LEFT JOIN had no family match
     */
    public function __construct(
        public string $individualId,
        public ?string $husbandId,
        public ?string $wifeId,
        public ?string $familyGedcom,
    ) {
    }
}
