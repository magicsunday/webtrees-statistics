<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Locale;

use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the year → century mapping the four century-bucketing repositories
 * share via {@see CenturyName::fromYear()}. The Gregorian convention is that
 * year 1 still belongs to the 1st century and the 2nd century begins at year
 * 101, so the boundary tests pin the `-1 / +1` shift that the inline formula
 * was prone to fat-finger before the helper extraction.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(CenturyName::class)]
final class CenturyNameTest extends TestCase
{
    /**
     * Each row of the provider pins one Gregorian century-boundary case so the
     * formula cannot drift back to the off-by-one variant (`intdiv($year, 100)
     * + 1`, `intdiv($year - 1, 100)`, …).
     *
     * @return array<string, array{int, int}>
     */
    public static function gregorianBoundaryProvider(): array
    {
        return [
            'year 1 is the 1st century'       => [1, 1],
            'year 100 is the 1st century'     => [100, 1],
            'year 101 starts the 2nd century' => [101, 2],
            'year 1900 is the 19th century'   => [1900, 19],
            'year 1901 starts the 20th'       => [1901, 20],
            'year 2000 is the 20th century'   => [2000, 20],
            'year 2001 starts the 21st'       => [2001, 21],
        ];
    }

    /**
     * Boundary-rich mapping confirms each off-by-one case the inline formula
     * was vulnerable to.
     */
    #[Test]
    #[DataProvider('gregorianBoundaryProvider')]
    public function fromYearMapsGregorianYearsToCenturies(int $year, int $expectedCentury): void
    {
        self::assertSame($expectedCentury, CenturyName::fromYear($year));
    }
}
