<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use Fisharebest\Webtrees\I18N;

use function abs;
use function count;
use function explode;
use function ltrim;
use function max;
use function min;
use function str_starts_with;
use function substr;

/**
 * Folds a `[label => count]` couple-age-gap distribution into the
 * diverging-bar row shape — `[label, value, sign, tooltipLabel,
 * tooltip]`. The husband-older side is encoded as negative labels
 * (`'-5 to -10'`, `'<-30'`) in the upstream distribution; this
 * mapper cleans those into positive band labels (`'5–10'`, `'>30'`)
 * and tags the row with `sign = -1` so the diverging-bar widget can
 * mirror it onto the left half of the chart. Wife-older bands pass
 * through with `sign = 1`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class CoupleAgeGapRowMapper
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Build the diverging-bar rows. PHP auto-casts numeric-string
     * array keys to int, so the input can carry bare-negative-int
     * keys (`-15`) as well as the documented `'-X to -Y'` /
     * `'<-N'` range forms — the loop coerces to string before the
     * label inspection runs.
     *
     * @param array<int|string, int> $ageGap Label → count map (labels can carry leading `-` / `<-` markers indicating the husband-older side)
     *
     * @return list<array{label: string, value: int, sign: int, tooltipLabel: string, tooltip: string}>
     */
    public static function toRows(array $ageGap): array
    {
        $rows = [];

        foreach ($ageGap as $label => $count) {
            $labelStr   = (string) $label;
            $first      = $labelStr !== '' ? $labelStr[0] : '+';
            $isNegative = ($first === '-') || ($first === '<');
            $cleaned    = $isNegative ? self::cleanNegativeLabel($labelStr) : $labelStr;

            $rows[] = [
                'label'        => $cleaned,
                'value'        => $count,
                'sign'         => $isNegative ? -1 : 1,
                'tooltipLabel' => $isNegative
                    ? I18N::translate('Husband older by %s years', $cleaned)
                    : I18N::translate('Wife older by %s years', $cleaned),
                'tooltip' => I18N::plural('%s couple', '%s couples', $count, I18N::number($count)),
            ];
        }

        return $rows;
    }

    /**
     * Strip the husband-older sign markers and reformat the band so
     * the resulting label is positive: `'-5 to -10'` → `'5–10'`,
     * `'<-30'` → `'>30'`, bare `'-15'` → `'15'`.
     */
    private static function cleanNegativeLabel(string $label): string
    {
        if (str_starts_with($label, '<')) {
            return '>' . substr($label, 2);
        }

        $parts = explode(' to ', $label);

        if (count($parts) === 2) {
            $low  = abs((int) $parts[0]);
            $high = abs((int) $parts[1]);

            return min($low, $high) . '–' . max($low, $high);
        }

        return ltrim($label, '-');
    }
}
