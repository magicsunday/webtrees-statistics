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
use function glob;
use function sprintf;
use function str_starts_with;

/**
 * Pins where the century ordinals live in the compiled catalogues. webtrees
 * merges a module catalogue over its own, so an entry under the core context
 * `CENTURY` replaces the century labels of the whole installation, not only
 * those of this module. The module keeps its plain-text ordinals ("10ᵉ" where
 * core writes markup, "10." where core uses Roman numerals) under a context of
 * its own, so the core wording stays untouched.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class CenturyOrdinalCatalogueTest extends TestCase
{
    /**
     * The module's own context for the century ordinals, written as the
     * literal `CenturyName` passes to `I18N::translateContext()`.
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
     * Every ordinal from the 1st to the 21st century is translated under the
     * module's own context. An ordinal missing there renders the English text.
     */
    #[Test]
    #[DataProvider('catalogueProvider')]
    public function everyOrdinalIsTranslatedUnderTheOwnContext(string $file): void
    {
        $translations = (new Translation($file))->asArray();

        foreach ($this->ordinals() as $ordinal) {
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
     * The ordinal source texts `CenturyName` translates, in century order.
     *
     * @return list<string>
     */
    private function ordinals(): array
    {
        return [
            '1st', '2nd', '3rd', '4th', '5th', '6th', '7th', '8th', '9th', '10th', '11th',
            '12th', '13th', '14th', '15th', '16th', '17th', '18th', '19th', '20th', '21st',
        ];
    }
}
