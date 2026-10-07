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
use function basename;
use function dirname;
use function file_get_contents;
use function glob;
use function preg_match_all;
use function range;
use function sort;
use function str_starts_with;

use const PREG_SET_ORDER;

/**
 * Pins that the module carries no century ordinal of its own. webtrees merges a
 * module catalogue over its own, so an entry under the core context `CENTURY`
 * replaces the century labels of the whole installation, not only those of this
 * module. `CenturyName` reads the core ordinals, and the compiled catalogues
 * hold no entry under that context nor under the module context the ordinals
 * lived under before.
 *
 * The test runtime ships no non-English catalogue and every context returns the
 * source text under English, so the production code is pinned through its
 * source and the compiled catalogues through their entries.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class CenturyOrdinalCatalogueTest extends TestCase
{
    /**
     * The context of the century labels in webtrees core.
     */
    private const string CORE_CONTEXT = 'CENTURY';

    /**
     * The module context the century ordinals lived under before the core ones were used.
     */
    private const string FORMER_CONTEXT = 'century ordinal';

    /**
     * Separator gettext puts between the context and the message in a key.
     */
    private const string CONTEXT_SEPARATOR = "\x04";

    /**
     * The compiled catalogues of the module.
     *
     * @return array<string, array{string}>
     */
    public static function catalogueProvider(): array
    {
        $rows  = [];
        $files = glob(__DIR__ . '/../resources/lang/*/messages.mo');

        foreach ($files === false ? [] : $files as $file) {
            $rows[basename(dirname($file))] = [$file];
        }

        return $rows;
    }

    /**
     * A scan that finds no catalogue would turn the catalogue test below into no
     * cases at all, and an empty suite reads like a green one.
     */
    #[Test]
    public function theCatalogueScanFindsCatalogues(): void
    {
        self::assertNotSame([], self::catalogueProvider(), 'No compiled catalogue found under resources/lang');
    }

    /**
     * Every century ordinal `CenturyName` translates goes through the core
     * context, and none is left out. The check names the exact set of ordinals, so
     * a single arm that falls back to a plain translation or to another context
     * turns the test red, where a test that only validates the calls it finds
     * would stay green on the other arms. A module context would show the English
     * ordinal in every language the module ships no catalogue for.
     */
    #[Test]
    public function theProductionCodeTranslatesEveryOrdinalUnderTheCoreContext(): void
    {
        $expected = [];

        foreach (range(1, 21) as $century) {
            $expected[] = [self::CORE_CONTEXT, $century . $this->englishSuffix($century)];
        }

        $calls = $this->translateContextCalls();

        sort($calls);
        sort($expected);

        self::assertSame($expected, $calls, 'CenturyName must translate the ordinals 1st to 21st under the core context');
    }

    /**
     * The English ordinal suffix of a century number from 1 to 21.
     *
     * @param int $number The century number
     */
    private function englishSuffix(int $number): string
    {
        return match (true) {
            ($number >= 4) && ($number <= 20) => 'th',
            ($number % 10) === 1              => 'st',
            ($number % 10) === 2              => 'nd',
            ($number % 10) === 3              => 'rd',
            default                           => 'th',
        };
    }

    /**
     * No entry sits under the core context, because it would replace the century
     * labels webtrees core renders on its own pages, and none sits under the
     * former module context, because nothing reads it any more.
     *
     * @param string $file The path of the compiled catalogue under test
     */
    #[Test]
    #[DataProvider('catalogueProvider')]
    public function noEntryHoldsACenturyOrdinal(string $file): void
    {
        $found = [];

        foreach (array_keys((new Translation($file))->asArray()) as $key) {
            foreach ([self::CORE_CONTEXT, self::FORMER_CONTEXT] as $context) {
                if (str_starts_with((string) $key, $context . self::CONTEXT_SEPARATOR)) {
                    $found[] = (string) $key;
                }
            }
        }

        self::assertSame([], $found, basename(dirname($file)) . ': entries hold century ordinals');
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
