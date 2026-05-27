<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Tree;

use JsonSerializable;

/**
 * Aggregated headline metrics for the Statistics-page hero — the six
 * numbers that introduce the tree at a glance (total individuals,
 * total families, deepest verified generation, mean generation length
 * in years, average pedigree completeness fraction, and the source
 * citation coverage as a fraction). Only the average-generation-years
 * figure is nullable, because a tree without a single parseable
 * BIRT-to-BIRT parent-child pair has no defined value; every other
 * metric is well-defined and defaults to 0 / 0.0 for an empty tree.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class HeroStats implements JsonSerializable
{
    /**
     * @param int        $individuals            Total recorded individuals (INDI records)
     * @param int        $families               Total recorded families (FAM records)
     * @param int        $maxGenerationDepth     Deepest verified parent-child chain length anywhere in the tree
     * @param float|null $averageGenerationYears Mean years between parent and child birth across parseable pairs (null when the tree has no usable pair)
     * @param float      $pedigreeCompleteness   Mean Lacy (1989) 4-generation pedigree-completeness index across every individual; 0.0 – 1.0 fraction
     * @param float      $sourceCitationCoverage Fraction of individuals that carry at least one SOUR citation; 0.0 – 1.0
     * @param int        $centurySpan            Rounded count of centuries spanned between the earliest and latest recorded birth decade; feeds the spelled-out "over X centuries" deck copy
     * @param int|null   $decadeFrom             Earliest recorded birth decade (e.g. 1490) or null when no births are dated
     * @param int|null   $decadeTo               Latest recorded birth decade (e.g. 2020) or null when no births are dated
     */
    public function __construct(
        public int $individuals,
        public int $families,
        public int $maxGenerationDepth,
        public ?float $averageGenerationYears,
        public float $pedigreeCompleteness,
        public float $sourceCitationCoverage,
        public int $centurySpan,
        public ?int $decadeFrom = null,
        public ?int $decadeTo = null,
    ) {
    }

    /**
     * @return array{individuals: int, families: int, maxGenerationDepth: int, averageGenerationYears: float|null, pedigreeCompleteness: float, sourceCitationCoverage: float, centurySpan: int, decadeFrom: int|null, decadeTo: int|null}
     */
    public function jsonSerialize(): array
    {
        return [
            'individuals'            => $this->individuals,
            'families'               => $this->families,
            'maxGenerationDepth'     => $this->maxGenerationDepth,
            'averageGenerationYears' => $this->averageGenerationYears,
            'pedigreeCompleteness'   => $this->pedigreeCompleteness,
            'sourceCitationCoverage' => $this->sourceCitationCoverage,
            'centurySpan'            => $this->centurySpan,
            'decadeFrom'             => $this->decadeFrom,
            'decadeTo'               => $this->decadeTo,
        ];
    }
}
