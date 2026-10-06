<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use Fisharebest\Localization\Locale;
use Fisharebest\Localization\Translation;
use Fisharebest\Localization\Translator;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function basename;
use function count;
use function dirname;
use function explode;
use function file_get_contents;
use function glob;
use function implode;
use function in_array;
use function is_file;
use function ksort;
use function preg_match;
use function preg_match_all;
use function sort;
use function sprintf;
use function str_contains;
use function str_replace;
use function stripcslashes;
use function strpos;
use function substr;

use const PREG_SET_ORDER;

/**
 * Locks the structure of the shipped translation catalogues.
 *
 * webtrees merges the compiled catalogue of a module over its own, so an entry that
 * webtrees cannot use does not just degrade the module. A plural entry with the wrong
 * number of forms makes webtrees fall back to English, and one with an empty form
 * renders an empty string for every number that selects that form. Because the entry
 * replaces the webtrees translation of the same text, the damage reaches pages that
 * have nothing to do with this module.
 *
 * The expected number of forms is taken from the plural rule class webtrees itself
 * uses for the locale, never from the PO header, which webtrees does not read.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class CatalogueStructureTest extends TestCase
{
    /**
     * Separator gettext uses between the singular and the plural msgid in a key and
     * between the plural forms of one translation.
     */
    private const string PLURAL_SEPARATOR = "\x00";

    /**
     * Highest number rendered for every plural entry. It covers every residue class
     * of the plural rules shipped for the locales, including the teens that the Slavic
     * rules treat separately.
     */
    private const int HIGHEST_NUMBER = 200;

    /**
     * Provides every locale the module ships a catalogue for.
     *
     * @return array<string, array{string}>
     */
    public static function shippedLocales(): array
    {
        $locales = [];

        foreach (self::localesWithFile('messages.po') as $locale) {
            $locales[$locale] = [$locale];
        }

        return $locales;
    }

    /**
     * Every locale has both its source and its compiled catalogue. A locale that lost its
     * source drops out of the data provider and would no longer be checked, an orphaned
     * compiled file would ship unchecked, and an empty provider would check nothing.
     */
    #[Test]
    public function everyLocaleHasItsSourceAndItsCompiledCatalogue(): void
    {
        $sources = self::localesWithFile('messages.po');

        self::assertNotSame([], $sources);
        self::assertSame($sources, self::localesWithFile('messages.mo'));
    }

    /**
     * The PO header of a locale declares the plural rule the translation tools use. The
     * declaration must be complete, because a rule split by other header lines is read
     * wrongly by tools that evaluate it, and its form count must agree with the
     * rule webtrees applies, otherwise a translator is asked for the wrong number of
     * forms and the file is wrong from the start.
     */
    #[Test]
    #[DataProvider('shippedLocales')]
    public function headerDeclaresThePluralRuleOfWebtrees(string $locale): void
    {
        $found = preg_match(
            '/^Plural-Forms: nplurals=(\d+); plural=[^\n]+;$/m',
            $this->headerText($locale),
            $matches,
        );

        self::assertSame(
            1,
            $found,
            sprintf('%s: the header carries no complete Plural-Forms line', $locale),
        );

        self::assertSame(
            $this->pluralRuleFormCount($locale),
            (int) $matches[1],
            sprintf('%s: nplurals in the header differs from the plural rule of webtrees', $locale),
        );
    }

    /**
     * Every plural entry of the compiled catalogue, the file webtrees actually loads,
     * must carry exactly as many forms as the plural rule of the locale selects from.
     * With a different count webtrees ignores the entry and shows English, and the
     * entry still hides the webtrees translation of the same text.
     */
    #[Test]
    #[DataProvider('shippedLocales')]
    public function pluralEntriesCarryExactlyTheFormsOfThePluralRule(string $locale): void
    {
        $expected  = $this->pluralRuleFormCount($locale);
        $offenders = [];

        foreach ($this->pluralEntries($this->compiledCatalogue($locale)) as $msgid => $forms) {
            if (count($forms) !== $expected) {
                $offenders[] = sprintf('%s (%d forms)', $msgid, count($forms));
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                '%s needs %d plural forms per entry: %s',
                $locale,
                $expected,
                implode(' | ', $offenders),
            ),
        );
    }

    /**
     * The tests that scan the plural entries of the compiled catalogues pass on an empty
     * scan. At least one compiled catalogue therefore has to carry a plural entry, which
     * fails loudly when the reader stops recognising plural keys.
     */
    #[Test]
    public function compiledCataloguesCarryPluralEntries(): void
    {
        $found = 0;

        foreach (self::localesWithFile('messages.mo') as $locale) {
            $found += count($this->pluralEntries($this->compiledCatalogue($locale)));
        }

        self::assertGreaterThan(
            0,
            $found,
            'No compiled catalogue carries a plural entry, so the plural checks would scan nothing',
        );
    }

    /**
     * The compiler leaves out an entry without any translation, so the compiled catalogue
     * cannot tell how many slots such an entry has in the source. The slot count can
     * therefore only be checked in the source file.
     */
    #[Test]
    #[DataProvider('shippedLocales')]
    public function sourcePluralEntriesHaveTheSlotsOfThePluralRule(string $locale): void
    {
        $expected = $this->pluralRuleFormCount($locale);
        $source   = $this->poSource($locale);

        $pattern = '/^msgid_plural (.*)\n(?:".*\n)*((?:msgstr\[\d+\] .*\n(?:".*\n)*)+)/m';
        $found   = preg_match_all($pattern, $source, $entries, PREG_SET_ORDER);

        self::assertGreaterThan(
            0,
            $found,
            sprintf('%s: the source catalogue has no plural entry', $locale),
        );
        self::assertSame(
            preg_match_all('/^msgid_plural /m', $source),
            $found,
            sprintf('%s: a plural entry of the source catalogue was not read', $locale),
        );

        $offenders = [];

        foreach ($entries as $entry) {
            $slots = preg_match_all('/^msgstr\[\d+\]/m', $entry[2]);

            if ($slots !== $expected) {
                $offenders[] = sprintf('%s (%d slots)', $entry[1], $slots);
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                '%s needs %d plural slots per entry: %s',
                $locale,
                $expected,
                implode(' | ', $offenders),
            ),
        );
    }

    /**
     * An empty plural form is kept by the compiler as soon as another form is filled,
     * and webtrees returns it unchanged. The number that selects it would render as
     * nothing.
     */
    #[Test]
    #[DataProvider('shippedLocales')]
    public function noPluralFormIsEmpty(string $locale): void
    {
        $offenders = [];

        foreach ($this->pluralEntries($this->compiledCatalogue($locale)) as $msgid => $forms) {
            if (in_array('', $forms, true)) {
                $offenders[] = $msgid;
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                '%s has plural entries with an empty form: %s',
                $locale,
                implode(' | ', $offenders),
            ),
        );
    }

    /**
     * The compiled catalogue is committed next to its source. A catalogue compiled
     * from an older source would let the checks above pass on stale data. The two
     * readers return the entries in different order, which carries no meaning. The PO
     * reader keeps an entry marked fuzzy in the source, while the compiler leaves it out
     * of the compiled file, so such an entry shows up here as a mismatch until its flag
     * is resolved.
     */
    #[Test]
    #[DataProvider('shippedLocales')]
    public function compiledCatalogueMatchesTheSourceCatalogue(string $locale): void
    {
        $source   = (new Translation($this->poFile($locale)))->asArray();
        $compiled = $this->compiledCatalogue($locale);

        ksort($source);
        ksort($compiled);

        self::assertSame(
            $source,
            $compiled,
            sprintf(
                '%s: messages.mo is out of date, run `make lang`, or the source holds a fuzzy entry',
                $locale,
            ),
        );
    }

    /**
     * Renders every plural entry the way webtrees does, for every number up to the
     * highest one. A form that is empty shows up as an empty result for the numbers
     * that select it. A wrong form count is not seen here, because webtrees then shows
     * the English text, which the form count test above covers.
     */
    #[Test]
    #[DataProvider('shippedLocales')]
    public function everyPluralEntryRendersForEveryNumber(string $locale): void
    {
        $catalogue  = $this->compiledCatalogue($locale);
        $translator = new Translator($catalogue, Locale::create($locale)->pluralRule());
        $failures   = [];

        foreach (array_keys($this->pluralEntries($catalogue)) as $key) {
            [$singular, $plural] = $this->sourceTexts($key);

            for ($number = 0; $number <= self::HIGHEST_NUMBER; ++$number) {
                if ($translator->translatePlural($singular, $plural, $number) === '') {
                    $failures[] = sprintf('%s (n=%d)', $singular, $number);

                    break;
                }
            }
        }

        self::assertSame(
            [],
            $failures,
            sprintf(
                '%s renders nothing for: %s',
                $locale,
                implode(' | ', $failures),
            ),
        );
    }

    /**
     * A form that drops or invents a placeholder breaks the sentence it is formatted
     * into. Every form must use the placeholders of the singular or of the plural
     * source text.
     */
    #[Test]
    #[DataProvider('shippedLocales')]
    public function pluralFormsKeepThePlaceholdersOfTheSource(string $locale): void
    {
        $offenders = [];

        foreach ($this->pluralEntries($this->compiledCatalogue($locale)) as $key => $forms) {
            [$singular, $plural] = $this->sourceTexts($key);

            $allowed = [
                $this->placeholders($singular),
                $this->placeholders($plural),
            ];

            foreach ($forms as $index => $form) {
                if (!in_array($this->placeholders($form), $allowed, true)) {
                    $offenders[] = sprintf('%s [%d]', $singular, $index);
                }
            }
        }

        self::assertSame(
            [],
            $offenders,
            sprintf(
                '%s has plural forms with other placeholders than the source: %s',
                $locale,
                implode(' | ', $offenders),
            ),
        );
    }

    /**
     * Returns the header of the source catalogue of a locale as plain text, with the
     * quoted lines of the header joined and unescaped the way a PO reader sees them.
     */
    private function headerText(string $locale): string
    {
        $source  = $this->poSource($locale);
        $pattern = '/^msgid ""\nmsgstr ""\n((?:"(?:[^"\\\\]|\\\\.)*"\n)+)/m';
        $found   = preg_match($pattern, $source, $block);

        self::assertSame(
            1,
            $found,
            sprintf('%s: the catalogue carries no header', $locale),
        );

        preg_match_all('/"((?:[^"\\\\]|\\\\.)*)"/', $block[1], $lines);

        return stripcslashes(implode('', $lines[1]));
    }

    /**
     * Lists the locales that have the named catalogue file, sorted.
     *
     * @return list<string>
     */
    public static function localesWithFile(string $file): array
    {
        $paths = glob(self::languageDirectory() . '/*/' . $file);
        $found = [];

        foreach ($paths === false ? [] : $paths as $path) {
            $found[] = basename(dirname($path));
        }

        sort($found);

        return $found;
    }

    /**
     * Returns the plural entries of a catalogue, keyed by the singular and plural
     * source text joined by the plural separator, with the forms split into a list.
     *
     * @param array<string, string> $catalogue
     *
     * @return array<string, list<string>>
     */
    private function pluralEntries(array $catalogue): array
    {
        $entries = [];

        foreach ($catalogue as $key => $translation) {
            if (str_contains((string) $key, self::PLURAL_SEPARATOR)) {
                $entries[(string) $key] = explode(self::PLURAL_SEPARATOR, $translation);
            }
        }

        return $entries;
    }

    /**
     * Returns the placeholders of a text, sorted so that their position in the sentence
     * does not matter. A doubled percent sign is a literal one and not a placeholder.
     *
     * @return list<string>
     */
    private function placeholders(string $text): array
    {
        $text = str_replace('%%', '', $text);

        preg_match_all('/%(?:\d+\$)?[sd]/', $text, $matches);

        $placeholders = $matches[0];

        sort($placeholders);

        return $placeholders;
    }

    /**
     * Reads the compiled catalogue webtrees loads for a locale.
     *
     * @return array<string, string>
     */
    private function compiledCatalogue(string $locale): array
    {
        $moFile = self::languageDirectory() . '/' . $locale . '/messages.mo';

        self::assertTrue(
            is_file($moFile),
            sprintf('%s: messages.mo is missing', $locale),
        );

        $translations = (new Translation($moFile))->asArray();

        /** @var array<string, string> $translations */
        return $translations;
    }

    /**
     * Splits the key of a plural entry into the singular and the plural source text.
     *
     * @return array{string, string}
     */
    private function sourceTexts(string $key): array
    {
        $position = strpos($key, self::PLURAL_SEPARATOR);

        self::assertIsInt(
            $position,
            'A plural entry key joins both source texts by the plural separator',
        );

        return [substr($key, 0, $position), substr($key, $position + 1)];
    }

    /**
     * Returns the number of plural forms webtrees selects from for a locale.
     */
    private function pluralRuleFormCount(string $locale): int
    {
        return Locale::create($locale)->pluralRule()->plurals();
    }

    /**
     * Returns the path of the source catalogue of a locale.
     */
    private function poFile(string $locale): string
    {
        return self::languageDirectory() . '/' . $locale . '/messages.po';
    }

    /**
     * Returns the raw text of the source catalogue of a locale.
     */
    private function poSource(string $locale): string
    {
        $source = file_get_contents($this->poFile($locale));

        self::assertIsString(
            $source,
            sprintf('%s: the source catalogue cannot be read', $locale),
        );

        return $source;
    }

    /**
     * Returns the directory that holds the catalogues of all locales.
     */
    public static function languageDirectory(): string
    {
        return __DIR__ . '/../resources/lang';
    }
}
