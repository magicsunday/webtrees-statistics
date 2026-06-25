<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Gedcom;

use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the defensive cast helper the repositories use against the
 * loosely-typed rows Eloquent returns. The branches matter: SQLite + MySQL
 * disagree on whether an `INTEGER` column comes back as an `int` or a numeric
 * string, and a missing column (typo, schema drift) must surface as the supplied
 * default rather than a fatal undefined-property warning.
 *
 * Each provider row is a small typed fixture object standing in for an Eloquent
 * row — `RowCast` reads it through `get_object_vars()`, so a constructor-promoted
 * readonly property models a present column and an empty object models an absent
 * one. DataProvider rows index the documented branches 1:1 so a future branch
 * (`is_int` shortcut, JSON-encoded fallback, …) forces a new yield rather than
 * being silently uncovered.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(RowCast::class)]
final class RowCastTest extends TestCase
{
    /**
     * Each row maps one documented branch of {@see RowCast::int()} to its
     * expected outcome. The "missing column with override" row pins the
     * default-argument path; the "non-numeric value" row pins the fallback that
     * protects callers downstream of the cast.
     *
     * @return array<string, array{0: object, 1: string, 2: int, 3: int}>
     */
    public static function intProvider(): array
    {
        return [
            'int value happy path' => [new readonly class(42) {
                public function __construct(public int $n)
                {
                }
            }, 'n', 0, 42],
            'numeric string coerces' => [new readonly class('17') {
                public function __construct(public string $n)
                {
                }
            }, 'n', 0, 17],
            'missing column falls back' => [new class {
            }, 'absent', 0, 0],
            'missing column with override' => [new class {
            }, 'absent', -1, -1],
            'non-numeric value falls back' => [new readonly class('not-a-number') {
                public function __construct(public string $n)
                {
                }
            }, 'n', 0, 0],
        ];
    }

    /**
     * Each row maps one documented branch of {@see RowCast::string()}. The
     * "non-string value" row catches the case where a numeric column accidentally
     * appears on a string-typed read.
     *
     * @return array<string, array{0: object, 1: string, 2: string, 3: string}>
     */
    public static function stringProvider(): array
    {
        return [
            'string value happy path' => [new readonly class('I42') {
                public function __construct(public string $xref)
                {
                }
            }, 'xref', '', 'I42'],
            'missing column falls back' => [new class {
            }, 'absent', '', ''],
            'missing column with override' => [new class {
            }, 'absent', 'UNK', 'UNK'],
            'non-string value falls back' => [new readonly class(17) {
                public function __construct(public int $n)
                {
                }
            }, 'n', '', ''],
        ];
    }

    /**
     * Drives every documented branch of {@see RowCast::int()} — happy path,
     * numeric-string coercion, missing column with both the implicit and an
     * explicit default, and the non-numeric fallback.
     */
    #[Test]
    #[DataProvider('intProvider')]
    public function intHandlesAllDocumentedBranches(object $row, string $column, int $default, int $expected): void
    {
        self::assertSame($expected, RowCast::int($row, $column, $default));
    }

    /**
     * Drives every documented branch of {@see RowCast::string()}: happy path on a
     * string column, missing column with both defaults, and the non-string-value
     * fallback.
     */
    #[Test]
    #[DataProvider('stringProvider')]
    public function stringHandlesAllDocumentedBranches(object $row, string $column, string $default, string $expected): void
    {
        self::assertSame($expected, RowCast::string($row, $column, $default));
    }
}
