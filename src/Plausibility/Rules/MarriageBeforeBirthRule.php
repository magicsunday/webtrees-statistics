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
use MagicSunday\Webtrees\Statistic\Support\GedcomScanner;

use function GregorianToJD;

/**
 * Flag families where the MARR julian-day predates either
 * spouse's BIRT julian-day. Negative ages-at-marriage are
 * almost always typos (year on the wrong side of a comma) and
 * caught by the data-entry-error class of plausibility checks.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MarriageBeforeBirthRule implements FamilyRule
{
    /**
     * Locale-independent rule id.
     */
    public function id(): string
    {
        return 'marriage-before-birth';
    }

    /**
     * Compare year-grained MARR against day-grained spouse-BIRT
     * julian-days. Year-resolution is enough — the rule fires on
     * data-entry typos (year on the wrong side of a comma), not
     * on borderline same-year cases, so the coarser MARR year
     * boundary at June 1 is the safest tie-breaker.
     */
    public function check(string $xref, string $gedcom, array $children, array $context): iterable
    {
        $marriageYear = GedcomScanner::extractEventYear($gedcom, 'MARR');

        if ($marriageYear === null) {
            return;
        }

        $fatherBirth = $context['fatherBirthJd'] ?? null;
        $motherBirth = $context['motherBirthJd'] ?? null;

        // Convert the MARR year to a coarse julian-day-of-year-mid so
        // the comparison stays year-grained without false positives
        // for couples married on Jan 1.
        $marriageJulianMid = GregorianToJD(6, 1, $marriageYear);

        if (($fatherBirth !== null) && ($fatherBirth > $marriageJulianMid)) {
            yield new Finding(
                $xref,
                'family',
                $this->id(),
                I18N::translate("Marriage was recorded before the husband's recorded birth."),
            );
        }

        if (($motherBirth !== null) && ($motherBirth > $marriageJulianMid)) {
            yield new Finding(
                $xref,
                'family',
                $this->id(),
                I18N::translate("Marriage was recorded before the wife's recorded birth."),
            );
        }
    }
}
