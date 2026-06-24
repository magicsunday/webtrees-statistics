<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Locale;

use MagicSunday\Webtrees\Statistic\Support\Locale\IsoCountryMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the apostrophe-and-diacritic normalisation contract on
 * `IsoCountryMap::resolve()`. ICU's display-region output uses the curly U+2019
 * apostrophe in names like "Côte d'Ivoire", but GEDCOM authors can stamp any of
 * six common single-quote variants. All six must fold to the same ISO-2 code.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(IsoCountryMap::class)]
final class IsoCountryMapTest extends TestCase
{
    protected function setUp(): void
    {
        IsoCountryMap::clearCache();
    }

    /**
     * Every variant of "Côte d?Ivoire" with a different single- quote /
     * modifier-letter character at the apostrophe position must resolve to CI.
     * The six characters covered are:
     *
     *   U+0027 — APOSTROPHE (ASCII)
     *   U+2019 — RIGHT SINGLE QUOTATION MARK (ICU canonical)
     *   U+2018 — LEFT SINGLE QUOTATION MARK
     *   U+02BB — MODIFIER LETTER TURNED COMMA (Hawai?i)
     *   U+02BC — MODIFIER LETTER APOSTROPHE
     *   U+201B — SINGLE HIGH-REVERSED-9 QUOTATION MARK
     *
     * @return iterable<string, array{0: string}>
     */
    public static function apostropheVariants(): iterable
    {
        yield 'ASCII U+0027' => ["Côte d\u{0027}Ivoire"];
        yield 'curly U+2019' => ["Côte d\u{2019}Ivoire"];
        yield 'left U+2018' => ["Côte d\u{2018}Ivoire"];
        yield 'okina U+02BB' => ["Côte d\u{02BB}Ivoire"];
        yield 'modifier U+02BC' => ["Côte d\u{02BC}Ivoire"];
        yield 'high-9 U+201B' => ["Côte d\u{201B}Ivoire"];
    }

    #[Test]
    #[DataProvider('apostropheVariants')]
    public function resolveFoldsEveryApostropheVariantToTheSameIso(string $name): void
    {
        self::assertSame('CI', (new IsoCountryMap())->resolve($name));
    }

    /**
     * Diacritics in the country name must round-trip without mojibake.
     * "Österreich" (German for Austria) → AT.
     */
    #[Test]
    public function resolveHandlesDiacriticsInLocalisedCountryName(): void
    {
        self::assertSame('AT', (new IsoCountryMap())->resolve('Österreich'));
    }

    /**
     * Manual aliases (USA, UK, Deutschland, …) must win over ICU's
     * display-region names so the resolver matches what GEDCOM authors actually
     * write rather than only ICU's canonical labels.
     */
    #[Test]
    public function resolveHonoursManualAliasOverIcuLabel(): void
    {
        $map = new IsoCountryMap();
        self::assertSame('US', $map->resolve('USA'));
        self::assertSame('US', $map->resolve('U.S.A'));
        self::assertSame('GB', $map->resolve('UK'));
        self::assertSame('DE', $map->resolve('Deutschland'));
    }

    /**
     * `resolve()` returns null for free-text country names that don't match any
     * locale-aware label or alias. The fixture's "Atlantis" stays unresolved
     * and the caller drops the event from the country aggregation.
     */
    #[Test]
    public function resolveReturnsNullForUnknownCountry(): void
    {
        self::assertNull((new IsoCountryMap())->resolve('Atlantis'));
    }

    /**
     * ISO-3166-1 alpha-3 country codes ("DEU", "FRA", "GBR") that GEDCOM
     * exporters stamp into the country segment must resolve to their alpha-2
     * sibling. ICU canonicalises the alpha-3 region subtag onto the same display
     * name as the alpha-2 code, so the resolver bridges through the existing
     * name lookup rather than carrying a separate 249-entry alpha-3 table.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function alpha3Codes(): iterable
    {
        yield 'Germany DEU' => ['DEU', 'DE'];
        yield 'France FRA' => ['FRA', 'FR'];
        yield 'United Kingdom GBR' => ['GBR', 'GB'];
        yield 'Switzerland CHE' => ['CHE', 'CH'];
        yield 'Austria AUT' => ['AUT', 'AT'];
        yield 'Côte d’Ivoire CIV' => ['CIV', 'CI'];
        yield 'lower-case deu' => ['deu', 'DE'];
        yield 'whitespace-padded fra' => [' FRA ', 'FR'];
    }

    /**
     * @param string $alpha3 Raw alpha-3 country segment
     * @param string $iso2   Expected ISO-3166-1 alpha-2 code
     */
    #[Test]
    #[DataProvider('alpha3Codes')]
    public function resolveAcceptsIsoAlpha3CountryCodes(string $alpha3, string $iso2): void
    {
        self::assertSame($iso2, (new IsoCountryMap())->resolve($alpha3));
    }

    /**
     * The reporter's exact place string (issue #208) — a four-segment hierarchy
     * whose country tail is the alpha-3 code "DEU" — must resolve to Germany via
     * `resolveFromPlace()`, which peels the trailing comma segment before the
     * lookup.
     */
    #[Test]
    public function resolveFromPlaceAcceptsAlpha3CountryTail(): void
    {
        self::assertSame(
            'DE',
            (new IsoCountryMap())->resolveFromPlace('Freiburg, Freiburg, Baden-Württemberg, DEU'),
        );
    }

    /**
     * A three-letter token that is not a valid ISO-3166-1 alpha-3 code must stay
     * unresolved — ICU echoes an unknown region subtag back unchanged, and the
     * resolver must treat that echo as "no match" rather than as a hit.
     */
    #[Test]
    public function resolveReturnsNullForUnknownThreeLetterToken(): void
    {
        self::assertNull((new IsoCountryMap())->resolve('XYZ'));
    }

    /**
     * The UK home-nation codes the webtrees core counts as countries
     * (CountryService::iso3166(): ENG / SCT / WLS / NIR) must fold onto GB. They
     * are Chapman / GEDCOM subdivision codes, not ISO-3166-1 alpha-3, so ICU
     * cannot resolve them — the manual alias carries them.
     *
     * @return iterable<string, array{0: string}>
     */
    public static function ukHomeNationCodes(): iterable
    {
        yield 'England ENG' => ['ENG'];
        yield 'Scotland SCT' => ['SCT'];
        yield 'Wales WLS' => ['WLS'];
        yield 'Northern Ireland NIR' => ['NIR'];
    }

    /**
     * @param string $code UK home-nation subdivision code
     */
    #[Test]
    #[DataProvider('ukHomeNationCodes')]
    public function resolveFoldsUkHomeNationCodesOntoGb(string $code): void
    {
        self::assertSame('GB', (new IsoCountryMap())->resolve($code));
    }

    /**
     * `label()` returns the active webtrees locale's name for a given ISO code.
     * With no I18N bootstrap (the test runs outside the webtrees request
     * lifecycle), the resolver falls back to en_US.
     */
    #[Test]
    public function labelReturnsEnglishNameWhenNoLocaleIsActive(): void
    {
        self::assertSame('Germany', (new IsoCountryMap())->label('DE'));
    }

    /**
     * `label()` echoes the ISO code unchanged when ICU has no display-region
     * name for the input. Protects the caller from ever seeing an empty label
     * string.
     */
    #[Test]
    public function labelEchoesUnknownIsoCodeUnchanged(): void
    {
        self::assertSame('XX', (new IsoCountryMap())->label('XX'));
    }
}
