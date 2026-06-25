<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\DB;
use MagicSunday\Webtrees\Statistic\Model\Metric\PlaceDispersionSummary;
use MagicSunday\Webtrees\Statistic\Repository\PlaceDispersionRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\Haversine;
use MagicSunday\Webtrees\Statistic\Support\Database\PlaceLocationGazetteer;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Test\Support\Narrowing\PayloadNarrowing;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * End-to-end test of {@see PlaceDispersionRepository} against a fixture that
 * exercises:
 *
 *   I1 — same BIRT and DEAT place → distinct = 1
 *   I2 — two different places → distinct = 2
 *   I3 — three different places → distinct = 3
 *   I4 — five different places → distinct = 5 (= overflow bucket)
 *   I5 — single BIRT → distinct = 1
 *   I6 — no events at all → excluded from the average
 *
 * Expected: sampled = 5, sum = 12, average = 2.4, distribution `{"1": 2, "2":
 * 1, "3": 1, "4": 0, "5+": 1}`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(PlaceDispersionRepository::class)]
#[UsesClass(PlaceDispersionSummary::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
#[UsesClass(GedcomScanner::class)]
#[UsesClass(Haversine::class)]
#[UsesClass(PlaceLocationGazetteer::class)]
final class PlaceDispersionRepositoryIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * The dispersion summary aggregates every individual that carries at least
     * one PLAC sub-tag, computes the average count of distinct PLAC values per
     * such individual, and buckets the per-individual counts into a {1, 2, 3,
     * 4, 5+} histogram.
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
     * An individual whose BIRT and DEAT places match (Anna in the fixture) is
     * correctly de-duplicated to a single distinct place. Without this de-dup
     * the count would over-state mobility.
     */
    #[Test]
    public function duplicatePlacesOnSameIndividualCollapseToOne(): void
    {
        $tree   = $this->importFixtureTree('place-dispersion.ged');
        $result = (new PlaceDispersionRepository($tree))->dispersionSummary();

        // The distribution shows TWO individuals at count 1: Anna
        // (BIRT+DEAT same place, de-duped) and Emil (single BIRT).
        PayloadNarrowing::assertValueAt(2, $result->distribution, '1');
    }

    /**
     * The migration-distance distribution buckets each individual by the
     * great-circle kilometres between their birth-place and death-place MAP
     * coordinates. The fixture places one individual in each band (I1 same place
     * → ≤10, I2 Berlin→Potsdam ≈ 27 km → 11–50, I3 →Leipzig ≈ 149 → 51–200, I4
     * →Hamburg ≈ 255 → 201–500, I5 →London ≈ 931 → 501–1000, I6 →New York ≈ 6385
     * → 1000+). I7 carries a PLAC but no MAP on either endpoint and must be
     * dropped, not counted.
     */
    #[Test]
    public function getMigrationDistanceDistributionBucketsByGreatCircleKilometres(): void
    {
        $tree   = $this->importFixtureTree('migration-distance.ged');
        $result = (new PlaceDispersionRepository($tree))->getMigrationDistanceDistribution();

        self::assertSame($this->oneIndividualPerBand(), $result);
    }

    /**
     * Coordinates also resolve through the place-name gazetteer (the
     * `place_location` table the control panel populates), not only fact-level
     * MAP sub-tags. The fixture records PLAC names without any MAP tag; seeding
     * the gazetteer with the matching hierarchy must reproduce the same
     * one-per-band distribution as the MAP-tagged fixture.
     */
    #[Test]
    public function getMigrationDistanceDistributionResolvesPlacesViaTheGazetteer(): void
    {
        $tree = $this->importFixtureTree('migration-distance-gazetteer.ged');

        DB::table('place_location')->insert([
            ['id' => 1, 'parent_id' => null, 'place' => 'Deutschland', 'latitude' => null, 'longitude' => null],
            ['id' => 2, 'parent_id' => 1, 'place' => 'Berlin', 'latitude' => 52.52, 'longitude' => 13.405],
            ['id' => 3, 'parent_id' => 1, 'place' => 'Potsdam', 'latitude' => 52.40, 'longitude' => 13.06],
            ['id' => 4, 'parent_id' => 1, 'place' => 'Leipzig', 'latitude' => 51.34, 'longitude' => 12.37],
            ['id' => 5, 'parent_id' => 1, 'place' => 'Hamburg', 'latitude' => 53.55, 'longitude' => 9.99],
            ['id' => 6, 'parent_id' => null, 'place' => 'United Kingdom', 'latitude' => null, 'longitude' => null],
            ['id' => 7, 'parent_id' => 6, 'place' => 'London', 'latitude' => 51.50, 'longitude' => -0.12],
            ['id' => 8, 'parent_id' => null, 'place' => 'United States', 'latitude' => null, 'longitude' => null],
            ['id' => 9, 'parent_id' => 8, 'place' => 'New York', 'latitude' => 40.71, 'longitude' => -74.00],
        ]);

        $result = (new PlaceDispersionRepository($tree))->getMigrationDistanceDistribution();

        self::assertSame($this->oneIndividualPerBand(), $result);
    }

    /**
     * Below the minimum geocoded cohort the distribution is suppressed entirely
     * (empty list → the view renders a "too sparse" placeholder). The sparse
     * fixture carries four fully-geocoded individuals, one below the floor.
     */
    #[Test]
    public function getMigrationDistanceDistributionIsEmptyBelowTheMinimumGeocodedCohort(): void
    {
        $tree   = $this->importFixtureTree('migration-distance-sparse.ged');
        $result = (new PlaceDispersionRepository($tree))->getMigrationDistanceDistribution();

        self::assertSame([], $result);
    }

    /**
     * A tree where no individual carries MAP coordinates on both endpoints
     * yields an empty distribution.
     */
    #[Test]
    public function getMigrationDistanceDistributionIsEmptyWhenNoIndividualIsGeocoded(): void
    {
        $tree = $this->importFixtureTree('empty-tree.ged');

        self::assertSame([], (new PlaceDispersionRepository($tree))->getMigrationDistanceDistribution());
    }

    /**
     * The expected distribution when exactly one individual falls into each
     * distance band — the shape both the MAP-tagged and the gazetteer-resolved
     * fixtures are built to produce.
     *
     * @return list<array{band: string, count: int}>
     */
    private function oneIndividualPerBand(): array
    {
        return [
            ['band' => 'le10', 'count' => 1],
            ['band' => '11-50', 'count' => 1],
            ['band' => '51-200', 'count' => 1],
            ['band' => '201-500', 'count' => 1],
            ['band' => '501-1000', 'count' => 1],
            ['band' => '1000+', 'count' => 1],
        ];
    }
}
