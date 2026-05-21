<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

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

    /**
     * Fixtures for extractEventPlace covering the rules the migration
     * aggregator depends on: trimmed value, empty / whitespace-only
     * lines reject, no event returns null.
     *
     * @return iterable<string, array{0: string, 1: string, 2: ?string}>
     */
    public static function extractEventPlaceSamples(): iterable
    {
        yield 'simple place is returned trimmed' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 PLAC Hamburg, Germany",
            'BIRT',
            'Hamburg, Germany',
        ];
        yield 'trailing whitespace is stripped' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 PLAC   Hamburg, Germany   ",
            'BIRT',
            'Hamburg, Germany',
        ];
        yield 'empty PLAC line is null' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 PLAC\n3 NOTE pending",
            'BIRT',
            null,
        ];
        yield 'whitespace-only PLAC line is null' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 PLAC    ",
            'BIRT',
            null,
        ];
        yield 'place from a different event is not matched' => [
            "\n0 @I1@ INDI\n1 BIRT\n2 DATE 1 JAN 1900\n1 DEAT\n2 PLAC Berlin",
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
     * extractEventPlace returns the first non-empty 2 PLAC sub-line of
     * the requested event, trimmed; empty / whitespace-only PLAC lines
     * are treated as no place.
     *
     * @param string  $gedcom   Raw GEDCOM record body
     * @param string  $tag      Level-1 event tag to inspect
     * @param ?string $expected Expected place string (null when no place)
     */
    #[Test]
    #[DataProvider('extractEventPlaceSamples')]
    public function extractEventPlaceReturnsTheFirstNonEmptyPlaceLine(string $gedcom, string $tag, ?string $expected): void
    {
        self::assertSame($expected, GedcomScanner::extractEventPlace($gedcom, $tag));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function extractPrimaryNameSamples(): iterable
    {
        yield 'plain Given /Surname/ strips the slashes' => [
            "0 @I1@ INDI\n1 NAME Anna /Test/\n1 SEX F",
            'Anna Test',
        ];

        yield 'closing slash followed by suffix does not leave a double space' => [
            "0 @I1@ INDI\n1 NAME John /Smith/ Jr\n1 SEX M",
            'John Smith Jr',
        ];

        yield 'given name only (no surname slashes) is returned verbatim' => [
            "0 @I1@ INDI\n1 NAME Cher\n1 SEX F",
            'Cher',
        ];

        yield 'first non-empty `1 NAME` line wins when several are recorded' => [
            "0 @I1@ INDI\n1 NAME Primary /Test/\n1 NAME Secondary /Test/",
            'Primary Test',
        ];

        yield 'empty `1 NAME / /` placeholder is skipped in favour of a later real NAME' => [
            "0 @I1@ INDI\n1 NAME / /\n1 NAME Real /Name/\n1 SEX F",
            'Real Name',
        ];

        yield 'lone `1 NAME / /` falls back to placeholder' => [
            "0 @I1@ INDI\n1 NAME / /\n1 SEX F",
            '(no name)',
        ];

        yield 'whitespace-only `1 NAME    ` falls back to placeholder' => [
            "0 @I1@ INDI\n1 NAME    \n1 SEX F",
            '(no name)',
        ];

        yield 'GEDCOM without any `1 NAME` line falls back to placeholder' => [
            "0 @I1@ INDI\n1 SEX F\n1 BIRT\n2 PLAC Berlin",
            '(no name)',
        ];

        yield 'unpaired UTF-8 lead byte from legacy import is scrubbed to substitute char' => [
            "0 @I1@ INDI\n1 NAME J\xC3os\xC3\xA9 /Smith/\n1 SEX F",
            "J?os\xC3\xA9 Smith",
        ];
    }

    /**
     * extractPrimaryName surfaces the first `1 NAME` line, strips the
     * surname-delimiter slashes, collapses internal whitespace so a
     * suffix after the closing slash does not double-space, and falls
     * back to `(no name)` whenever the resulting string would be empty
     * or the gedcom carries no NAME line at all.
     *
     * @param string $gedcom   Raw GEDCOM record body
     * @param string $expected Expected display name
     */
    #[Test]
    #[DataProvider('extractPrimaryNameSamples')]
    public function extractPrimaryNameReturnsTheCleanedFirstNameLine(string $gedcom, string $expected): void
    {
        self::assertSame($expected, GedcomScanner::extractPrimaryName($gedcom));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function extractAllTagValuesSamples(): iterable
    {
        yield 'single OCCU returns one value' => [
            "0 @I1@ INDI\n1 NAME Anna /Test/\n1 OCCU Schmied\n1 SEX F",
            'OCCU',
            ['Schmied'],
        ];

        yield 'multiple OCCU lines return both values in encounter order' => [
            "0 @I1@ INDI\n1 OCCU Schmied\n1 OCCU Hufschmied\n1 SEX M",
            'OCCU',
            ['Schmied', 'Hufschmied'],
        ];

        yield 'missing tag returns empty list' => [
            "0 @I1@ INDI\n1 NAME Anna /Test/\n1 SEX F",
            'OCCU',
            [],
        ];

        yield 'tag value with trailing whitespace is trimmed' => [
            "0 @I1@ INDI\n1 OCCU    Bauer   \n1 SEX M",
            'OCCU',
            ['Bauer'],
        ];

        yield 'tag with only whitespace value is dropped' => [
            "0 @I1@ INDI\n1 OCCU    \n1 SEX M",
            'OCCU',
            [],
        ];

        yield 'RELI captured the same way as OCCU' => [
            "0 @I1@ INDI\n1 RELI Katholisch\n1 SEX F",
            'RELI',
            ['Katholisch'],
        ];
    }

    /**
     * extractAllTagValues captures every value of a `1 <tag>` line in
     * the body, preserving encounter order and trimming each. Used by
     * Top-N aggregators over individual facts (OCCU, RELI, NATI, …).
     *
     * @param string       $gedcom   Raw GEDCOM record body
     * @param string       $tag      Level-1 tag to capture
     * @param list<string> $expected Expected captured values in encounter order
     */
    #[Test]
    #[DataProvider('extractAllTagValuesSamples')]
    public function extractAllTagValuesCapturesEveryTagOccurrence(string $gedcom, string $tag, array $expected): void
    {
        self::assertSame($expected, GedcomScanner::extractAllTagValues($gedcom, $tag));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: string, 3: ?string}>
     */
    public static function extractEventSubValueSamples(): iterable
    {
        yield 'DEAT/CAUS within the same block returns the value' => [
            "0 @I1@ INDI\n1 NAME Anna /Test/\n1 DEAT\n2 DATE 1850\n2 PLAC Berlin\n2 CAUS Cholera",
            'DEAT',
            'CAUS',
            'Cholera',
        ];

        yield 'missing event block returns null' => [
            "0 @I1@ INDI\n1 NAME Anna /Test/\n1 SEX F",
            'DEAT',
            'CAUS',
            null,
        ];

        yield 'event block without the sub-tag returns null' => [
            "0 @I1@ INDI\n1 DEAT\n2 DATE 1850\n2 PLAC Berlin",
            'DEAT',
            'CAUS',
            null,
        ];

        yield 'sub-tag in a different event block is not picked up' => [
            "0 @I1@ INDI\n1 BIRT\n2 CAUS premature\n1 DEAT\n2 DATE 1850",
            'DEAT',
            'CAUS',
            null,
        ];

        yield 'whitespace-only sub-value returns null' => [
            "0 @I1@ INDI\n1 DEAT\n2 CAUS     \n2 PLAC Berlin",
            'DEAT',
            'CAUS',
            null,
        ];

        yield 'sub-value is trimmed' => [
            "0 @I1@ INDI\n1 DEAT\n2 CAUS    Tuberkulose  \n2 PLAC Berlin",
            'DEAT',
            'CAUS',
            'Tuberkulose',
        ];
    }

    /**
     * extractEventSubValue scopes to the level-1 event block and pulls
     * the first matching `2 <subTag>` value. Block-confinement ensures a
     * later event's sub-tag cannot satisfy an earlier event's missing
     * sub-tag.
     *
     * @param string  $gedcom   Raw GEDCOM record body
     * @param string  $eventTag Level-1 event tag whose block to scan
     * @param string  $subTag   Level-2 sub-tag whose value to extract
     * @param ?string $expected Expected sub-value (null when absent)
     */
    #[Test]
    #[DataProvider('extractEventSubValueSamples')]
    public function extractEventSubValueScopesToTheEventBlock(string $gedcom, string $eventTag, string $subTag, ?string $expected): void
    {
        self::assertSame($expected, GedcomScanner::extractEventSubValue($gedcom, $eventTag, $subTag));
    }

    /**
     * @return iterable<string, array{0: string, 1: string, 2: list<string>}>
     */
    public static function extractAllSubTagValuesSamples(): iterable
    {
        yield 'single 2 RELI under BAPM is captured' => [
            "0 @I1@ INDI\n1 BAPM\n2 DATE 10 APR 1810\n2 RELI evangelisch-lutherisch",
            'RELI',
            ['evangelisch-lutherisch'],
        ];

        yield 'multiple 2 RELI lines under different events are all captured' => [
            "0 @I1@ INDI\n1 BAPM\n2 RELI Katholisch\n1 CONF\n2 RELI Katholisch",
            'RELI',
            ['Katholisch', 'Katholisch'],
        ];

        yield 'no 2 RELI line returns empty list' => [
            "0 @I1@ INDI\n1 BAPM\n2 DATE 10 APR 1810\n2 PLAC Hamburg",
            'RELI',
            [],
        ];

        yield 'whitespace-only 2 RELI value is dropped' => [
            "0 @I1@ INDI\n1 BAPM\n2 RELI    \n2 PLAC Hamburg",
            'RELI',
            [],
        ];

        yield 'trimmed value is returned' => [
            "0 @I1@ INDI\n1 BAPM\n2 RELI    lutherisch   \n2 PLAC Hamburg",
            'RELI',
            ['lutherisch'],
        ];

        yield 'level-1 same-tag is NOT captured by sub-tag scan' => [
            "0 @I1@ INDI\n1 RELI evangelisch-lutherisch\n1 BAPM\n2 PLAC Hamburg",
            'RELI',
            [],
        ];
    }

    /**
     * extractAllSubTagValues captures every level-2 `<subTag>` value
     * anywhere in the record. The cross-cutting form complements
     * {@see extractEventSubValue()} (which is scoped to one event
     * block) and feeds aggregators that don't care which event the
     * sub-tag attached to.
     *
     * @param string       $gedcom   Raw GEDCOM record body
     * @param string       $subTag   Level-2 tag to capture
     * @param list<string> $expected Expected captured values in encounter order
     */
    #[Test]
    #[DataProvider('extractAllSubTagValuesSamples')]
    public function extractAllSubTagValuesCapturesEverySubTagOccurrence(string $gedcom, string $subTag, array $expected): void
    {
        self::assertSame($expected, GedcomScanner::extractAllSubTagValues($gedcom, $subTag));
    }
}
