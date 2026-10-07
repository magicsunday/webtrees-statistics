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
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_unique;
use function array_values;
use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function preg_match_all;
use function sprintf;
use function str_starts_with;

use const PREG_SET_ORDER;

/**
 * Pins where the century ordinals live. webtrees merges a module catalogue over
 * its own, so an entry under the core context `CENTURY` replaces the century
 * labels of the whole installation, not only those of this module. The module
 * keeps its plain-text ordinals ("10ᵉ" where core writes markup, "10." where core
 * uses Roman numerals) under a context of its own, so the core wording stays
 * untouched.
 *
 * The ordinals and the context are read from `CenturyName`, the one place that
 * calls `translateContext()`. The test runtime ships no non-English catalogue and
 * every context returns the source text under English, so the production code is
 * pinned through its source and the compiled catalogues through their entries.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class CenturyOrdinalCatalogueTest extends TestCase
{
    /**
     * The module's own context for the century ordinals.
     */
    private const string OWN_CONTEXT = 'century ordinal';

    /**
     * The context of the century labels in webtrees core.
     */
    private const string CORE_CONTEXT = 'CENTURY';

    /**
     * Separator gettext puts between the context and the message in a key.
     */
    private const string CONTEXT_SEPARATOR = "\x04";

    /**
     * The compiled catalogues that carry the ordinals. `en-GB` only holds the
     * British spellings and leaves the ordinals to the source text.
     *
     * @return array<string, array{string}>
     */
    public static function catalogueProvider(): array
    {
        $rows  = [];
        $files = glob(__DIR__ . '/../resources/lang/*/messages.mo');

        foreach ($files === false ? [] : $files as $file) {
            $locale = basename(dirname($file));

            if ($locale === 'en-GB') {
                continue;
            }

            $rows[$locale] = [$file];
        }

        return $rows;
    }

    /**
     * A scan that finds no catalogue would turn the catalogue tests below into no
     * cases at all, and an empty suite reads like a green one.
     */
    #[Test]
    public function theCatalogueScanFindsCatalogues(): void
    {
        self::assertNotSame([], self::catalogueProvider(), 'No compiled catalogue found under resources/lang');
    }

    /**
     * Every century label `CenturyName` translates goes through the module's own
     * context. A call under the core context would replace the century labels of
     * the whole installation, whatever the catalogues hold.
     */
    #[Test]
    public function theProductionCodeTranslatesTheOrdinalsUnderTheOwnContext(): void
    {
        $calls = $this->translateContextCalls();

        self::assertNotSame([], $calls, 'CenturyName holds no translateContext() call to pin');

        foreach ($calls as [$context, $ordinal]) {
            self::assertSame(
                self::OWN_CONTEXT,
                $context,
                sprintf('The ordinal "%s" is translated under the context "%s"', $ordinal, $context),
            );
        }
    }

    /**
     * Every ordinal `CenturyName` translates has a translation under the module's
     * own context. An ordinal missing there renders the English text.
     */
    #[Test]
    #[DataProvider('catalogueProvider')]
    public function everyOrdinalIsTranslatedUnderTheOwnContext(string $file): void
    {
        $translations = (new Translation($file))->asArray();
        $ordinals     = $this->sourceOrdinals();

        self::assertNotSame([], $ordinals, 'CenturyName holds no ordinal to look up');

        foreach ($ordinals as $ordinal) {
            $key = self::OWN_CONTEXT . self::CONTEXT_SEPARATOR . $ordinal;

            self::assertNotSame(
                '',
                $translations[$key] ?? '',
                sprintf('%s: the ordinal "%s" has no translation under the context "%s"', basename(dirname($file)), $ordinal, self::OWN_CONTEXT),
            );
        }
    }

    /**
     * No entry sits under the core context, because it would replace the century
     * labels webtrees core renders on its own pages.
     */
    #[Test]
    #[DataProvider('catalogueProvider')]
    public function noEntryShadowsTheCoreCenturyContext(string $file): void
    {
        $shadowing = [];

        foreach (array_keys((new Translation($file))->asArray()) as $key) {
            if (str_starts_with((string) $key, self::CORE_CONTEXT . self::CONTEXT_SEPARATOR)) {
                $shadowing[] = (string) $key;
            }
        }

        self::assertSame([], $shadowing, basename(dirname($file)) . ': entries shadow the core century labels');
    }

    /**
     * The ordinal source texts `CenturyName` translates, in source order.
     *
     * @return list<string>
     */
    private function sourceOrdinals(): array
    {
        $ordinals = [];

        foreach ($this->translateContextCalls() as [, $ordinal]) {
            $ordinals[] = $ordinal;
        }

        return array_values(array_unique($ordinals));
    }

    /**
     * Every `I18N::translateContext('<context>', '<text>')` call of `CenturyName`,
     * as `[context, text]` pairs.
     *
     * @return list<array{string, string}>
     */
    private function translateContextCalls(): array
    {
        $source = file_get_contents(__DIR__ . '/../src/Support/Locale/CenturyName.php');

        self::assertIsString($source);

        preg_match_all("/translateContext\\('([^']+)', '([^']+)'\\)/", $source, $matches, PREG_SET_ORDER);

        $calls = [];

        foreach ($matches as $match) {
            $calls[] = [$match[1], $match[2]];
        }

        return $calls;
    }
}
