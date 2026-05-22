<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Dto\Tree;

use JsonSerializable;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\FamilyCountRecord;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\FamilyDurationDaysRecord;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\FamilyDurationYearsRecord;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\IndividualAgeRecord;
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\IndividualCountRecord;

/**
 * Hall-of-Fame report bundling every record-holder slot the Overview
 * tab renders. Each property is independently nullable — a fresh
 * tree without enough data may yield zero, some, or all slots; the
 * view renders each row only when its slot is populated.
 *
 * Lives alongside {@see GenerationDepthReport} in the `Model\Dto\Tree`
 * namespace so all per-tree aggregate reports share one home.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class TreeRecordsReport implements JsonSerializable
{
    public function __construct(
        public ?IndividualAgeRecord $oldestDeceased,
        public ?IndividualAgeRecord $oldestLiving,
        public ?FamilyDurationYearsRecord $longestMarriage,
        public ?FamilyDurationDaysRecord $shortestMarriage,
        public ?IndividualAgeRecord $youngestHusband,
        public ?IndividualAgeRecord $youngestWife,
        public ?IndividualAgeRecord $oldestHusband,
        public ?IndividualAgeRecord $oldestWife,
        public ?IndividualCountRecord $mostSpouses,
        public ?FamilyCountRecord $largestFamily,
        public ?IndividualCountRecord $mostChildrenPerPerson,
        public ?IndividualAgeRecord $youngestFatherAtFirstChild,
        public ?IndividualAgeRecord $youngestMotherAtFirstChild,
        public ?IndividualAgeRecord $oldestFatherAtFirstChild,
        public ?IndividualAgeRecord $oldestMotherAtFirstChild,
    ) {
    }

    /**
     * @return array<string, IndividualAgeRecord|IndividualCountRecord|FamilyCountRecord|FamilyDurationYearsRecord|FamilyDurationDaysRecord|null>
     */
    public function jsonSerialize(): array
    {
        return [
            'oldestDeceased'             => $this->oldestDeceased,
            'oldestLiving'               => $this->oldestLiving,
            'longestMarriage'            => $this->longestMarriage,
            'shortestMarriage'           => $this->shortestMarriage,
            'youngestHusband'            => $this->youngestHusband,
            'youngestWife'               => $this->youngestWife,
            'oldestHusband'              => $this->oldestHusband,
            'oldestWife'                 => $this->oldestWife,
            'mostSpouses'                => $this->mostSpouses,
            'largestFamily'              => $this->largestFamily,
            'mostChildrenPerPerson'      => $this->mostChildrenPerPerson,
            'youngestFatherAtFirstChild' => $this->youngestFatherAtFirstChild,
            'youngestMotherAtFirstChild' => $this->youngestMotherAtFirstChild,
            'oldestFatherAtFirstChild'   => $this->oldestFatherAtFirstChild,
            'oldestMotherAtFirstChild'   => $this->oldestMotherAtFirstChild,
        ];
    }
}
