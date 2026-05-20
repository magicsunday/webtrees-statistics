<?php

declare(strict_types=1);

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace MagicSunday\Webtrees\Statistic\Test\Support;

use MagicSunday\Webtrees\Statistic\Support\GedcomScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the edge-case behaviour the audit loop on Issue #11 surfaced:
 * empty PLAC sub-lines, range-style date markers, and event-block
 * boundary detection. The classifier and the data-quality counters both
 * lean on these helpers so a regression here would silently miscount.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class GedcomScannerTest extends TestCase
{
    /**
     * @return iterable<string, array{0: string, 1: string, 2: bool}>
     */
    public static function eventPlaceSamples(): iterable
    {
        yield 'PLAC with single-word value is recognised' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 DATE 1 JAN 1900\n2 PLAC Berlin",
            'BIRT',
            true,
        ];
        yield 'PLAC with multi-word comma-separated value is recognised' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 PLAC Berlin, Brandenburg, Germany",
            'BIRT',
            true,
        ];
        yield 'empty PLAC followed by NOTE sub-line is rejected' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 PLAC\n3 NOTE later research lead",
            'BIRT',
            false,
        ];
        yield 'empty PLAC at end of record is rejected' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 PLAC",
            'BIRT',
            false,
        ];
        yield 'PLAC that belongs to the next event does not satisfy the earlier event' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 DATE 1 JAN 1900\n1 DEAT\n2 PLAC Hamburg",
            'BIRT',
            false,
        ];
        yield 'PLAC under the second of two BIRT events satisfies the request' => [
            // Pathological but legal: webtrees stores the first BIRT only,
            // but the scanner should still find a PLAC inside the first
            // event's block.
            "\n0 @I1@ INDI\n1 BIRT\n2 PLAC London\n1 DEAT",
            'BIRT',
            true,
        ];
    }

    /**
     * Empty `2 PLAC` lines (with or without level-3 sub-lines) must not
     * count as "place recorded" — the data-quality metric depends on it.
     */
    #[Test]
    #[DataProvider('eventPlaceSamples')]
    public function hasEventPlaceMatchesOnlyNonEmptyPlaceLines(string $gedcom, string $tag, bool $expected): void
    {
        self::assertSame($expected, GedcomScanner::hasEventPlace($gedcom, $tag));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: ?int}>
     */
    public static function eventYearSamples(): iterable
    {
        yield 'exact year wins' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 DATE 1 JAN 1900",
            'BIRT',
            1900,
        ];
        yield 'ABT prefix is skipped, year still captured' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 DATE ABT 1900",
            'BIRT',
            1900,
        ];
        yield 'BET ... AND ... returns the lower bound' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 DATE BET 1900 AND 1910",
            'BIRT',
            1900,
        ];
        yield 'FROM ... TO ... returns the lower bound' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 DATE FROM 1900 TO 1910",
            'BIRT',
            1900,
        ];
        yield 'no DATE sub-line returns null' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 PLAC Berlin",
            'BIRT',
            null,
        ];
        yield 'no event returns null' => [
            "\n0 @I1@ INDI\n1 NAME John /Doe/",
            'BIRT',
            null,
        ];
    }

    /**
     * The first four-digit token on the `2 DATE` line wins; range markers
     * like `BET 1900 AND 1910` therefore resolve to the start year (1900).
     */
    #[Test]
    #[DataProvider('eventYearSamples')]
    public function extractEventYearGrabsTheFirstFourDigitToken(string $gedcom, string $tag, ?int $expected): void
    {
        self::assertSame($expected, GedcomScanner::extractEventYear($gedcom, $tag));
    }
}
