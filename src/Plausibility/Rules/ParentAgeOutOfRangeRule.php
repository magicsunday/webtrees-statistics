<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Plausibility\Rules;

use Fisharebest\Webtrees\I18N;
use MagicSunday\Webtrees\Statistic\Plausibility\FamilyRule;
use MagicSunday\Webtrees\Statistic\Plausibility\Finding;
use MagicSunday\Webtrees\Statistic\Plausibility\Thresholds;

use function intdiv;

/**
 * Flag families where a parent's age at the birth of any of their
 * children falls outside the plausibility band. Both sides
 * (father / mother) are checked from the single family record so
 * the rule fires once per child rather than re-iterating both
 * parents for every CHIL link.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ParentAgeOutOfRangeRule implements FamilyRule
{
    /**
     * Locale-independent rule id.
     */
    public function id(): string
    {
        return 'parent-age-out-of-range';
    }

    /**
     * Walk every child in the family once and emit per-parent
     * findings against the {@see Thresholds} bounds. Combining
     * both parents into one loop avoids iterating the CHIL list
     * twice — cheaper than two separate per-parent rules.
     */
    public function check(string $xref, string $gedcom, array $children, array $context): iterable
    {
        $fatherBirth = $context['fatherBirthJd'] ?? null;
        $motherBirth = $context['motherBirthJd'] ?? null;

        foreach ($children as $child) {
            $childXref = $child['xref'];
            $childJd   = $child['birthJd'];

            if ($childJd <= 0) {
                continue;
            }

            if (($motherBirth !== null) && ($motherBirth > 0)) {
                $age = intdiv($childJd - $motherBirth, 365);

                if (($age < Thresholds::MOTHER_MIN_AGE) || ($age > Thresholds::MOTHER_MAX_AGE)) {
                    yield new Finding(
                        $childXref,
                        'individual',
                        $this->id(),
                        I18N::translate(
                            'Mother age %1$s at this birth is outside the plausible range (%2$s–%3$s).',
                            I18N::number($age),
                            I18N::number(Thresholds::MOTHER_MIN_AGE),
                            I18N::number(Thresholds::MOTHER_MAX_AGE),
                        ),
                    );
                }
            }

            if (($fatherBirth !== null) && ($fatherBirth > 0)) {
                $age = intdiv($childJd - $fatherBirth, 365);

                if (($age < Thresholds::FATHER_MIN_AGE) || ($age > Thresholds::FATHER_MAX_AGE)) {
                    yield new Finding(
                        $childXref,
                        'individual',
                        $this->id(),
                        I18N::translate(
                            'Father age %1$s at this birth is outside the plausible range (%2$s–%3$s).',
                            I18N::number($age),
                            I18N::number(Thresholds::FATHER_MIN_AGE),
                            I18N::number(Thresholds::FATHER_MAX_AGE),
                        ),
                    );
                }
            }
        }
    }
}
