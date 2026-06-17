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
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\TopNAggregator;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\IsoCountryMap;

use function str_ends_with;
use function trim;

/**
 * Aggregates individual events (BIRT / DEAT) by ISO-3166-1 alpha-2 country.
 * Replaces the empty stubs in {@see
 * \MagicSunday\Webtrees\Statistic\Statistic::getBirthsByCountry()} and friends
 * — webtrees' core `countIndividualEventsByCountry()` is private, and the
 * upstream maintainer is not going to expose it, so the module ships its own
 * implementation.
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
     * Count individuals whose given event (BIRT / DEAT / …) carries a place
     * that resolves to a known country.
     *
     * @param string $tag Level-1 event tag (e.g. `'BIRT'`, `'DEAT'`)
     *
     * @return list<array{code: string, label: string, count: int}>
     */
    public function countByCountry(string $tag): array
    {
        $rows = TreeScope::table($this->tree, 'places')
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
            $place  = RowCast::string($row, 'place');
            $gedcom = RowCast::string($row, 'gedcom');

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

        return $this->rankEntries($counts);
    }

    /**
     * Count residences across the tree, grouped by country. Differs from {@see
     * countByCountry()} in two respects: it scans every `1 RESI` occurrence on
     * every individual (a person with three recorded residences contributes
     * three times), and it bypasses the placelinks join because we need *all*
     * RESI places per person, not the ones that happen to share a top-level
     * place row with another event.
     *
     * @return list<array{code: string, label: string, count: int}>
     */
    public function residencesByCountry(): array
    {
        // Only individuals carrying a `1 RESI` line contribute, so anchor-LIKE
        // the residence-bearers before transferring blobs — the whole-tree
        // GEDCOM scan otherwise reads (and regex-walks) every individual even
        // though most record no residence. The sibling countByCountry() is
        // already narrow via its placelinks join; this brings residencesByCountry
        // to the same footing.
        $resiPatterns = GedcomScanner::anchoredLikePatterns('RESI');

        $rows = TreeScope::table($this->tree, 'individuals')
            ->where(static function (Builder $query) use ($resiPatterns): void {
                foreach ($resiPatterns as $pattern) {
                    $query->orWhere('i_gedcom', 'like', $pattern);
                }
            })
            ->select(['i_gedcom AS gedcom'])
            ->get();

        $counts = [];

        foreach ($rows as $row) {
            $gedcom = RowCast::string($row, 'gedcom');

            foreach (GedcomScanner::extractAllEventPlaces($gedcom, 'RESI') as $place) {
                // RESI PLAC strings come straight from the GEDCOM as
                // "Hamburg, Germany" / "New York, USA"; resolveFromPlace
                // peels the last comma-separated (country) segment before
                // the lookup.
                $iso2 = $this->isoMap->resolveFromPlace($place);

                if ($iso2 === null) {
                    continue;
                }

                $counts[$iso2] = ($counts[$iso2] ?? 0) + 1;
            }
        }

        return $this->rankEntries($counts);
    }

    /**
     * Order an `ISO2 => count` map by descending count and emit the labelled
     * entry rows the WorldMap widget and its companion ProgressList consume.
     *
     * Equal-count countries are broken on the ISO2 code ascending (byte order)
     * via the shared {@see TopNAggregator::rankKeys()}, never on the database
     * row order — the latter collates differently across SQLite and MySQL, and
     * once the view slices the list to the top 10 a row-order tie could change
     * which country appears at the boundary. Sorting biggest-first also keeps
     * the top-10 list meaningfully ranked rather than alphabetical-by-place.
     *
     * @param array<string, int> $counts ISO2 code => occurrence count
     *
     * @return list<array{code: string, label: string, count: int}>
     */
    private function rankEntries(array $counts): array
    {
        $entries = [];

        foreach (TopNAggregator::rankKeys($counts, 0) as $iso2) {
            $entries[] = [
                'code'  => $iso2,
                'label' => $this->isoMap->label($iso2),
                'count' => $counts[$iso2],
            ];
        }

        return $entries;
    }

    /**
     * True when the event's PLAC string ends with (or equals) the given
     * top-level country place name. Comma-trimmed comparison — "Hamburg,
     * Germany" matches "Germany" but an unrelated "Germany Town" elsewhere in
     * the place string would not.
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
