<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;
use MagicSunday\Webtrees\Statistic\Model\Metric\PlaceDispersionSummary;
use MagicSunday\Webtrees\Statistic\Support\Calc\Haversine;
use MagicSunday\Webtrees\Statistic\Support\Database\PlaceLocationGazetteer;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function array_keys;
use function array_unique;
use function count;
use function preg_match_all;
use function round;
use function trim;

/**
 * Geographic-dispersion metric for the Places tab — how many distinct PLAC
 * values each individual carries across all their level-1 events (BIRT, DEAT,
 * BAPM, BURI, RESI, …). High dispersion = the person was recorded at many
 * locations across their life, indicating mobility or thorough documentation.
 * Low dispersion (typically 1) = a single PLAC like a birth-only stub record.
 *
 * Returns both the tree-wide average AND the per-individual count distribution
 * so the viewer can disambiguate "high avg because of a few wandering
 * ancestors" from "high avg because everyone moved around".
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class PlaceDispersionRepository
{
    /**
     * Cap for the distribution histogram — individuals with more than this many
     * distinct places collapse into the overflow. Five is a generous ceiling
     * that covers all but the most thoroughly-documented individuals; beyond
     * that the buckets stretch the axis without adding visual information.
     */
    private const int DISTRIBUTION_MAX = 5;

    /**
     * Migration-distance bands as `key => inclusive upper bound in km`, in axis
     * order. Distances above the last bound fall into {@see OVERFLOW_BAND}. The
     * key is a stable identifier; the view maps it to a localised label.
     */
    private const array DISTANCE_BANDS = [
        'le10'     => 10.0,
        '11-50'    => 50.0,
        '51-200'   => 200.0,
        '201-500'  => 500.0,
        '501-1000' => 1000.0,
    ];

    /**
     * Band key for distances beyond the last bound in {@see DISTANCE_BANDS}.
     */
    private const string OVERFLOW_BAND = '1000+';

    /**
     * Below this many individuals with both endpoints geocoded the distance
     * distribution is statistically meaningless (and, in most trees, dominated
     * by a handful of records that happen to carry MAP coordinates), so the
     * method returns an empty list and the view renders a "too sparse"
     * placeholder instead of a misleading chart.
     */
    private const int MIN_GEOCODED_INDIVIDUALS = 5;

    /**
     * Per-instance cache of the tree's individual GEDCOM rows. Both public
     * metrics scan the same whole-tree blob set; the container shares one
     * repository instance per request, so loading it once here halves the
     * Places-tab blob transfer.
     *
     * @var Collection<int, object>|null
     */
    private ?Collection $individualGedcomsCache = null;

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private readonly Tree $tree,
    ) {
    }

    /**
     * The tree's individual GEDCOM rows, loaded once and memoised so the two
     * public metrics share a single full-table read instead of scanning the
     * individuals table twice per Places-tab render.
     *
     * @return Collection<int, object>
     */
    private function individualGedcoms(): Collection
    {
        return $this->individualGedcomsCache ??= TreeScope::individualGedcoms($this->tree);
    }

    /**
     * Per-individual migration distance — the great-circle kilometres between
     * the birth-place and the death-place MAP coordinates — bucketed into the
     * {@see DISTANCE_BANDS}. Only individuals whose BIRT *and* DEAT facts both
     * carry MAP coordinates participate; the death-place requirement means the
     * subjects are deceased, and the bands name no individual, so no privacy
     * gate is needed.
     *
     * Coordinates come from webtrees' own data, never an external geocoder: the
     * fact-level `PLAC:MAP` sub-tag if present, otherwise the place-name
     * gazetteer (`place_location`, populated through the control panel — the
     * usual way places are geocoded). The cohort is therefore the
     * documentation-limited subset of records geocoded at all, a lower bound on
     * real mobility. Returns an empty list when fewer than
     * {@see MIN_GEOCODED_INDIVIDUALS} qualify.
     *
     * @return list<array{band: string, count: int}>
     */
    public function getMigrationDistanceDistribution(): array
    {
        $rows      = $this->individualGedcoms();
        $gazetteer = PlaceLocationGazetteer::load();
        $bands     = $this->initDistanceBands();

        $geocoded = 0;

        foreach ($rows as $row) {
            $gedcom = RowCast::string($row, 'gedcom');

            $birth = $this->eventCoordinates($gedcom, 'BIRT', $gazetteer);
            $death = $this->eventCoordinates($gedcom, 'DEAT', $gazetteer);

            if ($birth === null) {
                continue;
            }

            if ($death === null) {
                continue;
            }

            $distanceKm = Haversine::distanceKm($birth[0], $birth[1], $death[0], $death[1]);

            $band         = $this->bandFor($distanceKm);
            $bands[$band] = ($bands[$band] ?? 0) + 1;
            ++$geocoded;
        }

        if ($geocoded < self::MIN_GEOCODED_INDIVIDUALS) {
            return [];
        }

        $distribution = [];

        foreach ($bands as $band => $count) {
            $distribution[] = [
                'band'  => $band,
                'count' => $count,
            ];
        }

        return $distribution;
    }

    /**
     * Resolve an event's coordinates from webtrees' own data: the fact-level
     * `PLAC:MAP` sub-tag first (some trees embed coordinates per fact), then the
     * place-name gazetteer (the usual control-panel geocoding). Returns null
     * when neither source has coordinates for the event.
     *
     * @return array{0: float, 1: float}|null
     */
    private function eventCoordinates(string $gedcom, string $tag, PlaceLocationGazetteer $gazetteer): ?array
    {
        $factCoordinates = GedcomScanner::extractEventCoordinates($gedcom, $tag);

        if ($factCoordinates !== null) {
            return $factCoordinates;
        }

        $place = GedcomScanner::extractEventPlace($gedcom, $tag);

        if ($place === null) {
            return null;
        }

        return $gazetteer->resolve($place);
    }

    /**
     * Compute the dispersion summary: tree-wide average of distinct PLAC counts
     * per individual, the count of individuals with at least one PLAC sample,
     * and the bucketed distribution of those counts (`1`, `2`, `3`, `4`, `5+`).
     *
     * Individuals with no PLAC sub-tag in their record are silently excluded —
     * they would skew the average toward zero without meaningfully
     * participating in the "how many places does each documented person carry"
     * question.
     */
    public function dispersionSummary(): PlaceDispersionSummary
    {
        $rows = $this->individualGedcoms();

        $distribution = $this->initDistribution();
        $totalPlaces  = 0;
        $sampled      = 0;

        foreach ($rows as $row) {
            $gedcom = RowCast::string($row, 'gedcom');

            $distinct = $this->distinctPlaceCount($gedcom);

            if ($distinct === 0) {
                continue;
            }

            ++$sampled;
            $totalPlaces += $distinct;

            $bucket                = $distinct >= self::DISTRIBUTION_MAX ? self::DISTRIBUTION_MAX . '+' : (string) $distinct;
            $distribution[$bucket] = ($distribution[$bucket] ?? 0) + 1;
        }

        $average = $sampled === 0 ? 0.0 : round($totalPlaces / $sampled, 2);

        return new PlaceDispersionSummary(
            average: $average,
            sampled: $sampled,
            distribution: $distribution,
        );
    }

    /**
     * Count distinct `2 PLAC <value>` sub-tag values across the record. Values
     * are trimmed and compared case-sensitively so `Berlin, Germany` and
     * `Berlin, germany` count as two — a choice that biases toward "more
     * places" but stays simple and predictable; ISO-folding the place names is
     * a separate concern handled by IsoCountryMap.
     */
    private function distinctPlaceCount(string $gedcom): int
    {
        if (preg_match_all('/\n2 PLAC +([^\n]+)/', $gedcom, $matches) === 0) {
            return 0;
        }

        $values = [];

        foreach ($matches[1] as $raw) {
            $value = trim($raw);

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return count(array_unique($values));
    }

    /**
     * Pre-seed every distribution bucket so the histogram renders a continuous
     * 1..5+ x-axis even on trees where some buckets carry zero contributions.
     *
     * @return array<array-key, int>
     */
    private function initDistribution(): array
    {
        $buckets = [];

        for ($i = 1; $i < self::DISTRIBUTION_MAX; ++$i) {
            $buckets[(string) $i] = 0;
        }

        $buckets[self::DISTRIBUTION_MAX . '+'] = 0;

        return $buckets;
    }

    /**
     * Pre-seed every distance band (in axis order, overflow last) so the chart
     * renders a continuous axis even when some bands carry zero contributions.
     *
     * @return array<string, int>
     */
    private function initDistanceBands(): array
    {
        $bands = [];

        foreach (array_keys(self::DISTANCE_BANDS) as $key) {
            $bands[$key] = 0;
        }

        $bands[self::OVERFLOW_BAND] = 0;

        return $bands;
    }

    /**
     * Map a distance in kilometres to its band key — the first band whose
     * inclusive upper bound the distance does not exceed, or the overflow band.
     */
    private function bandFor(float $distanceKm): string
    {
        foreach (self::DISTANCE_BANDS as $key => $upperBoundKm) {
            if ($distanceKm <= $upperBoundKm) {
                return $key;
            }
        }

        return self::OVERFLOW_BAND;
    }
}
