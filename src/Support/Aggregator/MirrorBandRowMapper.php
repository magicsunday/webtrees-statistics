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

/**
 * Folds a `[band => count]` distribution into the row shape the
 * `widgets/mirror-histogram.phtml` partial consumes — `[label, value,
 * tooltipLabel, tooltipBody]`. Every Family-tab mirror-histogram (age at
 * marriage, age at first child, age at divorce — each for both sexes) needs an
 * identical loop except for the noun pluralised in the tooltip body. The named
 * factory methods (`husbands()`, `wives()`, `fathers()`, `mothers()`, `men()`,
 * `women()`) keep the `I18N::plural(...)` calls literal so xgettext extracts
 * the msgids from this file the same way it would from each tab template.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MirrorBandRowMapper
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Mirror-histogram rows for the male side of the age-at-marriage
     * distribution. `tooltipBody` pluralises "husband / husbands".
     *
     * @param array<string, int> $bands Band-label → count map
     *
     * @return list<array{label: string, value: int, tooltipLabel: string, tooltipBody: string}>
     */
    public static function husbands(array $bands): array
    {
        $rows = [];

        foreach ($bands as $band => $count) {
            $rows[] = [
                'label'        => $band,
                'value'        => $count,
                'tooltipLabel' => I18N::translate('%s years', $band),
                'tooltipBody'  => I18N::plural('%s husband', '%s husbands', $count, I18N::number($count)),
            ];
        }

        return $rows;
    }

    /**
     * Mirror-histogram rows for the female side of the age-at- marriage
     * distribution. `tooltipBody` pluralises "wife / wives".
     *
     * @param array<string, int> $bands
     *
     * @return list<array{label: string, value: int, tooltipLabel: string, tooltipBody: string}>
     */
    public static function wives(array $bands): array
    {
        $rows = [];

        foreach ($bands as $band => $count) {
            $rows[] = [
                'label'        => $band,
                'value'        => $count,
                'tooltipLabel' => I18N::translate('%s years', $band),
                'tooltipBody'  => I18N::plural('%s wife', '%s wives', $count, I18N::number($count)),
            ];
        }

        return $rows;
    }

    /**
     * Mirror-histogram rows for the male side of the age-at-first- child
     * distribution. `tooltipBody` pluralises "father / fathers".
     *
     * @param array<string, int> $bands
     *
     * @return list<array{label: string, value: int, tooltipLabel: string, tooltipBody: string}>
     */
    public static function fathers(array $bands): array
    {
        $rows = [];

        foreach ($bands as $band => $count) {
            $rows[] = [
                'label'        => $band,
                'value'        => $count,
                'tooltipLabel' => I18N::translate('%s years', $band),
                'tooltipBody'  => I18N::plural('%s father', '%s fathers', $count, I18N::number($count)),
            ];
        }

        return $rows;
    }

    /**
     * Mirror-histogram rows for the female side of the age-at-first- child
     * distribution. `tooltipBody` pluralises "mother / mothers".
     *
     * @param array<string, int> $bands
     *
     * @return list<array{label: string, value: int, tooltipLabel: string, tooltipBody: string}>
     */
    public static function mothers(array $bands): array
    {
        $rows = [];

        foreach ($bands as $band => $count) {
            $rows[] = [
                'label'        => $band,
                'value'        => $count,
                'tooltipLabel' => I18N::translate('%s years', $band),
                'tooltipBody'  => I18N::plural('%s mother', '%s mothers', $count, I18N::number($count)),
            ];
        }

        return $rows;
    }

    /**
     * Mirror-histogram rows for the male side of the age-at-divorce
     * distribution. `tooltipBody` pluralises "man / men".
     *
     * @param array<string, int> $bands
     *
     * @return list<array{label: string, value: int, tooltipLabel: string, tooltipBody: string}>
     */
    public static function men(array $bands): array
    {
        $rows = [];

        foreach ($bands as $band => $count) {
            $rows[] = [
                'label'        => $band,
                'value'        => $count,
                'tooltipLabel' => I18N::translate('%s years', $band),
                'tooltipBody'  => I18N::plural('%s man', '%s men', $count, I18N::number($count)),
            ];
        }

        return $rows;
    }

    /**
     * Mirror-histogram rows for the female side of the age-at-divorce
     * distribution. `tooltipBody` pluralises "woman / women".
     *
     * @param array<string, int> $bands
     *
     * @return list<array{label: string, value: int, tooltipLabel: string, tooltipBody: string}>
     */
    public static function women(array $bands): array
    {
        $rows = [];

        foreach ($bands as $band => $count) {
            $rows[] = [
                'label'        => $band,
                'value'        => $count,
                'tooltipLabel' => I18N::translate('%s years', $band),
                'tooltipBody'  => I18N::plural('%s woman', '%s women', $count, I18N::number($count)),
            ];
        }

        return $rows;
    }
}
