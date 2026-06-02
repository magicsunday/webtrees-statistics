<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Marriage;

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
     * Durations of more than this many whole days read as years; up to and
     * including this many days stay in days. Two years, so a marriage only
     * switches to a year count once it spans at least two full years (where the
     * whole-year value is already 2, never a misleading "1 year").
     */
    private const int MAX_DAYS_AS_DAYS = 365 * 2;

    /**
     * @param string            $familyXref    The family's XREF, kept so equal labels stay distinct
     * @param string            $label         The human-readable couple label (both spouses' names)
     * @param int               $durationDays  Whole days between marriage and its end
     * @param int               $durationYears Whole years between marriage and its end
     * @param 'death'|'divorce' $endReason     How the marriage ended (earliest terminating event)
     */
    public function __construct(
        public string $familyXref,
        public string $label,
        public int $durationDays,
        public int $durationYears,
        public string $endReason,
    ) {
    }

    /**
     * The unit the duration reads best in for this marriage: whole days up to
     * two years, whole years beyond that. Picked per entry so a sub-year
     * "longest" marriage never shows as "0 years".
     *
     * @return 'days'|'years'
     */
    public function displayUnit(): string
    {
        return $this->durationDays > self::MAX_DAYS_AS_DAYS ? 'years' : 'days';
    }

    /**
     * The duration as a whole number in the unit chosen by displayUnit().
     */
    public function displayValue(): int
    {
        return $this->displayUnit() === 'years' ? $this->durationYears : $this->durationDays;
    }
}
