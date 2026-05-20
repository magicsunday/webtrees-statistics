<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use Locale;

use function array_unique;
use function preg_replace;
use function strtolower;
use function trim;

/**
 * Maps free-text country names from GEDCOM PLAC lines to ISO-3166-1
 * alpha-2 codes (e.g. "Germany" → "DE", "Deutschland" → "DE").
 *
 * Built on PHP's intl extension (Locale::getDisplayRegion) which
 * provides the localised country-name dictionary that ships with
 * ICU. The resolver tries a small set of widely-used locales
 * (English, German, French, Spanish, Italian, Dutch, Portuguese,
 * Polish, Russian) plus the user's current webtrees locale, then
 * caches the inverse lookup in a static map so repeat queries
 * remain O(1).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class IsoCountryMap
{
    /**
     * Locales whose ICU display-region names get folded into the
     * reverse lookup map. Covers the major Latin-script languages
     * used in published genealogy software.
     *
     * @var list<string>
     */
    private const array PRESEED_LOCALES = [
        'en_US',
        'de_DE',
        'fr_FR',
        'es_ES',
        'it_IT',
        'nl_NL',
        'pt_PT',
        'pl_PL',
        'ru_RU',
    ];

    /**
     * ISO-3166-1 alpha-2 country codes. Sourced from the published
     * ISO standard; superseded codes (e.g. "SU" for the Soviet
     * Union) are intentionally excluded — the world-map widget
     * renders only present-day territories.
     *
     * @var list<string>
     */
    private const array ISO2_CODES = [
        'AD', 'AE', 'AF', 'AG', 'AI', 'AL', 'AM', 'AO', 'AQ', 'AR',
        'AS', 'AT', 'AU', 'AW', 'AX', 'AZ', 'BA', 'BB', 'BD', 'BE',
        'BF', 'BG', 'BH', 'BI', 'BJ', 'BL', 'BM', 'BN', 'BO', 'BQ',
        'BR', 'BS', 'BT', 'BV', 'BW', 'BY', 'BZ', 'CA', 'CC', 'CD',
        'CF', 'CG', 'CH', 'CI', 'CK', 'CL', 'CM', 'CN', 'CO', 'CR',
        'CU', 'CV', 'CW', 'CX', 'CY', 'CZ', 'DE', 'DJ', 'DK', 'DM',
        'DO', 'DZ', 'EC', 'EE', 'EG', 'EH', 'ER', 'ES', 'ET', 'FI',
        'FJ', 'FK', 'FM', 'FO', 'FR', 'GA', 'GB', 'GD', 'GE', 'GF',
        'GG', 'GH', 'GI', 'GL', 'GM', 'GN', 'GP', 'GQ', 'GR', 'GS',
        'GT', 'GU', 'GW', 'GY', 'HK', 'HM', 'HN', 'HR', 'HT', 'HU',
        'ID', 'IE', 'IL', 'IM', 'IN', 'IO', 'IQ', 'IR', 'IS', 'IT',
        'JE', 'JM', 'JO', 'JP', 'KE', 'KG', 'KH', 'KI', 'KM', 'KN',
        'KP', 'KR', 'KW', 'KY', 'KZ', 'LA', 'LB', 'LC', 'LI', 'LK',
        'LR', 'LS', 'LT', 'LU', 'LV', 'LY', 'MA', 'MC', 'MD', 'ME',
        'MF', 'MG', 'MH', 'MK', 'ML', 'MM', 'MN', 'MO', 'MP', 'MQ',
        'MR', 'MS', 'MT', 'MU', 'MV', 'MW', 'MX', 'MY', 'MZ', 'NA',
        'NC', 'NE', 'NF', 'NG', 'NI', 'NL', 'NO', 'NP', 'NR', 'NU',
        'NZ', 'OM', 'PA', 'PE', 'PF', 'PG', 'PH', 'PK', 'PL', 'PM',
        'PN', 'PR', 'PS', 'PT', 'PW', 'PY', 'QA', 'RE', 'RO', 'RS',
        'RU', 'RW', 'SA', 'SB', 'SC', 'SD', 'SE', 'SG', 'SH', 'SI',
        'SJ', 'SK', 'SL', 'SM', 'SN', 'SO', 'SR', 'SS', 'ST', 'SV',
        'SX', 'SY', 'SZ', 'TC', 'TD', 'TF', 'TG', 'TH', 'TJ', 'TK',
        'TL', 'TM', 'TN', 'TO', 'TR', 'TT', 'TV', 'TW', 'TZ', 'UA',
        'UG', 'UM', 'US', 'UY', 'UZ', 'VA', 'VC', 'VE', 'VG', 'VI',
        'VN', 'VU', 'WF', 'WS', 'YE', 'YT', 'ZA', 'ZM', 'ZW',
    ];

    /**
     * Common abbreviations and informal names that GEDCOM authors
     * stamp into PLAC fields. Mapped to their ISO-3166-1 alpha-2
     * codes so the resolver catches them even though ICU's
     * display-region names don't include these forms.
     *
     * @var array<string, string>
     */
    private const array MANUAL_ALIASES = [
        'usa'                      => 'US',
        'u.s.a'                    => 'US',
        'u s a'                    => 'US',
        'united states of america' => 'US',
        'us'                       => 'US',
        'uk'                       => 'GB',
        'u.k'                      => 'GB',
        'great britain'            => 'GB',
        'england'                  => 'GB',
        'scotland'                 => 'GB',
        'wales'                    => 'GB',
        'northern ireland'         => 'GB',
        'uae'                      => 'AE',
        'ussr'                     => 'RU',
        'czechoslovakia'           => 'CZ',
        'deutschland'              => 'DE',
        'österreich'               => 'AT',
        'oesterreich'              => 'AT',
        'schweiz'                  => 'CH',
    ];

    /**
     * Cached reverse lookup: normalised country name → ISO-2 code.
     * Populated lazily on first resolve() call and shared across
     * every instance.
     *
     * @var array<string, string>|null
     */
    private static ?array $reverseLookup = null;

    /**
     * @param string $userLocale Optional extra locale (typically the active webtrees locale) folded into the reverse lookup.
     */
    public function __construct(
        private readonly string $userLocale = '',
    ) {
    }

    /**
     * Resolve a free-text country name to its ISO-3166-1 alpha-2
     * code. Returns null when the name doesn't match any known
     * country for any of the pre-seeded locales.
     *
     * @param string $name Raw place segment (e.g. "Germany", "Deutschland", "Allemagne")
     */
    public function resolve(string $name): ?string
    {
        $normalised = $this->normalise($name);

        if ($normalised === '') {
            return null;
        }

        return $this->lookupMap()[$normalised] ?? null;
    }

    /**
     * Localised display name for a given ISO-3166-1 alpha-2 code in
     * the resolver's user locale (or English when the user locale
     * is empty). Falls back to the raw ISO code if the locale
     * doesn't recognise the code.
     *
     * @param string $iso2 Alpha-2 country code (case-insensitive).
     */
    public function label(string $iso2): string
    {
        $locale = $this->userLocale !== '' ? $this->userLocale : 'en_US';
        $name   = (string) Locale::getDisplayRegion('-' . $iso2, $locale);

        return ($name !== '' && $name !== $iso2) ? $name : $iso2;
    }

    /**
     * Test-only: clear the static reverse-lookup cache so each test
     * starts from a clean slate. Not part of the public API.
     *
     * @internal
     */
    public static function clearCache(): void
    {
        self::$reverseLookup = null;
    }

    /**
     * @return list<string>
     */
    public static function supportedIso2Codes(): array
    {
        return self::ISO2_CODES;
    }

    /**
     * Build the reverse name → ISO-2 lookup map, combining every
     * pre-seeded locale plus the user's current locale.
     *
     * @return array<string, string>
     */
    private function lookupMap(): array
    {
        if (self::$reverseLookup === null) {
            self::$reverseLookup = $this->buildReverseLookup();
        }

        return self::$reverseLookup;
    }

    /**
     * @return array<string, string>
     */
    private function buildReverseLookup(): array
    {
        $locales = $this->userLocale !== ''
            ? array_unique([...self::PRESEED_LOCALES, $this->userLocale])
            : self::PRESEED_LOCALES;

        $map = [];

        foreach (self::ISO2_CODES as $iso2) {
            foreach ($locales as $locale) {
                $name = (string) Locale::getDisplayRegion('-' . $iso2, $locale);

                if ($name === '') {
                    continue;
                }

                if ($name === $iso2) {
                    continue;
                }

                $normalised = $this->normalise($name);

                if ($normalised === '') {
                    continue;
                }

                // First locale to seed a given normalised name wins.
                $map[$normalised] ??= $iso2;
            }

            // The ISO code itself is a legitimate match — some
            // legacy GEDCOM exports stamp "DE" / "FR" directly
            // into the PLAC field.
            $map[strtolower($iso2)] ??= $iso2;
        }

        // Manual aliases (USA, UK, England, Deutschland, …) win
        // over the ICU defaults so the fixture data and most
        // real-world GEDCOMs resolve without surprises.
        foreach (self::MANUAL_ALIASES as $alias => $iso2) {
            $map[$alias] = $iso2;
        }

        return $map;
    }

    /**
     * Lowercase + collapse whitespace + strip leading/trailing dots
     * so "United States", "united states", and " USA. " all hit
     * the same lookup key.
     */
    private function normalise(string $value): string
    {
        $trimmed = trim($value, " \t\n\r\0\x0B.");

        if ($trimmed === '') {
            return '';
        }

        $lower = strtolower($trimmed);

        return (string) preg_replace('/\s+/u', ' ', $lower);
    }
}
