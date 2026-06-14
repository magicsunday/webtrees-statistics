<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Gedcom;

use MagicSunday\Webtrees\Statistic\Support\Gedcom\GivenNameNormalizer;
use Normalizer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function normalizer_normalize;

/**
 * Locks the Level-1 given-name normalisation contract: a single source of truth
 * for splitting a `n_givn` value into display tokens (NFC, particle/initial
 * stripped) and for deriving the case- and diacritics-folded grouping key that
 * collapses spelling variants of the same name.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(GivenNameNormalizer::class)]
final class GivenNameNormalizerTest extends TestCase
{
    /**
     * Spelling variants that differ only by diacritics, special Latin letters
     * or case must fold to the same grouping key, so the frequency tables count
     * them once.
     *
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function foldKeyVariants(): iterable
    {
        yield 'diacritic é vs e' => ['José', 'jose'];
        yield 'plain Jose' => ['Jose', 'jose'];
        yield 'special letter Ł vs L' => ['Łukasz', 'lukasz'];
        yield 'plain Lukasz' => ['Lukasz', 'lukasz'];
        yield 'accented í' => ['Sofía', 'sofia'];
        yield 'plain Sofia' => ['Sofia', 'sofia'];
        yield 'umlaut ü' => ['Jürgen', 'jurgen'];
        yield 'case only upper' => ['Anna', 'anna'];
        yield 'case only lower' => ['anna', 'anna'];
    }

    /**
     * @param non-empty-string $token
     */
    #[Test]
    #[DataProvider('foldKeyVariants')]
    public function foldKeyFoldsDiacriticsSpecialLettersAndCase(string $token, string $expected): void
    {
        self::assertSame($expected, GivenNameNormalizer::foldKey($token));
    }

    /**
     * Composed and decomposed spellings of the same character must fold
     * identically — the NFC step makes a binary-different but visually equal
     * name match.
     */
    #[Test]
    public function foldKeyIsUnicodeFormIndependent(): void
    {
        $composed   = "Jos\u{00E9}";          // José (precomposed é)
        $decomposed = "Jose\u{0301}";          // José (e + combining acute)

        self::assertSame(
            GivenNameNormalizer::foldKey($composed),
            GivenNameNormalizer::foldKey($decomposed),
        );
    }

    /**
     * `tokens()` splits on Unicode whitespace, drops initials and short
     * particles (matching webtrees core's `commonGivenNames` regex), and keeps
     * the readable display form (no case/diacritics folding).
     *
     * @return iterable<string, array{0: string, 1: list<string>}>
     */
    public static function tokenCases(): iterable
    {
        yield 'two tokens' => ['Anna Maria', ['Anna', 'Maria']];
        yield 'single capital initial dropped' => ['José M', ['José']];
        yield 'lowercase particle dropped' => ['Hans von', ['Hans']];
        yield 'tab and repeated spaces' => ["Anna\t  Maria", ['Anna', 'Maria']];
        yield 'unknown-name placeholder collapses' => ['@P.N.', []];
        yield 'empty string' => ['', []];
        yield 'whitespace only' => ['   ', []];
    }

    /**
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('tokenCases')]
    public function tokensSplitsStripsParticlesAndKeepsDisplayForm(string $givn, array $expected): void
    {
        self::assertSame($expected, GivenNameNormalizer::tokens($givn));
    }

    /**
     * The returned display tokens are NFC-normalised, so a decomposed source
     * spelling is emitted in its composed (canonical) form for the label.
     */
    #[Test]
    public function tokensNormaliseDisplayFormToNfc(): void
    {
        $decomposed = "Jose\u{0301} Maria";   // José Maria with combining acute

        self::assertSame(
            [normalizer_normalize('José', Normalizer::FORM_C), 'Maria'],
            GivenNameNormalizer::tokens($decomposed),
        );
    }

    /**
     * `dominantForm()` picks the display label for a folded group: the most
     * frequent raw spelling wins.
     */
    #[Test]
    public function dominantFormPicksTheMostFrequentRawSpelling(): void
    {
        self::assertSame('José', GivenNameNormalizer::dominantForm(['Jose' => 1, 'José' => 2]));
    }

    /**
     * Equal-frequency spellings break the tie alphabetically so the label is
     * deterministic across database engines (uppercase sorts before lowercase,
     * so "Anna" beats "anna").
     */
    #[Test]
    public function dominantFormBreaksFrequencyTiesAlphabetically(): void
    {
        self::assertSame('Anna', GivenNameNormalizer::dominantForm(['anna' => 2, 'Anna' => 2]));
    }

    /**
     * A digit-only given-name token (e.g. "2001" from a malformed record)
     * coerces to an int PHP array key. `dominantForm()` must still return a
     * string — never an int, which would raise a TypeError under
     * `strict_types`.
     */
    #[Test]
    public function dominantFormReturnsAStringForADigitOnlySpelling(): void
    {
        self::assertSame('2001', GivenNameNormalizer::dominantForm(['2001' => 3]));
    }

    /**
     * An empty group resolves to the empty string rather than erroring — the
     * documented boundary for a degenerate (no-spelling) fold group.
     */
    #[Test]
    public function dominantFormOfAnEmptyGroupIsTheEmptyString(): void
    {
        self::assertSame('', GivenNameNormalizer::dominantForm([]));
    }

    /**
     * Malformed (non-UTF-8) input makes both ICU steps fail; `foldKey()` must
     * fail soft to a plain lowercase mapping and return a string rather than
     * throwing or returning false.
     */
    #[Test]
    public function foldKeyFailsSoftOnInvalidUtf8(): void
    {
        $key = GivenNameNormalizer::foldKey("Jos\xE9");   // é as a raw Latin-1 byte, not UTF-8

        // The fail-soft path returns a non-empty key rather than throwing or
        // returning the empty string (which would collapse all broken-encoding
        // tokens into one bucket).
        self::assertNotSame('', $key);
    }
}
