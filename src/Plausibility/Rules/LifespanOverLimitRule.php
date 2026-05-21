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
use MagicSunday\Webtrees\Statistic\Plausibility\Finding;
use MagicSunday\Webtrees\Statistic\Plausibility\IndividualRule;
use MagicSunday\Webtrees\Statistic\Plausibility\Thresholds;
use MagicSunday\Webtrees\Statistic\Support\GedcomScanner;

/**
 * Flag individuals whose DEAT − BIRT age exceeds the lifespan
 * cap. Useful for catching DEAT or BIRT typos where the year was
 * mis-entered by a century — a 220-year-old shows up immediately
 * and points the user at the wrong digit.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class LifespanOverLimitRule implements IndividualRule
{
    /**
     * Locale-independent rule id used by the aggregator for
     * grouping and by the view for translating section headings.
     */
    public function id(): string
    {
        return 'lifespan-over-limit';
    }

    /**
     * Compute the BIRT-DEAT year delta directly off the raw
     * GEDCOM via {@see GedcomScanner::extractEventYear()}; the
     * underlying year-grained scan is enough — the rule only
     * fires on hard typos (≥ 120 years apart), and that level of
     * precision does not benefit from julian-day arithmetic.
     */
    public function check(string $xref, string $gedcom): iterable
    {
        $birthYear = GedcomScanner::extractEventYear($gedcom, 'BIRT');
        $deathYear = GedcomScanner::extractEventYear($gedcom, 'DEAT');

        if (($birthYear === null) || ($deathYear === null)) {
            return;
        }

        $age = $deathYear - $birthYear;

        if ($age <= Thresholds::MAX_LIFESPAN_YEARS) {
            return;
        }

        yield new Finding(
            $xref,
            'individual',
            $this->id(),
            I18N::translate(
                'Lifespan of %1$s years exceeds the plausibility ceiling (%2$s years).',
                I18N::number($age),
                I18N::number(Thresholds::MAX_LIFESPAN_YEARS),
            ),
        );
    }
}
