<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Support\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\IsoCountryMap;

use function array_unique;
use function arsort;
use function count;
use function is_string;
use function str_ends_with;
use function strrpos;
use function substr;
use function trim;

use const SORT_NUMERIC;

/**
 * Aggregates individual events (BIRT / DEAT) by ISO-3166-1 alpha-2
 * country. Replaces the empty stubs in
 * {@see \MagicSunday\Webtrees\Statistic\Statistic::getBirthsByCountry()}
 * and friends — webtrees' core `countIndividualEventsByCountry()`
 * is private, and the upstream maintainer is not going to expose
 * it, so the module ships its own implementation.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class CountryRepository
{
    /**
     * @param Tree          $tree   The tree the statistics are computed for
     * @param IsoCountryMap $isoMap Name → ISO-2 resolver + localised label provider
     */
    public function __construct(
        private Tree $tree,
        private IsoCountryMap $isoMap,
    ) {
    }

    /**
     * Count individuals whose given event (BIRT / DEAT / …) carries
     * a place that resolves to a known country.
     *
     * @param string $tag Level-1 event tag (e.g. `'BIRT'`, `'DEAT'`)
     *
     * @return list<array{countryCode: string, label: string, count: int}>
     */
    public function countByCountry(string $tag): array
    {
        $rows = DB::table('places')
            ->where('p_file', '=', $this->tree->id())
            ->where('p_parent_id', '=', 0)
            ->join('placelinks', static function (JoinClause $join): void {
                $join
                    ->on('pl_file', '=', 'p_file')
                    ->on('pl_p_id', '=', 'p_id');
            })
            ->join('individuals', static function (JoinClause $join): void {
                $join
                    ->on('pl_file', '=', 'i_file')
                    ->on('pl_gid', '=', 'i_id');
            })
            ->select(['p_place AS place', 'i_gedcom AS gedcom'])
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $place  = is_string($row->place) ? $row->place : '';
            $gedcom = is_string($row->gedcom) ? $row->gedcom : '';

            $iso2 = $this->isoMap->resolve($place);

            if ($iso2 === null) {
                continue;
            }

            // Re-confirm against raw GEDCOM: this place must
            // actually belong to the requested event on this
            // individual. An individual whose BIRT is in Germany
            // and DEAT in France would otherwise count for both
            // lookups via the same placelinks row, since
            // placelinks doesn't carry the originating fact.
            $eventPlace = GedcomScanner::extractEventPlace($gedcom, $tag);

            if ($eventPlace === null) {
                continue;
            }

            if (!$this->placeEndsInCountry($eventPlace, $place)) {
                continue;
            }

            $counts[$iso2] = ($counts[$iso2] ?? 0) + 1;
        }

        // Sort by descending count so the companion ProgressList
        // shows the biggest country first — insertion order from
        // the placelinks join is otherwise alphabetical-by-place,
        // which makes the "top 10" look randomly ranked.
        arsort($counts, SORT_NUMERIC);

        $entries = [];

        foreach ($counts as $iso2 => $count) {
            $entries[] = [
                'countryCode' => $iso2,
                'label'       => $this->isoMap->label($iso2),
                'count'       => $count,
            ];
        }

        return $entries;
    }

    /**
     * Count residences across the tree, grouped by country. Differs
     * from {@see countByCountry()} in two respects: it scans every
     * `1 RESI` occurrence on every individual (a person with three
     * recorded residences contributes three times), and it bypasses
     * the placelinks join because we need *all* RESI places per
     * person, not the ones that happen to share a top-level place
     * row with another event.
     *
     * @return list<array{countryCode: string, label: string, count: int}>
     */
    public function residencesByCountry(): array
    {
        $rows = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->select(['i_gedcom AS gedcom'])
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $gedcom = is_string($row->gedcom) ? $row->gedcom : '';

            foreach (GedcomScanner::extractAllEventPlaces($gedcom, 'RESI') as $place) {
                // RESI PLAC strings come straight from the GEDCOM as
                // "Hamburg, Germany" / "New York, USA"; isoMap->resolve
                // wants the bare country segment so we peel off the
                // last comma-separated part before the lookup.
                $iso2 = $this->isoMap->resolve($this->countrySegment($place));

                if ($iso2 === null) {
                    continue;
                }

                $counts[$iso2] = ($counts[$iso2] ?? 0) + 1;
            }
        }

        arsort($counts, SORT_NUMERIC);

        $entries = [];

        foreach ($counts as $iso2 => $count) {
            $entries[] = [
                'countryCode' => $iso2,
                'label'       => $this->isoMap->label($iso2),
                'count'       => $count,
            ];
        }

        return $entries;
    }

    /**
     * Most-residences record holder: the individual with the most
     * DISTINCT PLAC values across their `1 RESI` events. Counts
     * unique addresses, not raw event count — a person who has
     * three `1 RESI / 2 PLAC Hamburg` entries scores 1, while
     * someone with `Hamburg / München / Berlin` scores 3. This
     * matches the user intuition of "moved around the most".
     *
     * Place strings are trimmed and compared case-sensitively, so
     * "Hamburg, Germany" and "Hamburg, germany" count as two
     * distinct addresses; ISO-folding is out of scope here.
     *
     * @return array{individual: Individual, count: int}|null
     */
    public function mostResidencesRecord(): ?array
    {
        // Pre-filter at the DB layer so we don't iterate every
        // individual's full i_gedcom — most rows have no RESI at
        // all and would otherwise be pulled into PHP for nothing.
        $rows = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->where('i_gedcom', 'LIKE', '%1 RESI%')
            ->select(['i_id AS xref', 'i_gedcom AS gedcom'])
            ->get();

        $bestCount = 0;
        $bestXref  = null;

        foreach ($rows as $row) {
            $gedcom = is_string($row->gedcom ?? null) ? $row->gedcom : '';
            $xref   = is_string($row->xref ?? null) ? $row->xref : '';

            if ($xref === '') {
                continue;
            }

            $distinct = count(array_unique(GedcomScanner::extractAllEventPlaces($gedcom, 'RESI')));

            if ($distinct > $bestCount) {
                $bestCount = $distinct;
                $bestXref  = $xref;
            }
        }

        if (($bestXref === null) || ($bestCount < 2)) {
            return null;
        }

        $individual = Registry::individualFactory()->make($bestXref, $this->tree);

        if (!$individual instanceof Individual) {
            return null;
        }

        return ['individual' => $individual, 'count' => $bestCount];
    }

    /**
     * Peel the last comma-separated segment off a GEDCOM PLAC string
     * — the country-name segment by GEDCOM convention. "Hamburg,
     * Germany" → "Germany". Trims surrounding whitespace and returns
     * the full input verbatim when no comma is present.
     */
    private function countrySegment(string $placeString): string
    {
        $trimmed = trim($placeString);
        $comma   = strrpos($trimmed, ',');

        return $comma === false ? $trimmed : trim(substr($trimmed, $comma + 1));
    }

    /**
     * True when the event's PLAC string ends with (or equals) the
     * given top-level country place name. Comma-trimmed comparison
     * — "Hamburg, Germany" matches "Germany" but an unrelated
     * "Germany Town" elsewhere in the place string would not.
     */
    private function placeEndsInCountry(string $placeString, string $country): bool
    {
        $trimmed = trim($placeString);

        if ($trimmed === $country) {
            return true;
        }

        return str_ends_with($trimmed, ', ' . $country);
    }
}
