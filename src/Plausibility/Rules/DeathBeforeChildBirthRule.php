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

/**
 * Flag families where either parent died BEFORE one of their
 * recorded children was born. The mother case allows a small
 * grace of 10 lunar months (~300 days) so a child born shortly
 * after a posthumous father does not count, but a child born
 * eleven months after the mother's death definitely indicates a
 * misattribution or a date typo.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DeathBeforeChildBirthRule implements FamilyRule
{
    /**
     * Posthumous-father grace in days: a baby born up to 300 days
     * after the father's death is biologically possible and not a
     * data error.
     */
    private const int POSTHUMOUS_GRACE_DAYS = 300;

    /**
     * Locale-independent rule id.
     */
    public function id(): string
    {
        return 'death-before-child-birth';
    }

    /**
     * Apply two different posthumous tolerances: the father case
     * allows a 300-day grace window for posthumous-father births,
     * the mother case has zero tolerance (mother death after
     * child birth is biologically required).
     */
    public function check(string $xref, string $gedcom, array $children, array $context): iterable
    {
        $fatherDeath = $context['fatherDeathJd'] ?? null;
        $motherDeath = $context['motherDeathJd'] ?? null;

        foreach ($children as $child) {
            $childXref = $child['xref'];
            $childJd   = $child['birthJd'];

            if ($childJd <= 0) {
                continue;
            }

            if (($fatherDeath !== null) && ($fatherDeath > 0) && (($childJd - $fatherDeath) > self::POSTHUMOUS_GRACE_DAYS)) {
                yield new Finding(
                    $childXref,
                    'individual',
                    $this->id(),
                    I18N::translate("Child was born more than nine months after the father's recorded death."),
                );
            }

            if (($motherDeath !== null) && ($motherDeath > 0) && ($childJd > $motherDeath)) {
                yield new Finding(
                    $childXref,
                    'individual',
                    $this->id(),
                    I18N::translate("Child was born after the mother's recorded death."),
                );
            }
        }
    }
}
