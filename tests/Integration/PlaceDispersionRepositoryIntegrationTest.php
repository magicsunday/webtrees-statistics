<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\PlaceDispersionRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end test of {@see PlaceDispersionRepository} against a
 * fixture that exercises:
 *
 *   I1 — same BIRT and DEAT place → distinct = 1
 *   I2 — two different places → distinct = 2
 *   I3 — three different places → distinct = 3
 *   I4 — five different places → distinct = 5 (= overflow bucket)
 *   I5 — single BIRT → distinct = 1
 *   I6 — no events at all → excluded from the average
 *
 * Expected: sampled = 5, sum = 12, average = 2.4, distribution
 * `{"1": 2, "2": 1, "3": 1, "4": 0, "5+": 1}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class PlaceDispersionRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * The dispersion summary aggregates every individual that
     * carries at least one PLAC sub-tag, computes the average count
     * of distinct PLAC values per such individual, and buckets the
     * per-individual counts into a {1, 2, 3, 4, 5+} histogram.
     */
    #[Test]
    public function dispersionSummaryAggregatesDistinctPlacesPerIndividual(): void
    {
        $tree   = $this->importFixtureTree('place-dispersion.ged');
        $result = (new PlaceDispersionRepository($tree))->dispersionSummary();

        self::assertSame(5, $result->sampled, 'Franz (no events) is excluded; the other 5 contribute');
        self::assertSame(2.4, $result->average, '(1 + 2 + 3 + 5 + 1) / 5 = 2.4');

        self::assertSame(
            ['1' => 2, '2' => 1, '3' => 1, '4' => 0, '5+' => 1],
            $result->distribution,
        );
    }

    /**
     * An individual whose BIRT and DEAT places match (Anna in the
     * fixture) is correctly de-duplicated to a single distinct
     * place. Without this de-dup the count would over-state mobility.
     */
    #[Test]
    public function duplicatePlacesOnSameIndividualCollapseToOne(): void
    {
        $tree   = $this->importFixtureTree('place-dispersion.ged');
        $result = (new PlaceDispersionRepository($tree))->dispersionSummary();

        // The distribution shows TWO individuals at count 1: Anna
        // (BIRT+DEAT same place, de-duped) and Emil (single BIRT).
        self::assertSame(2, $result->distribution['1']);
    }
}
