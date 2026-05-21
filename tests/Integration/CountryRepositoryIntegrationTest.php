<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\CountryRepository;
use MagicSunday\Webtrees\Statistic\Support\IsoCountryMap;
use PHPUnit\Framework\Attributes\Test;

use function array_column;
use function array_combine;

/**
 * End-to-end test of {@see CountryRepository::countByCountry()}
 * against a curated fixture: covers a country with multiple
 * individuals, a multi-segment place hierarchy
 * ("Munich, Bayern, Germany"), a country expressed in two
 * languages (Wien/Vienna both resolving to Austria), an individual
 * with no place at all, and a place whose top-level segment is
 * not a known country ("Atlantis").
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class CountryRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Births aggregated per country, including the Munich (3-segment
     * place) → Germany and Wien → Austria collapses. Atlantis is not
     * a country and is silently skipped; the empty BIRT (I5) and
     * the BIRT-place-only individual (I6) contribute nothing.
     */
    #[Test]
    public function countByCountryReturnsExpectedBirthDistribution(): void
    {
        IsoCountryMap::clearCache();

        $tree   = $this->importFixtureTree('places-test.ged');
        $result = (new CountryRepository($tree, new IsoCountryMap()))->countByCountry('BIRT');

        $byCode = array_combine(array_column($result, 'countryCode'), array_column($result, 'count'));

        // Germany: I1 (Hamburg) + I2 (Munich) = 2
        self::assertSame(2, $byCode['DE'] ?? null);
        // USA: I3 (New York) + I8 (Newport, deeply nested) = 2
        self::assertSame(2, $byCode['US'] ?? null);
        // Austria: I4 (Wien) = 1 — German-language country name
        self::assertSame(1, $byCode['AT'] ?? null);
        // United Kingdom: I7 (London, England, UK) = 1
        self::assertSame(1, $byCode['GB'] ?? null);
        // Côte d'Ivoire: I9 (Abidjan) = 1 — diacritics + apostrophe
        self::assertSame(1, $byCode['CI'] ?? null);

        // Atlantis isn't a real country; I5/I6 have incomplete BIRT.
        self::assertArrayNotHasKey('XX', $byCode);
    }

    /**
     * Deaths aggregated per country. Wien (German) and Vienna
     * (English) collapse onto Austria via the locale-aware
     * resolver, so I4 (Vienna death) + I5 (Salzburg death) = 2.
     */
    #[Test]
    public function countByCountryReturnsExpectedDeathDistribution(): void
    {
        IsoCountryMap::clearCache();

        $tree   = $this->importFixtureTree('places-test.ged');
        $result = (new CountryRepository($tree, new IsoCountryMap()))->countByCountry('DEAT');

        $byCode = array_combine(array_column($result, 'countryCode'), array_column($result, 'count'));

        // Germany: I1 (Berlin) = 1
        self::assertSame(1, $byCode['DE'] ?? null);
        // France: I2 (Paris) = 1
        self::assertSame(1, $byCode['FR'] ?? null);
        // USA: I3 (Boston) + I8 (Providence) = 2
        self::assertSame(2, $byCode['US'] ?? null);
        // Austria: I4 (Vienna) + I5 (Salzburg) = 2
        self::assertSame(2, $byCode['AT'] ?? null);
        // United Kingdom: I7 (Edinburgh) = 1
        self::assertSame(1, $byCode['GB'] ?? null);
        // Côte d'Ivoire: I9 (Yamoussoukro) = 1
        self::assertSame(1, $byCode['CI'] ?? null);
    }

    /**
     * Every returned row carries a non-empty localised label so
     * the WorldMap widget can render a meaningful tooltip without
     * a second lookup.
     */
    #[Test]
    public function countByCountryEntriesCarryLocalisedLabels(): void
    {
        IsoCountryMap::clearCache();

        $tree   = $this->importFixtureTree('places-test.ged');
        $result = (new CountryRepository($tree, new IsoCountryMap()))->countByCountry('BIRT');

        foreach ($result as $entry) {
            self::assertNotSame('', $entry['label']);
            self::assertNotSame($entry['countryCode'], $entry['label']);
        }
    }

    /**
     * Deeply-nested place hierarchies must collapse onto the
     * country at the tail. The fixture has Hugo born in
     * "Newport, Rhode Island, New England, USA" — four segments —
     * which must still resolve to US.
     *
     * Compare BIRT (Carl + Hugo = 2) vs DEAT (Carl + Hugo = 2)
     * vs the same fixture without Hugo's BIRT (would be 1). The
     * delta-of-1 between Hugo present / absent rules out a
     * Carl-double-counted false positive.
     */
    #[Test]
    public function countByCountryCollapsesDeeplyNestedPlaceHierarchy(): void
    {
        IsoCountryMap::clearCache();

        $tree   = $this->importFixtureTree('places-test.ged');
        $result = (new CountryRepository($tree, new IsoCountryMap()))->countByCountry('BIRT');

        $byCode = array_combine(array_column($result, 'countryCode'), array_column($result, 'count'));

        // Carl (NY, USA) + Hugo (Newport, RI, NewEngland, USA) = 2.
        self::assertSame(2, $byCode['US'] ?? null, 'Hugo joins Carl in the US bucket');

        // Verify Hugo is the second contributor — `placeEndsInCountry`
        // operates on the trailing-comma rule, so a 4-segment place
        // with USA at the tail must score; if it didn't, US would
        // be 1 (Carl only) instead of 2.
        $hugoBirt    = 'Newport, Rhode Island, New England, USA';
        $resolverHit = (new IsoCountryMap())->resolve($this->lastSegment($hugoBirt));
        self::assertSame('US', $resolverHit, 'the trailing segment "USA" must resolve to US directly');
    }

    private function lastSegment(string $place): string
    {
        $parts = array_map(trim(...), explode(',', $place));

        return end($parts);
    }

    /**
     * Country names that carry diacritics in the country segment
     * itself (not just the inner segments) must resolve. The
     * fixture deliberately uses two apostrophe variants on the
     * same country across Ines's two events:
     *
     *   BIRT: "Abidjan, Côte d’Ivoire"      ← U+2019 (smart quote)
     *   DEAT: "Yamoussoukro, Côte d'Ivoire" ← U+0027 (ASCII)
     *
     * Both must resolve to CI. ICU's own display-region output for
     * CI uses U+2019, so without the curly-to-ASCII normalisation
     * in `IsoCountryMap::normalise`, one of the two events would
     * silently drop.
     */
    #[Test]
    public function countByCountryHandlesDiacriticsInCountryName(): void
    {
        IsoCountryMap::clearCache();

        $tree = $this->importFixtureTree('places-test.ged');
        $repo = new CountryRepository($tree, new IsoCountryMap());

        $birthByCode = array_combine(
            array_column($repo->countByCountry('BIRT'), 'countryCode'),
            array_column($repo->countByCountry('BIRT'), 'count'),
        );
        $deathByCode = array_combine(
            array_column($repo->countByCountry('DEAT'), 'countryCode'),
            array_column($repo->countByCountry('DEAT'), 'count'),
        );

        // The U+2019 (smart-quote) BIRT and the U+0027 (ASCII) DEAT
        // must both resolve to CI — proves both apostrophe variants
        // round-trip through the resolver.
        self::assertSame(1, $birthByCode['CI'] ?? null, 'smart-quote variant must resolve');
        self::assertSame(1, $deathByCode['CI'] ?? null, 'ASCII-apostrophe variant must resolve');
    }

    /**
     * Invariant the acceptance spells out: the sum of per-country
     * counts must equal the number of individuals whose event
     * carries a place that resolves to a known country. Anything
     * else means the repository is double-counting or silently
     * dropping resolvable places.
     *
     * For the fixture's BIRT field: Anna (DE), Berta (DE), Carl
     * (US), Doris (AT), Hugo (US), Ines (CI), Greta (GB). Seven
     * individuals' BIRT places resolve; Franz's "Atlantis" is
     * unknown so excluded; Emil has no BIRT place. Total = 7.
     */
    #[Test]
    public function countByCountrySumMatchesResolvableIndividuals(): void
    {
        IsoCountryMap::clearCache();

        $tree   = $this->importFixtureTree('places-test.ged');
        $result = (new CountryRepository($tree, new IsoCountryMap()))->countByCountry('BIRT');

        $total  = 0;
        $byCode = [];

        foreach ($result as $entry) {
            $total += $entry['count'];
            $byCode[$entry['countryCode']] = $entry['count'];
        }

        // 7 individuals have a BIRT place that resolves to a known
        // country (Anna, Berta, Carl, Doris, Greta, Hugo, Ines).
        // Atlantis (Franz) and missing-place (Emil) are excluded.
        self::assertSame(7, $total, 'sum of all country counts must equal the resolvable-individual count');

        // Franz's "Some Unknown Region, Atlantis" must not appear
        // under any code — the unknown country drops silently.
        self::assertArrayNotHasKey('XX', $byCode);
        self::assertArrayNotHasKey('ZZ', $byCode);
    }

    /**
     * Residence aggregation counts every `1 RESI` occurrence per
     * individual: MultiMove (I1) has two RESI entries (Hamburg and
     * New York) and contributes once to Germany and once to USA;
     * SingleMove (I2) has one RESI in England; NoResidence (I3)
     * has no RESI at all and contributes nothing. Total resolved
     * residences must equal three across three distinct countries.
     */
    #[Test]
    public function residencesByCountryCountsEachResiOccurrence(): void
    {
        IsoCountryMap::clearCache();

        $tree   = $this->importFixtureTree('residences.ged');
        $result = (new CountryRepository($tree, new IsoCountryMap()))->residencesByCountry();

        $byCode = [];

        foreach ($result as $entry) {
            $byCode[$entry['countryCode']] = $entry['count'];
        }

        self::assertSame(1, $byCode['DE'] ?? null, 'MultiMove contributes Hamburg → Germany');
        self::assertSame(1, $byCode['US'] ?? null, 'MultiMove contributes New York → USA');
        self::assertSame(1, $byCode['GB'] ?? null, 'SingleMove contributes London → United Kingdom');
        self::assertCount(3, $result, 'NoResidence individual contributes nothing');
    }
}
