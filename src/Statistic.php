<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic;

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\StatisticsData;
use MagicSunday\Webtrees\Statistic\Repository\ChildMortalityRepository;
use MagicSunday\Webtrees\Statistic\Repository\ChildrenRepository;
use MagicSunday\Webtrees\Statistic\Repository\CountryRepository;
use MagicSunday\Webtrees\Statistic\Repository\DeathCauseRepository;
use MagicSunday\Webtrees\Statistic\Repository\DivorceRepository;
use MagicSunday\Webtrees\Statistic\Repository\EndogamyRepository;
use MagicSunday\Webtrees\Statistic\Repository\EventRepository;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRepository;
use MagicSunday\Webtrees\Statistic\Repository\GenerationDepthRepository;
use MagicSunday\Webtrees\Statistic\Repository\GivenNameTrendsRepository;
use MagicSunday\Webtrees\Statistic\Repository\KinshipRepository;
use MagicSunday\Webtrees\Statistic\Repository\LifeSpanRepository;
use MagicSunday\Webtrees\Statistic\Repository\MarriageMatrixRepository;
use MagicSunday\Webtrees\Statistic\Repository\MarriageRepository;
use MagicSunday\Webtrees\Statistic\Repository\MigrationRepository;
use MagicSunday\Webtrees\Statistic\Repository\NameRepository;
use MagicSunday\Webtrees\Statistic\Repository\OccupationRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParenthoodRepository;
use MagicSunday\Webtrees\Statistic\Repository\PlaceDispersionRepository;
use MagicSunday\Webtrees\Statistic\Repository\ReligionRepository;
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
     * @param StatisticsData            $data                      Core data accessor for individual, family and event counts
     * @param FamilyRepository          $familyRepository          Census-style marital classification not exposed by core
     * @param EventRepository           $eventRepository           Zodiac-sign grouping not exposed by core
     * @param NameRepository            $nameRepository            Distinct primary-name counts (bypass commonSurnames/GivenNames limit-zero quirk)
     * @param TreeHealthRepository      $treeHealthRepository      Data-quality metrics: source coverage, missing-event gaps, generation length
     * @param GivenNameTrendsRepository $givenNameTrendsRepository Per-decade frequency of the top-N given names for the stream graph
     * @param MigrationRepository       $migrationRepository       Birth → death country flows for the Places-tab Sankey diagram
     * @param CountryRepository         $countryRepository         BIRT / DEAT counts aggregated to ISO-3166-1 alpha-2 country codes
     * @param LifeSpanRepository        $lifeSpanRepository        Age-at-death distribution + top-N oldest individuals + living-age-band buckets
     * @param MarriageRepository        $marriageRepository        Age-at-marriage / duration / couple-age-gap aggregations for the Family tab
     * @param DivorceRepository         $divorceRepository         Divorce century / month / age / cohort-rate aggregations for the Family tab
     * @param ChildrenRepository        $childrenRepository        Children-per-family + sibling-age-gap + top-N largest families aggregations
     * @param ChildMortalityRepository  $childMortalityRepository  Under-5 child mortality summary + per-birth-century breakdown
     * @param KinshipRepository         $kinshipRepository         Ancestor-count distribution + average pedigree-completeness (Lacy 1989)
     * @param OccupationRepository      $occupationRepository      Top-N occupations (`1 OCCU` facts) across the tree
     * @param ReligionRepository        $religionRepository        Top-N religions / confessions (`1 RELI` facts) across the tree
     * @param DeathCauseRepository      $deathCauseRepository      Top-N death causes (`2 CAUS` under `1 DEAT`) across the tree
     * @param PlaceDispersionRepository $placeDispersionRepository Distinct-PLAC-per-individual dispersion (Places tab)
     * @param GenerationDepthRepository $generationDepthRepository Max generation depth + descendants-distance distribution (Family tab brick-wall surfacing)
     * @param ParenthoodRepository      $parenthoodRepository      Age-at-first-child distribution per parent sex (Family tab)
     * @param EndogamyRepository        $endogamyRepository        Cousin-marriage / shared-ancestor rate within four generations (Family tab)
     * @param MarriageMatrixRepository  $marriageMatrixRepository  Surname × surname marriage matrix for the chord diagram (Names tab)
     */
    public function __construct(
        private StatisticsData $data,
        private FamilyRepository $familyRepository,
        private EventRepository $eventRepository,
        private NameRepository $nameRepository,
        private TreeHealthRepository $treeHealthRepository,
        private GivenNameTrendsRepository $givenNameTrendsRepository,
        private MigrationRepository $migrationRepository,
        private CountryRepository $countryRepository,
        private LifeSpanRepository $lifeSpanRepository,
        private MarriageRepository $marriageRepository,
        private DivorceRepository $divorceRepository,
        private ChildrenRepository $childrenRepository,
        private ChildMortalityRepository $childMortalityRepository,
        private KinshipRepository $kinshipRepository,
        private OccupationRepository $occupationRepository,
        private ReligionRepository $religionRepository,
        private DeathCauseRepository $deathCauseRepository,
        private PlaceDispersionRepository $placeDispersionRepository,
        private GenerationDepthRepository $generationDepthRepository,
        private ParenthoodRepository $parenthoodRepository,
        private EndogamyRepository $endogamyRepository,
        private MarriageMatrixRepository $marriageMatrixRepository,
    ) {
    }

    /**
     * Surname × surname marriage matrix for the chord-diagram widget
     * on the Names tab. Top-N surnames by marriage count; matrix is
     * symmetric so `[i][j] === [j][i]` for every off-diagonal cell.
     *
     * @param int $topN Cap on the number of arcs.
     *
     * @return array{labels: list<string>, matrix: list<list<int>>}
     */
    public function getSurnameMarriageMatrix(int $topN = 8): array
    {
        return $this->marriageMatrixRepository->surnameMarriageMatrix($topN);
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
     * Country grouping for births. Aggregated from the same
     * `places` + `placelinks` join chain core uses internally,
     * then re-confirmed against the raw GEDCOM so a person's
     * BIRT only counts where it actually happened.
     *
     * @return list<array{countryCode: string, label: string, count: int}>
     */
    public function getBirthsByCountry(): array
    {
        return $this->countryRepository->countByCountry('BIRT');
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
     * Country grouping for deaths. Same aggregation pipeline as
     * {@see getBirthsByCountry()}; the event tag is the only
     * difference.
     *
     * @return list<array{countryCode: string, label: string, count: int}>
     */
    public function getDeathsByCountry(): array
    {
        return $this->countryRepository->countByCountry('DEAT');
    }

    /**
     * Country grouping for residences. Each `1 RESI` occurrence on
     * an individual contributes once — a person with three recorded
     * residences (e.g. Germany, USA, France) registers in all three
     * countries.
     *
     * @return list<array{countryCode: string, label: string, count: int}>
     */
    public function getResidencesByCountry(): array
    {
        return $this->countryRepository->residencesByCountry();
    }

    /**
     * Age-at-death distribution bucketed into 10-year bands, ready
     * for the histogram-style ProgressList partial.
     *
     * @return array<string, int>
     */
    public function getAgeAtDeathDistribution(): array
    {
        return $this->lifeSpanRepository->ageAtDeathDistribution();
    }

    /**
     * Average lifespan grouped by birth-century × sex — feeds the
     * multi-series LineChart in the LifeSpan tab.
     *
     * @return array{
     *     categories: list<string>,
     *     series: list<array{name: string, values: list<float>, tooltips: list<string>, tooltipLabels: list<string>, class: string}>
     * }
     */
    public function getAverageLifespanBySexAndCentury(): array
    {
        return $this->lifeSpanRepository->averageLifespanBySexAndCentury();
    }

    /**
     * Births grouped by decade — the tree-growth indicator on the
     * TreeHealth tab. Leading / trailing zero-decades are trimmed;
     * inner zero-decades stay so historical gaps remain visible.
     * Keys are integer decade starts (e.g. 1900 for the 1900s);
     * the view layer formats them via `I18N::translate('%ss', $decade)`.
     *
     * @return array<int, int>
     */
    public function getBirthsByDecade(): array
    {
        return $this->lifeSpanRepository->birthsByDecade();
    }

    /**
     * Winter-peak indicator for deaths (Dec+Jan+Feb vs. baseline).
     * Returns null when fewer than 12 dated deaths are recorded.
     *
     * @return array{score: float, seasonCount: int, total: int}|null
     */
    public function getDeathWinterPeakScore(): ?array
    {
        return $this->lifeSpanRepository->deathWinterPeakScore();
    }

    /**
     * Distinct-PLAC dispersion across the tree. Average + sampled
     * count + distribution shaped for a side-by-side
     * Scalar + ProgressList visual on the Places tab.
     *
     * @return array{average: float, sampled: int, distribution: array<array-key, int>}
     */
    public function getPlaceDispersionSummary(): array
    {
        return $this->placeDispersionRepository->dispersionSummary();
    }

    /**
     * Tree-wide under-5 child mortality summary: count of individuals
     * with both BIRT + DEAT julian-days, count that died before age
     * five, and the percentage. Null when no BIRT+DEAT pair exists.
     *
     * @return array{total: int, died: int, rate: float}|null
     */
    public function getChildMortalitySummary(): ?array
    {
        return $this->childMortalityRepository->summary();
    }

    /**
     * Under-5 child mortality per birth century — list of
     * `{century, total, died, rate}` entries, ordered ascending, with
     * tiny cohorts (< 5 children) suppressed. View formats the
     * century label and tooltip prose via I18N.
     *
     * @return list<array{century: int, total: int, died: int, rate: float}>
     */
    public function getChildMortalityByBirthCentury(): array
    {
        return $this->childMortalityRepository->byBirthCentury();
    }

    /**
     * Generation-depth summary for the Family tab: tree-wide longest
     * vertical descent, per-individual `[depth → count]` histogram
     * across the entire parentage graph, a `capped` flag that
     * trips when the depth-cap guard fired, up to three concrete
     * chains (each a list of {@see Individual} objects, ordered
     * eldest-ancestor → leaf-descendant) that reach the tree-wide
     * maximum depth, and the total number of distinct chains so
     * the view can surface "+N more" when more than three exist.
     *
     * @return array{maxDepth: int, distribution: array<int, int>, capped: bool, chains: list<list<Individual>>, totalChainCount: int}
     */
    public function getGenerationDepthSummary(): array
    {
        return $this->generationDepthRepository->summary();
    }

    /**
     * Age-at-first-child distribution for parents of the given sex,
     * bucketed into 5-year bands.
     *
     * @param string $sex 'M' for fathers, 'F' for mothers
     *
     * @return array<string, int>
     */
    public function getAgeAtFirstChildDistribution(string $sex): array
    {
        return $this->parenthoodRepository->ageAtFirstChildDistribution($sex);
    }

    /**
     * Endogamy summary: testable-couple count, count sharing ≥1
     * common ancestor within the default depth, the resulting
     * percentage, and the depth used. Null when no testable couple
     * exists (a tree with no recorded parentage links anywhere).
     *
     * @return array{total: int, endogamous: int, rate: float, depth: int}|null
     */
    public function getEndogamySummary(): ?array
    {
        return $this->endogamyRepository->summary();
    }

    /**
     * Hall-of-fame style record holders bundled into a single
     * structure the view can render as a table. Each entry is
     * either null (record could not be established for this tree)
     * or carries the resolved Individual / Family object plus the
     * extreme value the record-holder reached.
     *
     * @return array{
     *     oldestDeceased:   array{individual: Individual, ageYears: int}|null,
     *     oldestLiving:     array{individual: Individual, ageYears: int}|null,
     *     longestMarriage:  array{family: Family, durationYears: int}|null,
     *     shortestMarriage: array{family: Family, durationDays: int}|null,
     *     youngestHusband:  array{individual: Individual, ageYears: int}|null,
     *     youngestWife:     array{individual: Individual, ageYears: int}|null,
     *     oldestHusband:    array{individual: Individual, ageYears: int}|null,
     *     oldestWife:       array{individual: Individual, ageYears: int}|null,
     *     mostSpouses:           array{individual: Individual, count: int}|null,
     *     largestFamily:         array{family: Family, count: int}|null,
     *     mostChildrenPerPerson: array{individual: Individual, count: int}|null,
     *     youngestFatherAtFirstChild: array{individual: Individual, ageYears: int}|null,
     *     youngestMotherAtFirstChild: array{individual: Individual, ageYears: int}|null,
     *     oldestFatherAtFirstChild:   array{individual: Individual, ageYears: int}|null,
     *     oldestMotherAtFirstChild:   array{individual: Individual, ageYears: int}|null
     * }
     */
    public function getTreeRecords(): array
    {
        return [
            'oldestDeceased'             => $this->lifeSpanRepository->oldestDeceasedRecord(),
            'oldestLiving'               => $this->lifeSpanRepository->oldestLivingRecord(),
            'longestMarriage'            => $this->marriageRepository->longestMarriageRecord(),
            'shortestMarriage'           => $this->marriageRepository->shortestMarriageRecord(),
            'youngestHusband'            => $this->marriageRepository->youngestSpouseAtMarriageRecord('M'),
            'youngestWife'               => $this->marriageRepository->youngestSpouseAtMarriageRecord('F'),
            'oldestHusband'              => $this->marriageRepository->oldestSpouseAtMarriageRecord('M'),
            'oldestWife'                 => $this->marriageRepository->oldestSpouseAtMarriageRecord('F'),
            'mostSpouses'                => $this->marriageRepository->mostSpousesRecord(),
            'largestFamily'              => $this->childrenRepository->largestFamilyRecord(),
            'mostChildrenPerPerson'      => $this->childrenRepository->mostChildrenPerPersonRecord(),
            'youngestFatherAtFirstChild' => $this->parenthoodRepository->youngestParentAtFirstChildRecord('M'),
            'youngestMotherAtFirstChild' => $this->parenthoodRepository->youngestParentAtFirstChildRecord('F'),
            'oldestFatherAtFirstChild'   => $this->parenthoodRepository->oldestParentAtFirstChildRecord('M'),
            'oldestMotherAtFirstChild'   => $this->parenthoodRepository->oldestParentAtFirstChildRecord('F'),
        ];
    }

    /**
     * Top-N oldest deceased individuals (label includes the age).
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return array<string, int>
     */
    public function getTopOldestDeceased(int $limit): array
    {
        return $this->lifeSpanRepository->topOldestDeceased($limit);
    }

    /**
     * Top-N oldest living individuals (label includes the age).
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return array<string, int>
     */
    public function getTopOldestLiving(int $limit): array
    {
        return $this->lifeSpanRepository->topOldestLiving($limit);
    }

    /**
     * Living-individual count grouped by life-stage age-band, ready
     * for the donut partial.
     *
     * @return list<array{label: string, value: int, class: string}>
     */
    public function getLivingByAgeBand(): array
    {
        return $this->lifeSpanRepository->livingByAgeBand();
    }

    /**
     * Age-at-marriage histogram for one sex (5-year bands + 60+
     * overflow).
     *
     * @param string $sex 'M' or 'F'
     *
     * @return array<string, int>
     */
    public function getAgeAtMarriageDistribution(string $sex): array
    {
        return $this->marriageRepository->ageAtMarriageDistribution($sex);
    }

    /**
     * Marriage-duration histogram (10-year bands up to 60+).
     *
     * @return array<string, int>
     */
    public function getMarriageDurationDistribution(): array
    {
        return $this->marriageRepository->durationDistribution();
    }

    /**
     * Couple age-gap histogram (symmetric 5-year bands centred on
     * zero). Negative buckets mean wife older than husband.
     *
     * @return array<string, int>
     */
    public function getCoupleAgeGapDistribution(): array
    {
        return $this->marriageRepository->ageGapDistribution();
    }

    /**
     * Weddings grouped by century.
     *
     * @return array<string, int>
     */
    public function getWeddingsByCentury(): array
    {
        return $this->marriageRepository->weddingsByCentury();
    }

    /**
     * Weddings grouped by month (first MARR per family). Keys are
     * the localised month names so the rendering matches the
     * existing births-by-month card.
     *
     * @return array<string, int>
     */
    public function getWeddingsByMonth(): array
    {
        return $this->translateMonthKeys($this->marriageRepository->weddingsByMonth());
    }

    /**
     * Divorces grouped by century.
     *
     * @return array<string, int>
     */
    public function getDivorcesByCentury(): array
    {
        return $this->divorceRepository->divorcesByCentury();
    }

    /**
     * Divorces grouped by localised month name.
     *
     * @return array<string, int>
     */
    public function getDivorcesByMonth(): array
    {
        return $this->translateMonthKeys($this->divorceRepository->divorcesByMonth());
    }

    /**
     * Age-at-divorce histogram for one sex (5-year bands, 80+
     * overflow).
     *
     * @param string $sex 'M' or 'F'
     *
     * @return array<string, int>
     */
    public function getAgeAtDivorceDistribution(string $sex): array
    {
        return $this->divorceRepository->ageAtDivorceDistribution($sex);
    }

    /**
     * Divorce rate per MARR-cohort (integer decade start → fraction 0.0-1.0).
     * Cohorts with fewer than 3 marriages are filtered out.
     *
     * @return array<int, float>
     */
    public function getDivorceRateByMarriageCohort(): array
    {
        return $this->divorceRepository->divorceRateByMarriageCohort();
    }

    /**
     * Divorces cross-tabulated by divorce century and age-band —
     * feeds the StackedBar widget on the Family tab.
     *
     * @return array{
     *     categories: list<string>,
     *     tooltipLabels: list<string>,
     *     series: list<array{name: string, data: list<int>, class: string}>
     * }
     */
    public function getDivorcesByCenturyAndAgeBand(): array
    {
        return $this->divorceRepository->divorcesByCenturyAndAgeBand();
    }

    /**
     * Average number of children per family across the whole tree.
     */
    public function getAverageChildrenPerFamily(): float
    {
        return $this->childrenRepository->averageChildrenPerFamily();
    }

    /**
     * Histogram of children-per-family (0–9 buckets + "10+" overflow).
     *
     * @return array<array-key, int>
     */
    public function getChildrenPerFamilyHistogram(): array
    {
        return $this->childrenRepository->childrenPerFamilyHistogram();
    }

    /**
     * Distribution of gaps (years) between consecutive siblings.
     *
     * @return array<string, int>
     */
    public function getSiblingAgeGapDistribution(): array
    {
        return $this->childrenRepository->siblingAgeGapDistribution();
    }

    /**
     * Family-size composition pivoted into a StackedBar payload —
     * one bar per decade (1900s, 1910s, …), segments stack
     * 1/2/3/4+ children.
     *
     * @return array{
     *     categories: list<string>,
     *     tooltipLabels: list<string>,
     *     series: list<array{name: string, data: list<int>, class: string}>
     * }
     */
    public function getFamilySizeStackedByDecade(): array
    {
        return $this->childrenRepository->familySizeStackedByDecade();
    }

    /**
     * Average children per family by century — single LineChart
     * series tracking the central tendency over time.
     *
     * @return array{
     *     categories: list<string>,
     *     series: list<array{name: string, values: list<float>, tooltips: list<string>, tooltipLabels: list<string>}>
     * }
     */
    public function getAverageFamilySizeByCentury(): array
    {
        return $this->childrenRepository->averageFamilySizeByCentury();
    }

    /**
     * Top-N largest families by child count.
     *
     * @param int $limit Maximum number of rows.
     *
     * @return array<string, int>
     */
    public function getTopLargestFamilies(int $limit): array
    {
        return $this->childrenRepository->topLargestFamilies($limit);
    }

    /**
     * Childless-families breakdown for a donut chart.
     *
     * @return list<array{label: string, value: int, class: string}>
     */
    public function getChildlessFamiliesBreakdown(): array
    {
        return $this->childrenRepository->childlessFamiliesBreakdown();
    }

    /**
     * First-children by localised month name.
     *
     * @return array<string, int>
     */
    public function getFirstChildrenByMonth(): array
    {
        return $this->translateMonthKeys($this->childrenRepository->firstChildrenByMonth());
    }

    /**
     * Histogram of known-ancestor counts per individual (4-generation
     * walk, 3-wide buckets).
     *
     * @return array<string, int>
     */
    public function getAncestorCountDistribution(): array
    {
        return $this->kinshipRepository->ancestorCountDistribution();
    }

    /**
     * Mean pedigree-completeness index across every individual
     * (Lacy 1989, 4-generation depth). Fraction 0.0-1.0.
     */
    public function getAveragePedigreeCompleteness(): float
    {
        return $this->kinshipRepository->averagePedigreeCompleteness();
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
     * Per-decade frequencies of the top-N given names, ready for the
     * stream-graph renderer. Each band sums to the individual's count
     * across the entire decade.
     *
     * @param int $topN Maximum number of distinct given names to keep
     *
     * @return array{decades: list<int>, names: list<string>, series: array<string, array<int, int>>}
     */
    public function getGivenNameTrends(int $topN): array
    {
        return $this->givenNameTrendsRepository->countByDecade($topN);
    }

    /**
     * Top-N occupations across the tree (`1 OCCU` facts on individuals),
     * case-folded so spelling variants merge into one bucket. Display
     * labels carry the first-seen original casing.
     *
     * @param int $limit Maximum number of occupations to surface
     *
     * @return array<string, int>
     */
    public function getTopOccupations(int $limit): array
    {
        return $this->occupationRepository->topOccupations($limit);
    }

    /**
     * Number of distinct occupations (case-folded) recorded across the tree.
     */
    public function getTotalOccupations(): int
    {
        return $this->occupationRepository->countDistinctOccupations();
    }

    /**
     * Top-N religions / confessions across the tree (`1 RELI` facts on
     * individuals), case-folded so spelling variants merge into one
     * bucket.
     *
     * @param int $limit Maximum number of religions to surface
     *
     * @return array<string, int>
     */
    public function getTopReligions(int $limit): array
    {
        return $this->religionRepository->topReligions($limit);
    }

    /**
     * Number of distinct religions (case-folded) recorded across the tree.
     */
    public function getTotalReligions(): int
    {
        return $this->religionRepository->countDistinctReligions();
    }

    /**
     * Top-N death causes across the tree (`2 CAUS` sub-facts under the
     * `1 DEAT` block), case-folded so spelling variants merge into one
     * bucket.
     *
     * @param int $limit Maximum number of causes to surface
     *
     * @return array<string, int>
     */
    public function getTopDeathCauses(int $limit): array
    {
        return $this->deathCauseRepository->topDeathCauses($limit);
    }

    /**
     * Number of distinct death causes (case-folded) recorded across the tree.
     */
    public function getTotalDeathCauses(): int
    {
        return $this->deathCauseRepository->countDistinctDeathCauses();
    }

    /**
     * Birth → death country migration flows ready for the Places-tab
     * Sankey diagram. Same-country trajectories are dropped (no
     * movement); only the top-N weighted links are returned.
     *
     * @param int $topLinks Maximum number of distinct flows to retain
     *
     * @return array{
     *     nodes: list<array{name: string}>,
     *     links: list<array{source: int, target: int, value: int, samples: list<array{name: string, xref: string}>}>
     * }
     */
    public function getMigrationFlows(int $topLinks): array
    {
        return $this->migrationRepository->flowsByCountry($topLinks);
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
