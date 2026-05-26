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
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\FamilyCountRecord;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\FamilyDurationDaysRecord;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\FamilyDurationYearsRecord;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\IndividualAgeRecord;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\IndividualCountRecord;
use MagicSunday\Webtrees\Statistic\View\RecordCategory;

/**
 * Flattens the typed record DTOs in `TreeRecordsReport` into the
 * `[cat, label, value, who, url]` row shape that the
 * `components/records-grid.phtml` partial iterates over. Each
 * factory method covers one record-holder shape — `years()` for
 * individual age-records, `familyYears()` / `familyDays()` for
 * marriage durations, `marriages()` / `children()` /
 * `familyChildren()` for count records. Every method returns
 * `null` when the source record is missing so the caller can
 * `array_filter()` the assembled list without a per-record `if`.
 *
 * The pluralised value strings live here as literal
 * `I18N::plural(...)` calls — xgettext extracts the msgids from
 * this file the same way it would from the original template.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class RecordRowMapper
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Year-aged individual record (`oldestDeceased`, `oldestLiving`,
     * `youngest/oldestHusband/Wife`, `youngest/oldestFather/Mother
     * AtFirstChild`).
     *
     * @return array{cat: string, label: string, value: string, who: string, url: string}|null
     */
    public static function years(RecordCategory $cat, string $label, ?IndividualAgeRecord $record): ?array
    {
        if (!$record instanceof IndividualAgeRecord) {
            return null;
        }

        return [
            'cat'   => $cat->value,
            'label' => $label,
            'value' => I18N::plural('%s year', '%s years', $record->ageYears, I18N::number($record->ageYears)),
            'who'   => $record->individual->fullName(),
            'url'   => $record->individual->url(),
        ];
    }

    /**
     * Family marriage-duration record measured in years
     * (`longestMarriage`).
     *
     * @return array{cat: string, label: string, value: string, who: string, url: string}|null
     */
    public static function familyYears(RecordCategory $cat, string $label, ?FamilyDurationYearsRecord $record): ?array
    {
        if (!$record instanceof FamilyDurationYearsRecord) {
            return null;
        }

        return [
            'cat'   => $cat->value,
            'label' => $label,
            'value' => I18N::plural('%s year', '%s years', $record->durationYears, I18N::number($record->durationYears)),
            'who'   => $record->family->fullName(),
            'url'   => $record->family->url(),
        ];
    }

    /**
     * Family marriage-duration record measured in days
     * (`shortestMarriage`).
     *
     * @return array{cat: string, label: string, value: string, who: string, url: string}|null
     */
    public static function familyDays(RecordCategory $cat, string $label, ?FamilyDurationDaysRecord $record): ?array
    {
        if (!$record instanceof FamilyDurationDaysRecord) {
            return null;
        }

        return [
            'cat'   => $cat->value,
            'label' => $label,
            'value' => I18N::plural('%s day', '%s days', $record->durationDays, I18N::number($record->durationDays)),
            'who'   => $record->family->fullName(),
            'url'   => $record->family->url(),
        ];
    }

    /**
     * Individual marriage-count record (`mostSpouses`).
     *
     * @return array{cat: string, label: string, value: string, who: string, url: string}|null
     */
    public static function marriages(RecordCategory $cat, string $label, ?IndividualCountRecord $record): ?array
    {
        if (!$record instanceof IndividualCountRecord) {
            return null;
        }

        return [
            'cat'   => $cat->value,
            'label' => $label,
            'value' => I18N::plural('%s marriage', '%s marriages', $record->count, I18N::number($record->count)),
            'who'   => $record->individual->fullName(),
            'url'   => $record->individual->url(),
        ];
    }

    /**
     * Individual child-count record (`mostChildrenPerPerson`).
     *
     * @return array{cat: string, label: string, value: string, who: string, url: string}|null
     */
    public static function children(RecordCategory $cat, string $label, ?IndividualCountRecord $record): ?array
    {
        if (!$record instanceof IndividualCountRecord) {
            return null;
        }

        return [
            'cat'   => $cat->value,
            'label' => $label,
            'value' => I18N::plural('%s child', '%s children', $record->count, I18N::number($record->count)),
            'who'   => $record->individual->fullName(),
            'url'   => $record->individual->url(),
        ];
    }

    /**
     * Family child-count record (`largestFamily`).
     *
     * @return array{cat: string, label: string, value: string, who: string, url: string}|null
     */
    public static function familyChildren(RecordCategory $cat, string $label, ?FamilyCountRecord $record): ?array
    {
        if (!$record instanceof FamilyCountRecord) {
            return null;
        }

        return [
            'cat'   => $cat->value,
            'label' => $label,
            'value' => I18N::plural('%s child', '%s children', $record->count, I18N::number($record->count)),
            'who'   => $record->family->fullName(),
            'url'   => $record->family->url(),
        ];
    }
}
