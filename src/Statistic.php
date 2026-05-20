<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\StatisticsData;
use MagicSunday\Webtrees\Statistic\Repository\EventRepository;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRepository;
use MagicSunday\Webtrees\Statistic\Repository\NameRepository;
use MagicSunday\Webtrees\Statistic\Repository\TreeHealthRepository;

use function array_sum;
use function array_values;
use function count;
use function is_array;
use function usort;

/**
 * Aggregator service that backs the statistics-chart tab partials.
 *
 * Delegates every count that the core `StatisticsData` accessor exposes
 * directly to it; uses local repositories only for the things core does
 * not provide (marital-status classification by Census semantics, zodiac
 * grouping, primary-name distinct counts).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class Statistic
{
    private const int NAME_FREQUENCY_THRESHOLD = 1;

    /**
     * @param StatisticsData       $data                 Core data accessor for individual, family and event counts
     * @param FamilyRepository     $familyRepository     Census-style marital classification not exposed by core
     * @param EventRepository      $eventRepository      Zodiac-sign grouping not exposed by core
     * @param NameRepository       $nameRepository       Distinct primary-name counts (bypass commonSurnames/GivenNames limit-zero quirk)
     * @param TreeHealthRepository $treeHealthRepository Data-quality metrics: source coverage, missing-event gaps, generation length
     */
    public function __construct(
        private StatisticsData $data,
        private FamilyRepository $familyRepository,
        private EventRepository $eventRepository,
        private NameRepository $nameRepository,
        private TreeHealthRepository $treeHealthRepository,
    ) {
    }

    /**
     * Total individuals in the tree (every status, including the
     * unknown-sex and the deceased).
     */
    public function getTotalIndividuals(): int
    {
        return $this->data->countIndividuals();
    }

    /**
     * @return array<int, array{label: string, value: int, class: string}>
     */
    public function getTotalIndividualsData(): array
    {
        return [
            ['label' => I18N::translate('Male'), 'value' => $this->data->countIndividualsBySex('M'), 'class' => 'male'],
            ['label' => I18N::translate('Female'), 'value' => $this->data->countIndividualsBySex('F'), 'class' => 'female'],
            ['label' => I18N::translate('Unknown'), 'value' => $this->data->countIndividualsBySex('U'), 'class' => 'unknown'],
        ];
    }

    /**
     * @return array<int, array{label: string, value: int, class: string}>
     */
    public function getTotalLivingDeceasedData(): array
    {
        return [
            ['label' => I18N::translate('Living'), 'value' => $this->data->countIndividualsLiving(), 'class' => 'living'],
            ['label' => I18N::translate('Deceased'), 'value' => $this->data->countIndividualsDeceased(), 'class' => 'deceased'],
        ];
    }

    /**
     * Marital-status breakdown for the donut chart. Each living individual
     * is classified into exactly one bucket by the family repository using
     * precedence current > divorced > widowed > single, so the four
     * slices sum to the living-individual total without clamping.
     *
     * @return array<int, array{label: string, value: int, class: string}>
     */
    public function getFamilyStatusData(): array
    {
        $buckets = $this->familyRepository->classifyLivingIndividuals();

        return [
            ['label' => I18N::translate('Married'), 'value' => $buckets[MaritalBucket::Current->value], 'class' => 'married'],
            ['label' => I18N::translate('Single'), 'value' => $buckets[MaritalBucket::Single->value], 'class' => 'single'],
            ['label' => I18N::translate('Widowed'), 'value' => $buckets[MaritalBucket::Widowed->value], 'class' => 'widowed'],
            ['label' => I18N::translate('Divorced'), 'value' => $buckets[MaritalBucket::Divorced->value], 'class' => 'divorced'],
        ];
    }

    /**
     * Count of distinct primary surnames in the tree, computed from the
     * same aggregation that feeds the Top-N tag cloud.
     */
    public function getTotalSurnames(): int
    {
        return $this->nameRepository->countDistinctSurnames(self::NAME_FREQUENCY_THRESHOLD);
    }

    /**
     * @param int $limit Maximum number of surnames to return
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function getTopSurnames(int $limit): array
    {
        return $this->shapeSurnameList(
            $this->data->commonSurnames($limit, self::NAME_FREQUENCY_THRESHOLD, 'count'),
        );
    }

    /**
     * Count of distinct primary given names recorded on male
     * individuals.
     */
    public function getTotalMaleGivenNames(): int
    {
        return $this->nameRepository->countDistinctGivenNames('M', self::NAME_FREQUENCY_THRESHOLD);
    }

    /**
     * @param int $limit Maximum number of given names to return
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function getTopMaleGivenNames(int $limit): array
    {
        return $this->shapeGivenNameList(
            $this->data->commonGivenNames('M', self::NAME_FREQUENCY_THRESHOLD, $limit),
        );
    }

    /**
     * Count of distinct primary given names recorded on female
     * individuals.
     */
    public function getTotalFemaleGivenNames(): int
    {
        return $this->nameRepository->countDistinctGivenNames('F', self::NAME_FREQUENCY_THRESHOLD);
    }

    /**
     * @param int $limit Maximum number of given names to return
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function getTopFemaleGivenNames(int $limit): array
    {
        return $this->shapeGivenNameList(
            $this->data->commonGivenNames('F', self::NAME_FREQUENCY_THRESHOLD, $limit),
        );
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByMonth(): array
    {
        return $this->translateMonthKeys($this->data->countEventsByMonth('BIRT', 0, 0));
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByCentury(): array
    {
        return $this->reshapeCenturyRows($this->data->countEventsByCentury('BIRT'));
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByZodiacSign(): array
    {
        return $this->eventRepository->getBirthsByZodiacSign();
    }

    /**
     * Country grouping for births. Returns an empty list until core
     * exposes a public accessor; the WorldMap widget falls back to its
     * empty-state rendering.
     *
     * @todo Restore once webtrees exposes a public country-grouping accessor.
     *
     * @return array<int, array{countryCode: string, label: string, count: int}>
     */
    public function getBirthsByCountry(): array
    {
        return [];
    }

    /**
     * @return array<string, int>
     */
    public function getDeathsByMonth(): array
    {
        return $this->translateMonthKeys($this->data->countEventsByMonth('DEAT', 0, 0));
    }

    /**
     * @return array<string, int>
     */
    public function getDeathsByCentury(): array
    {
        return $this->reshapeCenturyRows($this->data->countEventsByCentury('DEAT'));
    }

    /**
     * Country grouping for deaths. Same deferral as {@see getBirthsByCountry()}.
     *
     * @todo Restore once webtrees exposes a public country-grouping accessor.
     *
     * @return array<int, array{countryCode: string, label: string, count: int}>
     */
    public function getDeathsByCountry(): array
    {
        return [];
    }

    /**
     * Source-citation coverage as `{value, total}`, ready for the
     * ProgressList partial to derive the percentage and absolute counts.
     *
     * @return array{value: int, total: int}
     */
    public function getSourceCitationCoverage(): array
    {
        return $this->treeHealthRepository->sourceCitationCoverage();
    }

    /**
     * Missing-event gap rates for BIRT / DEAT, each split into "event
     * missing" and "place missing" rows. Returned as `{event, kind,
     * value, total}` tuples so the consumer can render its own label
     * (keeping translations next to their consuming markup).
     *
     * @return array<int, array{event: string, kind: string, value: int, total: int}>
     */
    public function getMissingEventGaps(): array
    {
        return array_values($this->treeHealthRepository->missingEventGaps());
    }

    /**
     * Average years between a parent's birth and a child's birth across
     * every parent-child pair where both ends carry a parseable BIRT
     * date. Returns null when the tree has no usable pair.
     */
    public function getAverageGenerationLength(): ?float
    {
        return $this->treeHealthRepository->averageGenerationLength();
    }

    /**
     * Convert StatisticsData::commonSurnames output (surname → variant-count
     * map) into the [{label, value}] shape consumed by chart widgets, sorted
     * alphabetically by surname. Core's PHPDoc declares the shape as
     * `array<array<int>>`; in practice the outer keys are always the surname
     * strings and the inner array sums to the total occurrences.
     *
     * @param array<array-key, array<int>> $rows
     *
     * @return array<int, array{label: string, value: int}>
     */
    private function shapeSurnameList(array $rows): array
    {
        $entries = [];

        foreach ($rows as $surname => $variants) {
            $entries[] = ['label' => (string) $surname, 'value' => array_sum($variants)];
        }

        usort(
            $entries,
            static fn (array $x, array $y): int => $x['label'] <=> $y['label'],
        );

        return $entries;
    }

    /**
     * Convert StatisticsData::commonGivenNames output (iterable<string,int>)
     * into the [{label, value}] shape consumed by chart widgets, sorted
     * alphabetically by name.
     *
     * @param iterable<string, int> $rows
     *
     * @return array<int, array{label: string, value: int}>
     */
    private function shapeGivenNameList(iterable $rows): array
    {
        $entries = [];

        foreach ($rows as $name => $count) {
            $entries[] = ['label' => $name, 'value' => $count];
        }

        usort(
            $entries,
            static fn (array $x, array $y): int => $x['label'] <=> $y['label'],
        );

        return $entries;
    }

    /**
     * Translated NOMINATIVE month names keyed by the GEDCOM 3-letter abbreviation.
     *
     * @return array<string, string>
     */
    private function monthLabels(): array
    {
        return [
            'JAN' => I18N::translateContext('NOMINATIVE', 'January'),
            'FEB' => I18N::translateContext('NOMINATIVE', 'February'),
            'MAR' => I18N::translateContext('NOMINATIVE', 'March'),
            'APR' => I18N::translateContext('NOMINATIVE', 'April'),
            'MAY' => I18N::translateContext('NOMINATIVE', 'May'),
            'JUN' => I18N::translateContext('NOMINATIVE', 'June'),
            'JUL' => I18N::translateContext('NOMINATIVE', 'July'),
            'AUG' => I18N::translateContext('NOMINATIVE', 'August'),
            'SEP' => I18N::translateContext('NOMINATIVE', 'September'),
            'OCT' => I18N::translateContext('NOMINATIVE', 'October'),
            'NOV' => I18N::translateContext('NOMINATIVE', 'November'),
            'DEC' => I18N::translateContext('NOMINATIVE', 'December'),
        ];
    }

    /**
     * Replace 'JAN' / 'FEB' / … keys with translated month names, filling in
     * any month the dataset skipped so the donut always sees 12 keys.
     *
     * @param array<string, int> $rows
     *
     * @return array<string, int>
     */
    private function translateMonthKeys(array $rows): array
    {
        $out = [];

        foreach ($this->monthLabels() as $abbrev => $label) {
            $out[$label] = $rows[$abbrev] ?? 0;
        }

        return $out;
    }

    /**
     * Normalise StatisticsData::countEventsByCentury output (mixed shape:
     * either {century → count} or [[centuryLabel, count]] tuples) into the
     * {centuryLabel => count} map the bar chart consumes.
     *
     * @param array<int|string, int|array<int, int|string>> $rows
     *
     * @return array<string, int>
     */
    private function reshapeCenturyRows(array $rows): array
    {
        $out = [];

        foreach ($rows as $key => $row) {
            if (is_array($row) && count($row) >= 2) {
                $out[(string) $row[0]] = (int) $row[1];
            } else {
                $out[(string) $key] = (int) $row;
            }
        }

        return $out;
    }
}
