<?php

declare(strict_types=1);

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

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
        // USA: I3 (New York) = 1
        self::assertSame(1, $byCode['US'] ?? null);
        // Austria: I4 (Wien) = 1 — German-language country name
        self::assertSame(1, $byCode['AT'] ?? null);
        // United Kingdom: I7 (London, England, UK) = 1
        self::assertSame(1, $byCode['GB'] ?? null);

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
        // USA: I3 (Boston) = 1
        self::assertSame(1, $byCode['US'] ?? null);
        // Austria: I4 (Vienna) + I5 (Salzburg) = 2
        self::assertSame(2, $byCode['AT'] ?? null);
        // United Kingdom: I7 (Edinburgh) = 1
        self::assertSame(1, $byCode['GB'] ?? null);
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
}
