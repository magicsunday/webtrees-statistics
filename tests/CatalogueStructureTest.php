<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use Fisharebest\Localization\Translation;
use MagicSunday\Webtrees\ModuleBase\Testing\AbstractCatalogueStructureTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

use function array_filter;
use function preg_match_all;
use function sort;
use function str_contains;

use const ARRAY_FILTER_USE_BOTH;

/**
 * Locks the structure of the shipped translation catalogues. The checks live in the
 * shared abstract case of webtrees-module-base, this class only names the directory
 * that holds the catalogues of this module.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class CatalogueStructureTest extends AbstractCatalogueStructureTestCase
{
    /**
     * A translation of a singular text that drops or invents a placeholder loses
     * the value that is formatted into it. The shared case compares only the
     * plural forms, so the singular entries are compared here.
     *
     * @param string $locale The name of the locale directory
     */
    #[Test]
    #[DataProvider('shippedLocales')]
    public function singularTranslationsKeepThePlaceholdersOfTheSource(string $locale): void
    {
        $translations = (new Translation(self::languageDirectory() . '/' . $locale . '/messages.mo'))->asArray();

        $offenders = array_filter(
            $translations,
            static function (string $translation, string $source): bool {
                // A plural entry holds the separator of its forms, the shared case checks it.
                if (str_contains($source, Translation::PLURAL_SEPARATOR)) {
                    return false;
                }

                return self::placeholdersOf($source) !== self::placeholdersOf($translation);
            },
            ARRAY_FILTER_USE_BOTH,
        );

        self::assertSame([], $offenders);
    }

    /**
     * The string and integer placeholders of a text in a stable order.
     *
     * @param string $text The text
     *
     * @return list<string> The placeholders
     */
    private static function placeholdersOf(string $text): array
    {
        preg_match_all('/%(?:\d+\$)?[sd]/', $text, $matches);

        $placeholders = $matches[0];
        sort($placeholders);

        return $placeholders;
    }

    /**
     * Returns the directory that holds the catalogues of all locales.
     *
     * @return string The path of the catalogue directory
     */
    protected static function languageDirectory(): string
    {
        return __DIR__ . '/../resources/lang';
    }
}
