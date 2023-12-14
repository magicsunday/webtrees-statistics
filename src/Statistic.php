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
use Fisharebest\Webtrees\Statistics\Google\ChartDistribution;
use Fisharebest\Webtrees\Statistics\Repository\Interfaces\IndividualRepositoryInterface;
use Fisharebest\Webtrees\Statistics\Service\CenturyService;
use Fisharebest\Webtrees\Statistics\Service\ColorService;
use Fisharebest\Webtrees\Statistics\Service\CountryService;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Repository\EventRepository;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRepository;
use MagicSunday\Webtrees\Statistic\Repository\IndividualRepository;
use MagicSunday\Webtrees\Statistic\Repository\NameRepository;

/**
 * A selection of pre-formatted statistical queries.
 * These are primarily used for embedded keywords on HTML blocks, but
 * are also used elsewhere in the code.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/ */
class Statistic
{
    /**
     * @var ColorService
     */
    private ColorService $colorService;

    /**
     * @var IndividualRepository
     */
    private IndividualRepository $individualRepository;

    /**
     * @var FamilyRepository
     */
    private FamilyRepository $familyRepository;

    /**
     * @var EventRepository
     */
    private EventRepository $eventRepository;

    /**
     * @var NameRepository
     */
    private NameRepository $nameRepository;

    /**
     * Constructor.
     *
     * @param Tree                          $tree           The tree used to generate the statistics for
     * @param ColorService                  $colorService   The color service
     * @param CenturyService                $centuryService The century service
     * @param CountryService                $countryService
     */
    public function __construct(
        Tree $tree,
        ColorService $colorService,
        CenturyService $centuryService,
        CountryService $countryService
    ) {
        $individual_repository   = new \Fisharebest\Webtrees\Statistics\Repository\IndividualRepository($centuryService, $colorService, $tree);
        $chartDistribution = new ChartDistribution($tree, $countryService, $individual_repository);

        $this->colorService         = $colorService;
        $this->individualRepository = new IndividualRepository($tree);
        $this->familyRepository     = new FamilyRepository($tree);
        $this->eventRepository      = new EventRepository($tree, $centuryService, $chartDistribution);
        $this->nameRepository       = new NameRepository($tree);
    }

    /**
     * @return int
     */
    public function getTotalIndividuals(): int
    {
        return $this->individualRepository->getTotalIndividuals();
    }

    /**
     * @return array<array<string, string|int>>
     */
    public function getTotalIndividualsData(): array
    {
        return [
            [
                'label' => I18N::translate('Male'),
                'value' => $this->individualRepository->getTotalSexMale(),
                'class' => 'male',
            ],
            [
                'label' => I18N::translate('Female'),
                'value' => $this->individualRepository->getTotalSexFemale(),
                'class' => 'female',
            ],
            [
                'label' => I18N::translate('Unknown'),
                'value' => $this->individualRepository->getTotalSexUnknown(),
                'class' => 'unknown',
            ],
        ];
    }

    /**
     * @return array<array<string, string|int>>
     */
    public function getTotalLivingDeceasedData(): array
    {
        return [
            [
                'label' => I18N::translate('Living'),
                'value' => $this->individualRepository->getTotalLiving(),
                'class' => 'living',
            ],
            [
                'label' => I18N::translate('Deceased'),
                'value' => $this->individualRepository->getTotalDeceased(),
                'class' => 'deceased',
            ],
        ];
    }

    /**
     * @return array<array<string, string|int>>
     */
    public function getFamilyStatusData(): array
    {
        $totalMarriedIndividuals = $this->familyRepository->getTotalMarriedMales()
            + $this->familyRepository->getTotalMarriedFemales();

        $totalNotMarriedIndividuals = $this->individualRepository->getTotalIndividuals()
            - $this->familyRepository->getTotalMarriedMales()
            - $this->familyRepository->getTotalMarriedFemales();

        return [
            [
                'label' => I18N::translate('Verheiratet'),
                'value' => $totalMarriedIndividuals,
                'class' => 'married',
            ],
            [
                'label' => I18N::translate('Allein lebend'),
                'value' => $totalNotMarriedIndividuals,
                'class' => 'alone',
            ],
            [
                'label' => I18N::translate('Verwitwet'),
                'value' => 38,
                'class' => 'widowed',
            ],
            [
                'label' => I18N::translate('Geschieden'),
                'value' => 0,
                'class' => 'divorced',
            ],
        ];
    }

    /**
     * Returns the total number of different surnames.
     *
     * @return int
     */
    public function getTotalSurnames(): int
    {
        return $this->nameRepository->getTotalSurnames();
    }

    /**
     * @param int $limit The number of surnames to return
     *
     * @return array
     */
    public function getTopSurnames(int $limit): array
    {
        $data = $this->nameRepository->getTopSurnames($limit);

        // Sort names in ascending order by name
        uasort(
            $data,
            static fn (array $x, array $y): int => $x['name'] <=> $y['name']
        );

        $result = [];

        // Transform list into output data structure
        foreach ($data as $entry) {
            $result[] = [
                'label' => $entry['name'],
                'value' => $entry['count'],
            ];
        }

        return $result;

        //        $totalCount = count($data);
        //
        //        // Interpolate color values
        //        $colors = $this->colorService->interpolateRgb(
        //            'ffffff',
        //            '84beff',
        //            $totalCount + 1
        //        );
        //
        //        $result = [];
        //
        //        // Transform list into output data structure
        //        foreach ($data as $key => $entry) {
        //            $result[] = [
        //                'label' => $entry['name'],
        //                'value' => $entry['count'],
        //                'fill'  => $colors[$key],
        //            ];
        //        }
    }

    public function getTotalMaleGivenNames(): int
    {
        return $this->nameRepository->getTotalMaleGivenNames();
    }

    public function getTopMaleGivenNames(int $limit): array
    {
        $data = $this->nameRepository->getTopMaleGivenNames($limit);

        // Sort names in ascending order by name
        uasort(
            $data,
            static fn (array $x, array $y): int => $x['name'] <=> $y['name']
        );

        $result = [];

        // Transform list into output data structure
        foreach ($data as $entry) {
            $result[] = [
                'label' => $entry['name'],
                'value' => $entry['count'],
            ];
        }

        return $result;
    }

    public function getTotalFemaleGivenNames(): int
    {
        return $this->nameRepository->getTotalFemaleGivenNames();
    }

    public function getTopFemaleGivenNames(int $limit): array
    {
        $data = $this->nameRepository->getTopFemaleGivenNames($limit);

        // Sort names in ascending order by name
        uasort(
            $data,
            static fn (array $x, array $y): int => $x['name'] <=> $y['name']
        );

        $result = [];

        // Transform list into output data structure
        foreach ($data as $entry) {
            $result[] = [
                'label' => $entry['name'],
                'value' => $entry['count'],
            ];
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByMonth(): array
    {
        return $this->eventRepository->getBirthsByMonth();
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByCentury(): array
    {
        return $this->eventRepository->getBirthsByCentury();
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByZodiacSign(): array
    {
        return $this->eventRepository->getBirthsByZodiacSign();
    }

    /**
     * @return array<string, int>
     */
    public function getBirthsByCountry(): array
    {
        return $this->eventRepository->getBirthsByCountry();
    }

    /**
     * @return array<string, int>
     */
    public function getDeathsByMonth(): array
    {
        return $this->eventRepository->getDeathsByMonth();
    }

    /**
     * @return array<string, int>
     */
    public function getDeathsByCentury(): array
    {
        return $this->eventRepository->getDeathsByCentury();
    }

    /**
     * @return array<string, int>
     */
    public function getDeathsByCountry(): array
    {
        return $this->eventRepository->getDeathsByCountry();
    }
}
