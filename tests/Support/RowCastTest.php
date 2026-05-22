<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support;

use MagicSunday\Webtrees\Statistic\Support\RowCast;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Verifies the defensive cast helper the repositories use against
 * the loosely-typed `stdClass` rows Eloquent returns. The branches
 * matter: SQLite + MySQL disagree on whether an `INTEGER` column
 * comes back as an `int` or a numeric string, and a missing
 * column (typo, schema drift) must surface as the supplied default
 * rather than a fatal undefined-property warning.
 *
 * DataProvider rows index the documented branches 1:1 so a future
 * additional branch (`is_int` shortcut, JSON-encoded fallback, …)
 * forces a new yield rather than being silently uncovered.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class RowCastTest extends TestCase
{
    /**
     * Each row maps one documented branch of {@see RowCast::int()}
     * to its expected outcome. The "missing column with override"
     * row pins the default-argument path; the "non-numeric value"
     * row pins the fallback that protects callers downstream of
     * the cast.
     *
     * @return array<string, array{0: ?array<string, mixed>, 1: string, 2: int, 3: int}>
     */
    public static function intProvider(): array
    {
        return [
            'int value happy path'         => [['n' => 42], 'n', 0, 42],
            'numeric string coerces'       => [['n' => '17'], 'n', 0, 17],
            'missing column falls back'    => [[], 'absent', 0, 0],
            'missing column with override' => [[], 'absent', -1, -1],
            'non-numeric value falls back' => [['n' => 'not-a-number'], 'n', 0, 0],
        ];
    }

    /**
     * Each row maps one documented branch of {@see RowCast::string()}.
     * The "non-string value" row catches the case where a numeric
     * column accidentally appears on a string-typed read.
     *
     * @return array<string, array{0: ?array<string, mixed>, 1: string, 2: string, 3: string}>
     */
    public static function stringProvider(): array
    {
        return [
            'string value happy path'      => [['xref' => 'I42'], 'xref', '', 'I42'],
            'missing column falls back'    => [[], 'absent', '', ''],
            'missing column with override' => [[], 'absent', 'UNK', 'UNK'],
            'non-string value falls back'  => [['n' => 17], 'n', '', ''],
        ];
    }

    /**
     * Drives every documented branch of {@see RowCast::int()} against a
     * rebuilt stdClass row — happy path, numeric-string coercion,
     * missing column with both the implicit and an explicit default,
     * and the non-numeric fallback.
     *
     * @param array<string, mixed> $properties
     */
    #[Test]
    #[DataProvider('intProvider')]
    public function intHandlesAllDocumentedBranches(array $properties, string $column, int $default, int $expected): void
    {
        $row = $this->stdClassFor($properties);

        self::assertSame($expected, RowCast::int($row, $column, $default));
    }

    /**
     * Drives every documented branch of {@see RowCast::string()}:
     * happy path on a string column, missing column with both
     * defaults, and the non-string-value fallback.
     *
     * @param array<string, mixed> $properties
     */
    #[Test]
    #[DataProvider('stringProvider')]
    public function stringHandlesAllDocumentedBranches(array $properties, string $column, string $default, string $expected): void
    {
        $row = $this->stdClassFor($properties);

        self::assertSame($expected, RowCast::string($row, $column, $default));
    }

    /**
     * Build a stdClass mirror of an Eloquent row from the given
     * property map — an empty map models the absent-column scenario.
     *
     * @param array<string, mixed> $properties
     */
    private function stdClassFor(array $properties): stdClass
    {
        $row = new stdClass();

        foreach ($properties as $name => $value) {
            $row->{$name} = $value;
        }

        return $row;
    }
}
