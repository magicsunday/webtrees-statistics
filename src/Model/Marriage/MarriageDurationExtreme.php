<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Marriage;

use MagicSunday\Webtrees\Statistic\Enum\MarriageEndReason;

/**
 * One marriage at an extreme of the duration distribution (a shortest or a
 * longest marriage). Carries the family XREF so equal labels stay distinct, the
 * resolved couple label, the duration in whole days and whole years, and how
 * the marriage ended. The unit a row is rendered in (days or whole years) is
 * chosen per entry by {@see self::displayUnit()}, not fixed per list.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MarriageDurationExtreme
{
    /**
     * The whole-year count at which a duration starts reading in years rather
     * than days. Below two whole years a marriage reads in days, so a sub-year
     * or just-over-a-year "longest" entry never shows a misleading "0 years" or
     * "1 year".
     */
    private const int MIN_YEARS_AS_YEARS = 2;

    /**
     * @param string                 $familyXref    The family's XREF, kept so equal labels stay distinct
     * @param string                 $label         The human-readable couple label (both spouses' names)
     * @param int                    $durationDays  Whole days between marriage and its end
     * @param int                    $durationYears Whole years between marriage and its end
     * @param MarriageEndReason|null $endReason     How the marriage ended (earliest terminating event), or null when
     *                                              suppressed because the record is not visible to the current user
     */
    public function __construct(
        public string $familyXref,
        public string $label,
        public int $durationDays,
        public int $durationYears,
        public ?MarriageEndReason $endReason,
    ) {
    }

    /**
     * The unit the duration reads best in for this marriage: whole days below
     * two years, whole years from two years on. Picked per entry so a sub-year
     * "longest" marriage never shows as "0 years".
     *
     * @return 'days'|'years'
     */
    public function displayUnit(): string
    {
        return $this->durationYears >= self::MIN_YEARS_AS_YEARS ? 'years' : 'days';
    }

    /**
     * The duration as a whole number in the unit chosen by displayUnit().
     */
    public function displayValue(): int
    {
        return $this->displayUnit() === 'years' ? $this->durationYears : $this->durationDays;
    }
}
