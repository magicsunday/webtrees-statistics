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
use MagicSunday\Webtrees\Statistic\Enum\MaritalBucket;
use MagicSunday\Webtrees\Statistic\Enum\Sex;
use MagicSunday\Webtrees\Statistic\Model\Chord\ChordMatrixPayload;
use MagicSunday\Webtrees\Statistic\Model\Heatmap\HeatmapPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\Metric\ChildMortalitySummary;
use MagicSunday\Webtrees\Statistic\Model\Metric\EndogamyRate;
use MagicSunday\Webtrees\Statistic\Model\Metric\PlaceDispersionSummary;
use MagicSunday\Webtrees\Statistic\Model\Metric\RateCount;
use MagicSunday\Webtrees\Statistic\Model\Metric\WinterPeakScore;
use MagicSunday\Webtrees\Statistic\Model\Pyramid\PopulationPyramidPayload;
use MagicSunday\Webtrees\Statistic\Model\Ranking\RankingEntry;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyFlowsPayload;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarPayload;
use MagicSunday\Webtrees\Statistic\Model\StreamGraph\GivenNameTrendsPayload;
use MagicSunday\Webtrees\Statistic\Model\Tree\GenerationDepthReport;
use MagicSunday\Webtrees\Statistic\Model\Tree\HeroStats;
use MagicSunday\Webtrees\Statistic\Model\Tree\TreeRecordsReport;
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
use MagicSunday\Webtrees\Statistic\Repository\OccupationInheritanceRepository;
use MagicSunday\Webtrees\Statistic\Repository\OccupationRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParenthoodRepository;
use MagicSunday\Webtrees\Statistic\Repository\PlaceDispersionRepository;
use MagicSunday\Webtrees\Statistic\Repository\ReligionRepository;
use MagicSunday\Webtrees\Statistic\Repository\TreeHealthRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\HistogramTrim;
use MagicSunday\Webtrees\Statistic\Support\Locale\MonthName;
use MagicSunday\Webtrees\Statistic\Support\Locale\ZodiacLabels;

use function array_values;
use function count;
use function is_array;

/**
 * Aggregator service that backs the statistics-chart tab partials.
 *
 * Delegates every count that the core `StatisticsData` accessor exposes
 * directly to it; uses local repositories only for the things core does not
 * provide (marital-status classification by Census semantics, zodiac grouping,
 * primary-name distinct counts).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class Statistic
{
    private const int NAME_FREQUENCY_THRESHOLD = 1;

    /**
     * @param StatisticsData                  $data                            Core data accessor for individual, family and event counts
     * @param FamilyRepository                $familyRepository                Census-style marital classification not exposed by core
     * @param EventRepository                 $eventRepository                 Zodiac-sign grouping not exposed by core
     * @param NameRepository                  $nameRepository                  Top-N surnames / given names + distinct counts, restricted to whitelisted GEDCOM name forms
     * @param TreeHealthRepository            $treeHealthRepository            Data-quality metrics: source coverage, missing-event gaps, generation length
     * @param GivenNameTrendsRepository       $givenNameTrendsRepository       Per-decade frequency of the top-N given names for the stream graph
     * @param MigrationRepository             $migrationRepository             Birth → death country flows for the Places-tab Sankey diagram
     * @param CountryRepository               $countryRepository               BIRT / DEAT counts aggregated to ISO-3166-1 alpha-2 country codes
     * @param LifeSpanRepository              $lifeSpanRepository              Age-at-death distribution + top-N oldest individuals + living-age-band buckets
     * @param MarriageRepository              $marriageRepository              Age-at-marriage / duration / couple-age-gap aggregations for the Family tab
     * @param DivorceRepository               $divorceRepository               Divorce century / month / age / cohort-rate aggregations for the Family tab
     * @param ChildrenRepository              $childrenRepository              Children-per-family + sibling-age-gap + top-N largest families aggregations
     * @param ChildMortalityRepository        $childMortalityRepository        Under-5 child mortality summary + per-birth-century breakdown
     * @param KinshipRepository               $kinshipRepository               Ancestor-count distribution + average pedigree-completeness (Lacy 1989)
     * @param OccupationRepository            $occupationRepository            Top-N occupations (`1 OCCU` facts) across the tree
     * @param OccupationInheritanceRepository $occupationInheritanceRepository Father → son occupation flows for the Overview-tab Sankey diagram
     * @param ReligionRepository              $religionRepository              Top-N religions / confessions (`1 RELI` facts) across the tree
     * @param DeathCauseRepository            $deathCauseRepository            Top-N death causes (`2 CAUS` under `1 DEAT`) across the tree
     * @param PlaceDispersionRepository       $placeDispersionRepository       Distinct-PLAC-per-individual dispersion (Places tab)
     * @param GenerationDepthRepository       $generationDepthRepository       Max generation depth + descendants-distance distribution (Family tab brick-wall surfacing)
     * @param ParenthoodRepository            $parenthoodRepository            Age-at-first-child distribution per parent sex (Family tab)
     * @param EndogamyRepository              $endogamyRepository              Cousin-marriage / shared-ancestor rate within four generations (Family tab)
     * @param MarriageMatrixRepository        $marriageMatrixRepository        Surname × surname marriage matrix for the chord diagram (Names tab)
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
        private OccupationInheritanceRepository $occupationInheritanceRepository,
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
     * Surname × surname marriage matrix for the chord-diagram widget on the
     * Names tab. Top-N surnames by marriage count; matrix is symmetric so
     * `[i][j] === [j][i]` for every off-diagonal cell.
     *
     * @param int $topN Cap on the number of arcs.
     */
    public function getSurnameMarriageMatrix(int $topN = 8): ChordMatrixPayload
    {
        return $this->marriageMatrixRepository->surnameMarriageMatrix($topN);
    }

    /**
     * Same-sex given-name passdown rate per child's birth century, two series
     * on the same X axis: father → son and mother → daughter. Each series shows
     * the share of children whose given names overlap with the same-sex
     * parent's by at least one token, expressed as a 0..100 percentage.
     */
    public function getSameSexNamePassdownByCentury(): LineChartPayload
    {
        return $this->nameRepository->sameSexNamePassdownByCentury();
    }

    /**
     * Total individuals in the tree (every status, including the unknown-sex
     * and the deceased).
     */
    public function getTotalIndividuals(): int
    {
        return $this->data->countIndividuals();
    }

    /**
     * Facade aggregator that returns one DTO carrying every headline the hero
     * template renders, so the view stays bound to a single facade call instead
     * of six.
     */
    public function getHeroStats(): HeroStats
    {
        $sources  = $this->getSourceCitationCoverage();
        $coverage = ($sources->total > 0) ? ($sources->value / $sources->total) : 0.0;

        // Year range comes from the birth-decade aggregator; keys are
        // decade integers (1490, 1500, …). Decade-from / decade-to feed
        // the hero eyebrow as a "1490s – 2020s" tag, and the rounded
        // delta drives the spelled-out "over X centuries" deck copy.
        $decades     = $this->getBirthsByDecade();
        $decadeKeys  = array_keys($decades);
        $decadeFrom  = ($decadeKeys !== []) ? min($decadeKeys) : null;
        $decadeTo    = ($decadeKeys !== []) ? max($decadeKeys) : null;
        $centurySpan = ($decadeFrom !== null && $decadeTo !== null)
            ? max(1, (int) ceil(($decadeTo + 9 - $decadeFrom) / 100))
            : 0;

        return new HeroStats(
            individuals: $this->getTotalIndividuals(),
            families: $this->data->countFamilies(),
            maxGenerationDepth: $this->getGenerationDepthSummary()->maxDepth,
            averageGenerationYears: $this->getAverageGenerationLength(),
            pedigreeCompleteness: $this->getAveragePedigreeCompleteness(),
            sourceCitationCoverage: $coverage,
            centurySpan: $centurySpan,
            decadeFrom: $decadeFrom,
            decadeTo: $decadeTo,
        );
    }

    /**
     * @return array<int, array{label: string, value: int, class: string}>
     */
    public function getTotalIndividualsData(): array
    {
        return [
            [
                'label' => I18N::translate('Male'),
                'value' => $this->data->countIndividualsBySex('M'),
                'class' => 'male',
            ],
            [
                'label' => I18N::translate('Female'),
                'value' => $this->data->countIndividualsBySex('F'),
                'class' => 'female',
            ],
            [
                'label' => I18N::translate('Unknown'),
                'value' => $this->data->countIndividualsBySex('U'),
                'class' => 'unknown',
            ],
        ];
    }

    /**
     * @return array<int, array{label: string, value: int, class: string}>
     */
    public function getTotalLivingDeceasedData(): array
    {
        return [
            [
                'label' => I18N::translate('Living'),
                'value' => $this->data->countIndividualsLiving(),
                'class' => 'living',
            ],
            [
                'label' => I18N::translate('Deceased'),
                'value' => $this->data->countIndividualsDeceased(),
                'class' => 'deceased',
            ],
        ];
    }

    /**
     * Marital-status breakdown for the donut chart. Each living individual is
     * classified into exactly one bucket by the family repository using
     * precedence current > divorced > widowed > single, so the four slices sum
     * to the living-individual total without clamping.
     *
     * @return array<int, array{label: string, value: int, class: string}>
     */
    public function getFamilyStatusData(): array
    {
        $buckets = $this->familyRepository->classifyLivingIndividuals();

        return [
            [
                'label' => I18N::translate('Married'),
                'value' => $buckets[MaritalBucket::Current->value],
                'class' => 'married',
            ],
            [
                'label' => I18N::translate('Single'),
                'value' => $buckets[MaritalBucket::Single->value],
                'class' => 'single',
            ],
            [
                'label' => I18N::translate('Widowed'),
                'value' => $buckets[MaritalBucket::Widowed->value],
                'class' => 'widowed',
            ],
            [
                'label' => I18N::translate('Divorced'),
                'value' => $buckets[MaritalBucket::Divorced->value],
                'class' => 'divorced',
            ],
        ];
    }

    /**
     * Count of distinct primary surnames in the tree, computed from the same
     * aggregation that feeds the Top-N tag cloud.
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
        return $this->nameRepository->topSurnames($limit, self::NAME_FREQUENCY_THRESHOLD);
    }

    /**
     * Count of distinct primary given names recorded on male individuals.
     */
    public function getTotalMaleGivenNames(): int
    {
        return $this->nameRepository->countDistinctGivenNames(Sex::Male->value, self::NAME_FREQUENCY_THRESHOLD);
    }

    /**
     * @param int $limit Maximum number of given names to return
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function getTopMaleGivenNames(int $limit): array
    {
        return $this->nameRepository->topGivenNames(Sex::Male->value, self::NAME_FREQUENCY_THRESHOLD, $limit);
    }

    /**
     * Count of distinct primary given names recorded on female individuals.
     */
    public function getTotalFemaleGivenNames(): int
    {
        return $this->nameRepository->countDistinctGivenNames(Sex::Female->value, self::NAME_FREQUENCY_THRESHOLD);
    }

    /**
     * @param int $limit Maximum number of given names to return
     *
     * @return array<int, array{label: string, value: int}>
     */
    public function getTopFemaleGivenNames(int $limit): array
    {
        return $this->nameRepository->topGivenNames(Sex::Female->value, self::NAME_FREQUENCY_THRESHOLD, $limit);
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
     * Births grouped by zodiac sign, keyed by the locale-translated sign label
     * (Aries / Bélier / Widder etc.) — the view renders the `{label => count}`
     * map directly. Callers needing the canonical English keys go through {@see
     * EventRepository::getBirthsByZodiacSign()} instead.
     *
     * @return array<string, int>
     */
    public function getBirthsByZodiacSign(): array
    {
        return ZodiacLabels::translateKeys($this->eventRepository->getBirthsByZodiacSign());
    }

    /**
     * Country grouping for births. Aggregated from the same `places` +
     * `placelinks` join chain core uses internally, then re-confirmed against
     * the raw GEDCOM so a person's BIRT only counts where it actually happened.
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
     * Country grouping for deaths. Same aggregation pipeline as {@see
     * getBirthsByCountry()}; the event tag is the only difference.
     *
     * @return list<array{countryCode: string, label: string, count: int}>
     */
    public function getDeathsByCountry(): array
    {
        return $this->countryRepository->countByCountry('DEAT');
    }

    /**
     * Country grouping for residences. Each `1 RESI` occurrence on an
     * individual contributes once — a person with three recorded residences
     * (e.g. Germany, USA, France) registers in all three countries.
     *
     * @return list<array{countryCode: string, label: string, count: int}>
     */
    public function getResidencesByCountry(): array
    {
        return $this->countryRepository->residencesByCountry();
    }

    /**
     * Age-at-death distribution bucketed into 10-year bands, ready for the
     * histogram-style ProgressList partial.
     *
     * @return array<string, int>
     */
    public function getAgeAtDeathDistribution(): array
    {
        return $this->lifeSpanRepository->ageAtDeathDistribution();
    }

    /**
     * Average lifespan grouped by birth-century × sex — feeds the multi-series
     * LineChart in the LifeSpan tab.
     */
    public function getAverageLifespanBySexAndCentury(): LineChartPayload
    {
        return $this->lifeSpanRepository->averageLifespanBySexAndCentury();
    }

    /**
     * Survival curve per birth century — for each cohort the share of
     * individuals still alive at age 0, 10, 20, …, 100. Feeds the multi-series
     * LineChart in the LifeSpan tab.
     */
    public function getSurvivalCurveByCentury(): LineChartPayload
    {
        return $this->lifeSpanRepository->survivalFunctionByCentury();
    }

    /**
     * Births grouped by decade — the tree-growth indicator on the TreeHealth
     * tab. Leading / trailing zero-decades are trimmed; inner zero-decades stay
     * so historical gaps remain visible. Keys are integer decade starts (e.g.
     * 1900 for the 1900s); the view layer formats them via
     * `I18N::translate('%ss', $decade)`.
     *
     * @return array<int, int>
     */
    public function getBirthsByDecade(): array
    {
        return $this->lifeSpanRepository->birthsByDecade();
    }

    /**
     * Running cumulative population by decade — for each decade in the visible
     * birth window the total number of individuals born up to and including
     * that decade. Layers a running sum on top of {@see getBirthsByDecade()}.
     *
     * @return array<int, int>
     */
    public function getCumulativeBirthsByDecade(): array
    {
        return $this->lifeSpanRepository->cumulativeBirthsByDecade();
    }

    /**
     * Raw age-at-death samples grouped by birth century. Each row carries the
     * year-precision integer ages for one cohort plus the sample size;
     * downstream consumers (chart-lib BoxPlot) compute quartiles and whiskers
     * themselves.
     *
     * @return list<array{century: int, values: list<int>, n: int}>
     */
    public function getDeathAgeDistributionByCentury(): array
    {
        return $this->lifeSpanRepository->deathAgeDistributionByCentury();
    }

    /**
     * Age-at-death distribution split by sex and faceted by birth century —
     * feeds the population-pyramid widget on the LifeSpan tab. Same cohort
     * definition as {@see getDeathAgeDistributionByCentury()}, binned into
     * 10-year age bands per sex.
     */
    public function getDeathsByCenturyAgeBandSex(): PopulationPyramidPayload
    {
        return $this->lifeSpanRepository->deathsByCenturyAgeBandSex();
    }

    /**
     * Births faceted by 25-year period × calendar month — feeds the births
     * heatmap on the LifeSpan tab. Each BIRT date contributes one tick to its
     * period row and month column. Leading / trailing empty period rows are
     * trimmed; inner empty rows stay so a gap in the recorded history remains a
     * visible blank band.
     */
    public function getBirthHeatmapByPeriodMonth(): HeatmapPayload
    {
        return $this->lifeSpanRepository->eventHeatmapByPeriodMonth('BIRT');
    }

    /**
     * Deaths faceted by 25-year period × calendar month — feeds the deaths
     * heatmap on the LifeSpan tab. Mirrors {@see getBirthHeatmapByPeriodMonth()}
     * over DEAT dates.
     */
    public function getDeathHeatmapByPeriodMonth(): HeatmapPayload
    {
        return $this->lifeSpanRepository->eventHeatmapByPeriodMonth('DEAT');
    }

    /**
     * Winter-peak indicator for deaths (Dec+Jan+Feb vs. baseline). Returns null
     * when fewer than 12 dated deaths are recorded.
     */
    public function getDeathWinterPeakScore(): ?WinterPeakScore
    {
        return $this->lifeSpanRepository->deathWinterPeakScore();
    }

    /**
     * Distinct-PLAC dispersion across the tree. Average + sampled count +
     * distribution shaped for a side-by-side Scalar + ProgressList visual on
     * the Places tab.
     */
    public function getPlaceDispersionSummary(): PlaceDispersionSummary
    {
        return $this->placeDispersionRepository->dispersionSummary();
    }

    /**
     * Tree-wide under-5 child mortality summary: count of individuals with both
     * BIRT + DEAT julian-days, count that died before age five, and the
     * percentage. Null when no BIRT+DEAT pair exists.
     */
    public function getChildMortalitySummary(): ?ChildMortalitySummary
    {
        return $this->childMortalityRepository->summary();
    }

    /**
     * Under-5 child mortality per birth century — list of `{century, total,
     * died, rate}` entries, ordered ascending, with tiny cohorts (< 5 children)
     * suppressed. View formats the century label and tooltip prose via I18N.
     *
     * @return list<array{century: int, total: int, died: int, rate: float}>
     */
    public function getChildMortalityByBirthCentury(): array
    {
        return $this->childMortalityRepository->byBirthCentury();
    }

    /**
     * Generation-depth summary for the Family tab: tree-wide longest vertical
     * descent, per-individual `[depth → count]` histogram across the entire
     * parentage graph, a `capped` flag that trips when the depth-cap guard
     * fired, up to three concrete chains (each a list of {@see
     * \Fisharebest\Webtrees\Individual} objects, ordered eldest-ancestor →
     * leaf-descendant) that reach the tree-wide maximum depth, and the total
     * number of distinct chains so the view can surface "+N more" when more
     * than three exist.
     */
    public function getGenerationDepthSummary(): GenerationDepthReport
    {
        return $this->generationDepthRepository->summary();
    }

    /**
     * Top-N ancestors ranked by total transitive descendant count. Surfaces the
     * structural roots of the tree — the individuals whose branches actually
     * carry the rest of the recorded lineage. Each entry carries the XREF,
     * display label and count so the Podium component renders it directly
     * without collapsing same-named ancestors.
     *
     * @param int $limit Maximum number of rows to return
     *
     * @return list<RankingEntry>
     */
    public function getTopAncestorsByDescendantCount(int $limit): array
    {
        return $this->generationDepthRepository->topAncestorsByDescendantCount($limit);
    }

    /**
     * Co-trimmed `{father, mother}` age-at-first-child distributions — both
     * 5-year-band maps with leading and trailing all-zero buckets dropped
     * symmetrically. Index 0 is fathers, index 1 is mothers.
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    public function getTrimmedAgeAtFirstChildDistributions(): array
    {
        return HistogramTrim::dropCoZeroEnds(
            $this->parenthoodRepository->ageAtFirstChildDistribution('M'),
            $this->parenthoodRepository->ageAtFirstChildDistribution('F'),
        );
    }

    /**
     * Per-decade trend of the mean parental age at first child, with one series
     * each for fathers and mothers. Lets the family-tab reader see the
     * historical drift — the secular rise in parental age across the 20th
     * century in particular — that the aggregate 5-year-band histogram hides.
     */
    public function getAgeAtFirstChildMeanByDecade(): LineChartPayload
    {
        return $this->parenthoodRepository->ageAtFirstChildMeanByDecade();
    }

    /**
     * Endogamy summary: testable-couple count, count sharing ≥1 common ancestor
     * within the default depth, the resulting percentage, and the depth used.
     * Null when no testable couple exists (a tree with no recorded parentage
     * links anywhere).
     */
    public function getEndogamySummary(): ?EndogamyRate
    {
        return $this->endogamyRepository->summary();
    }

    /**
     * Hall-of-fame style record holders bundled into a typed report the view
     * can render as a table. Each property is independently nullable — a fresh
     * tree without enough data may yield zero, some, or all slots; the view
     * renders each row only when its slot is populated.
     */
    public function getTreeRecords(): TreeRecordsReport
    {
        return new TreeRecordsReport(
            oldestDeceased: $this->lifeSpanRepository->oldestDeceasedRecord(),
            oldestLiving: $this->lifeSpanRepository->oldestLivingRecord(),
            longestMarriage: $this->marriageRepository->longestMarriageRecord(),
            shortestMarriage: $this->marriageRepository->shortestMarriageRecord(),
            youngestHusband: $this->marriageRepository->youngestSpouseAtMarriageRecord('M'),
            youngestWife: $this->marriageRepository->youngestSpouseAtMarriageRecord('F'),
            oldestHusband: $this->marriageRepository->oldestSpouseAtMarriageRecord('M'),
            oldestWife: $this->marriageRepository->oldestSpouseAtMarriageRecord('F'),
            mostSpouses: $this->marriageRepository->mostSpousesRecord(),
            largestFamily: $this->childrenRepository->largestFamilyRecord(),
            mostChildrenPerPerson: $this->childrenRepository->mostChildrenPerPersonRecord(),
            youngestFatherAtFirstChild: $this->parenthoodRepository->youngestParentAtFirstChildRecord('M'),
            youngestMotherAtFirstChild: $this->parenthoodRepository->youngestParentAtFirstChildRecord('F'),
            oldestFatherAtFirstChild: $this->parenthoodRepository->oldestParentAtFirstChildRecord('M'),
            oldestMotherAtFirstChild: $this->parenthoodRepository->oldestParentAtFirstChildRecord('F'),
        );
    }

    /**
     * Top-N oldest deceased individuals, each carrying the XREF, display name
     * and age in years.
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return list<RankingEntry>
     */
    public function getTopOldestDeceased(int $limit): array
    {
        return $this->lifeSpanRepository->topOldestDeceased($limit);
    }

    /**
     * Top-N oldest living individuals, each carrying the XREF, display name and
     * age in years.
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return list<RankingEntry>
     */
    public function getTopOldestLiving(int $limit): array
    {
        return $this->lifeSpanRepository->topOldestLiving($limit);
    }

    /**
     * Living-individual count grouped by life-stage age-band, ready for the
     * donut partial.
     *
     * @return list<array{label: string, value: int, class: string}>
     */
    public function getLivingByAgeBand(): array
    {
        return $this->lifeSpanRepository->livingByAgeBand();
    }

    /**
     * Co-trimmed `{husband, wife}` age-at-marriage distributions — both
     * 5-year-band maps (incl. 60+ overflow) with leading and trailing all-zero
     * buckets dropped symmetrically. Index 0 is husbands, index 1 is wives.
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    public function getTrimmedAgeAtMarriageDistributions(): array
    {
        return HistogramTrim::dropCoZeroEnds(
            $this->marriageRepository->ageAtMarriageDistribution('M'),
            $this->marriageRepository->ageAtMarriageDistribution('F'),
        );
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
     * Widowhood / widower-interval histogram (5-year bands up to 50+) — for
     * FAMs where both spouses carry a recorded DEAT, the number of years the
     * survivor outlived the first-deceased partner.
     *
     * @return array<string, int>
     */
    public function getWidowhoodYearsDistribution(): array
    {
        return $this->marriageRepository->widowhoodYearsDistribution();
    }

    /**
     * Couple age-gap histogram as a two-sided bucket: each shared magnitude band
     * carries the count of couples where the husband is the older partner
     * (`left`) and where the wife is (`right`).
     *
     * @return array<string, array{left: int, right: int}>
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
     * Weddings grouped by month (first MARR per family). Keys are the localised
     * month names so the rendering matches the existing births-by-month card.
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
     * Co-trimmed `{husband, wife}` age-at-divorce distributions — both
     * 5-year-band maps (incl. 80+ overflow) with leading and trailing all-zero
     * buckets dropped symmetrically. Index 0 is husbands, index 1 is wives.
     *
     * @return array{0: array<string, int>, 1: array<string, int>}
     */
    public function getTrimmedAgeAtDivorceDistributions(): array
    {
        return HistogramTrim::dropCoZeroEnds(
            $this->divorceRepository->ageAtDivorceDistribution('M'),
            $this->divorceRepository->ageAtDivorceDistribution('F'),
        );
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
     * Divorces cross-tabulated by divorce century and age-band — feeds the
     * StackedBar widget on the Family tab.
     */
    public function getDivorcesByCenturyAndAgeBand(): StackedBarPayload
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
    public function getChildrenPerFamilyDistribution(): array
    {
        return $this->childrenRepository->childrenPerFamilyDistribution();
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
     * Family-size composition pivoted into a StackedBar payload — one bar per
     * decade (1900s, 1910s, …), segments stack 1/2/3/4+ children.
     */
    public function getFamilySizeStackedByDecade(): StackedBarPayload
    {
        return $this->childrenRepository->familySizeStackedByDecade();
    }

    /**
     * Average children per family by century — single LineChart series tracking
     * the central tendency over time.
     */
    public function getAverageFamilySizeByCentury(): LineChartPayload
    {
        return $this->childrenRepository->averageFamilySizeByCentury();
    }

    /**
     * Multiple-birth rate per century — one LineChart series per multiplicity
     * that actually occurs in the tree (twins, triplets, quadruplets,
     * quintuplets and above). Each series carries that multiplicity's
     * per-century share of dated births. Detection groups same-FAM siblings
     * whose BIRT dates sit within one day of each other, so cross-midnight
     * twins count without depending on an explicit INDI:ASSO link.
     */
    public function getMultipleBirthRateByCentury(): LineChartPayload
    {
        return $this->childrenRepository->multipleBirthRateByCentury();
    }

    /**
     * Top-N largest families by child count, each carrying the family XREF,
     * display label and child count.
     *
     * @param int $limit Maximum number of rows.
     *
     * @return list<RankingEntry>
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
    public function getChildlessFamiliesDistribution(): array
    {
        return $this->childrenRepository->childlessFamiliesDistribution();
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
     * Histogram of known-ancestor counts per individual (4-generation walk,
     * 3-wide buckets).
     *
     * @return array<string, int>
     */
    public function getAncestorCountDistribution(): array
    {
        return $this->kinshipRepository->ancestorCountDistribution();
    }

    /**
     * Mean pedigree-completeness index across every individual (Lacy 1989,
     * 4-generation depth). Fraction 0.0-1.0.
     */
    public function getAveragePedigreeCompleteness(): float
    {
        return $this->kinshipRepository->averagePedigreeCompleteness();
    }

    /**
     * Source-citation coverage as `{value, total}`, ready for the ProgressList
     * partial to derive the percentage and absolute counts.
     */
    public function getSourceCitationCoverage(): RateCount
    {
        return $this->treeHealthRepository->sourceCitationCoverage();
    }

    /**
     * Source-citation coverage broken down by birth century — the per-century
     * companion to {@see getSourceCitationCoverage()}. Surfaces which
     * historical eras carry their share of source-backed documentation and
     * which rely on family lore. Centuries below the repository's
     * minimum-sample threshold are dropped from the breakdown to keep the bar
     * from spiking on a single sourced ancestor; BCE birth years are excluded
     * entirely.
     *
     * @return list<array{century: int, total: int, sourced: int, percentage: float}>
     */
    public function getSourceCitationCoverageByCentury(): array
    {
        return $this->treeHealthRepository->sourceCitationCoverageByCentury();
    }

    /**
     * Missing-event gap rates for BIRT / DEAT, each split into "event missing"
     * and "place missing" rows. Returned as `{event, kind, value, total}`
     * tuples so the consumer can render its own label (keeping translations
     * next to their consuming markup).
     *
     * @return array<int, array{event: string, kind: string, value: int, total: int}>
     */
    public function getMissingEventGaps(): array
    {
        return array_values($this->treeHealthRepository->missingEventGaps());
    }

    /**
     * Average years between a parent's birth and a child's birth across every
     * parent-child pair where both ends carry a parseable BIRT date. Returns
     * null when the tree has no usable pair.
     */
    public function getAverageGenerationLength(): ?float
    {
        return $this->treeHealthRepository->averageGenerationLength();
    }

    /**
     * Per-decade frequencies of the top-N given names, ready for the
     * stream-graph renderer. Each band sums to the individual's count across
     * the entire decade.
     *
     * @param int $topN Maximum number of distinct given names to keep
     */
    public function getGivenNameTrends(int $topN): GivenNameTrendsPayload
    {
        return $this->givenNameTrendsRepository->countByDecade($topN);
    }

    /**
     * Top-N occupations across the tree (`1 OCCU` facts on individuals),
     * case-folded so spelling variants merge into one bucket. Display labels
     * carry the first-seen original casing.
     *
     * @param int $limit Maximum number of occupations to surface
     *
     * @return array<string, int>
     */
    public function getTopOccupations(int $limit): array
    {
        return $this->occupationRepository->top($limit);
    }

    /**
     * Number of distinct occupations (case-folded) recorded across the tree.
     */
    public function getTotalOccupations(): int
    {
        return $this->occupationRepository->countDistinct();
    }

    /**
     * Top-N religions / confessions across the tree (`1 RELI` facts on
     * individuals), case-folded so spelling variants merge into one bucket.
     *
     * @param int $limit Maximum number of religions to surface
     *
     * @return array<string, int>
     */
    public function getTopReligions(int $limit): array
    {
        return $this->religionRepository->top($limit);
    }

    /**
     * Number of distinct religions (case-folded) recorded across the tree.
     */
    public function getTotalReligions(): int
    {
        return $this->religionRepository->countDistinct();
    }

    /**
     * Top-N death causes across the tree (`2 CAUS` sub-facts under the `1 DEAT`
     * block), case-folded so spelling variants merge into one bucket.
     *
     * @param int $limit Maximum number of causes to surface
     *
     * @return array<string, int>
     */
    public function getTopDeathCauses(int $limit): array
    {
        return $this->deathCauseRepository->top($limit);
    }

    /**
     * Number of distinct death causes (case-folded) recorded across the tree.
     */
    public function getTotalDeathCauses(): int
    {
        return $this->deathCauseRepository->countDistinct();
    }

    /**
     * Birth → death country migration flows ready for the Places-tab Sankey
     * diagram. Same-country trajectories are dropped (no movement); only the
     * top-N weighted links are returned.
     *
     * @param int $topLinks Maximum number of distinct flows to retain
     */
    public function getMigrationFlows(int $topLinks): SankeyFlowsPayload
    {
        return $this->migrationRepository->flowsByCountry($topLinks);
    }

    /**
     * Father → son occupation inheritance flows ready for the Overview-tab
     * Sankey diagram. Each link runs from a father's occupation to a son's;
     * only sons whose father also has a recorded occupation contribute, and
     * only the top-N weighted links are returned.
     *
     * @param int $topLinks Maximum number of distinct flows to retain
     */
    public function getOccupationInheritance(int $topLinks): SankeyFlowsPayload
    {
        return $this->occupationInheritanceRepository->occupationInheritance($topLinks);
    }

    /**
     * Translated NOMINATIVE month names keyed by the GEDCOM 3-letter
     * abbreviation.
     *
     * @return array<string, string>
     */
    private function monthLabels(): array
    {
        return MonthName::byAbbreviation();
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
