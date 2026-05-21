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

use function count;
use function intdiv;
use function usort;

/**
 * Flag families whose siblings violate plausible interval bounds:
 * either too close together (under nine months between consecutive
 * dated births — biologically implausible for the same mother), or
 * an age-gap larger than 50 years between the earliest and latest
 * child (almost always two records that should belong to two
 * different families on the same parent).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class SiblingIntervalRule implements FamilyRule
{
    private const int APPROX_DAYS_PER_MONTH = 30;

    /**
     * Locale-independent rule id.
     */
    public function id(): string
    {
        return 'sibling-interval';
    }

    /**
     * Two-branch fire pattern: the rule may yield multiple
     * findings per family — once per child that is too close to
     * its predecessor (linked to the offending sibling) and
     * once for an excessive first-to-last age-gap (linked to the
     * family record itself). Callers must therefore not assume
     * one-finding-per-family.
     */
    public function check(string $xref, string $gedcom, array $children, array $context): iterable
    {
        $dated = [];

        foreach ($children as $child) {
            $childJd = $child['birthJd'];

            if ($childJd <= 0) {
                continue;
            }

            $dated[] = ['xref' => $child['xref'], 'birthJd' => $childJd];
        }

        if (count($dated) < 2) {
            return;
        }

        usort($dated, static fn (array $a, array $b): int => $a['birthJd'] <=> $b['birthJd']);

        // Pair-wise minimum interval guard.
        $previous = null;

        foreach ($dated as $current) {
            if ($previous !== null) {
                $deltaDays = $current['birthJd'] - $previous['birthJd'];

                if (($deltaDays > 0) && ($deltaDays < Thresholds::SIBLING_MIN_INTERVAL_MONTHS * self::APPROX_DAYS_PER_MONTH)) {
                    yield new Finding(
                        $current['xref'],
                        'individual',
                        $this->id(),
                        I18N::translate('Born only %s days after the previous sibling — below the nine-month plausibility floor.', I18N::number($deltaDays)),
                    );
                }
            }

            $previous = $current;
        }

        // First-to-last age-gap guard. Only fire on the family
        // record itself so a 60-year gap caused by a remarriage on
        // the same parent surfaces once, not twice.
        $first = $dated[0];
        $last  = $dated[count($dated) - 1];
        $years = intdiv($last['birthJd'] - $first['birthJd'], 365);

        if ($years > Thresholds::SIBLING_MAX_GAP_YEARS) {
            yield new Finding(
                $xref,
                'family',
                $this->id(),
                I18N::translate('First-to-last sibling age-gap of %s years exceeds the plausibility ceiling.', I18N::number($years)),
            );
        }
    }
}
