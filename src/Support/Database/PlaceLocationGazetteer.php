<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Database;

use Fisharebest\Webtrees\DB;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function array_reverse;
use function preg_split;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Read-only resolver for the webtrees place-coordinate gazetteer (the global
 * `place_location` table populated through the control panel's map data — the
 * normal way a user geocodes places, as opposed to embedding a `PLAC:MAP`
 * sub-tag on every fact).
 *
 * webtrees' own {@see \Fisharebest\Webtrees\PlaceLocation} resolves a place name
 * but {@see \Fisharebest\Webtrees\PlaceLocation::id()} INSERTS missing rows as a
 * side effect — unacceptable for a statistic. This helper loads the whole
 * gazetteer once and resolves place names in memory, never writing.
 *
 * Resolution mirrors webtrees: the place hierarchy roots at the country
 * (`parent_id IS NULL`, normalised to key 0 here) and descends most-general
 * first; a place resolves to the coordinates of its exact leaf row, or null when
 * any level of the chain is missing or the leaf carries no coordinates.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class PlaceLocationGazetteer
{
    /**
     * `parentId => placeName => {id, lat, lng}`. The root level (`parent_id IS
     * NULL`) is keyed under 0.
     *
     * @param array<int, array<string, array{id: int, lat: float|null, lng: float|null}>> $children
     */
    private function __construct(
        private array $children,
    ) {
    }

    /**
     * Load the entire `place_location` gazetteer into an in-memory resolver.
     */
    public static function load(): self
    {
        $rows = DB::table('place_location')
            ->select(['id', 'parent_id', 'place', 'latitude', 'longitude'])
            ->get();

        // Cast the loosely-typed query rows to a typed shape here, at the
        // database boundary, so the resolver itself works on plain arrays. A
        // missing parent_id (the country roots) collapses to 0.
        $mapped = [];

        foreach ($rows as $row) {
            $mapped[] = [
                'id'        => RowCast::int($row, 'id'),
                'parent_id' => RowCast::int($row, 'parent_id'),
                'place'     => RowCast::string($row, 'place'),
                'lat'       => RowCast::nullableFloat($row, 'latitude'),
                'lng'       => RowCast::nullableFloat($row, 'longitude'),
            ];
        }

        return self::fromRows($mapped);
    }

    /**
     * Build the resolver from typed gazetteer rows. Split out from {@see load()}
     * so the in-memory hierarchy resolution is unit-testable without a database.
     *
     * @param iterable<array{id: int, parent_id: int, place: string, lat: float|null, lng: float|null}> $rows
     */
    public static function fromRows(iterable $rows): self
    {
        $children = [];

        foreach ($rows as $row) {
            $children[$row['parent_id']][$row['place']] = [
                'id'  => $row['id'],
                'lat' => $row['lat'],
                'lng' => $row['lng'],
            ];
        }

        return new self($children);
    }

    /**
     * Resolve a GEDCOM PLAC string ("<most specific>, …, <country>") to the
     * coordinates of its exact leaf in the gazetteer. Returns null when any part
     * of the chain is missing or the leaf carries no coordinates.
     *
     * @param string $place The PLAC value, e.g. "Berlin, Deutschland"
     *
     * @return array{0: float, 1: float}|null `[latitude, longitude]`, or null
     */
    public function resolve(string $place): ?array
    {
        $parts = preg_split('/\s*,\s*/', trim($place), -1, PREG_SPLIT_NO_EMPTY);

        if (($parts === false) || ($parts === [])) {
            return null;
        }

        $parentId = 0;
        $leaf     = null;

        // PLAC orders most-specific first; the gazetteer roots at the country,
        // so descend in reverse.
        foreach (array_reverse($parts) as $part) {
            $leaf = $this->children[$parentId][$part] ?? null;

            if ($leaf === null) {
                return null;
            }

            $parentId = $leaf['id'];
        }

        if (($leaf['lat'] === null) || ($leaf['lng'] === null)) {
            return null;
        }

        return [$leaf['lat'], $leaf['lng']];
    }
}
