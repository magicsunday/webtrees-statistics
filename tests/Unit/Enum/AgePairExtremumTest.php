<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Enum;

use MagicSunday\Webtrees\Statistic\Enum\AgePairExtremum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the {@see AgePairExtremum} min / max walker used by the mirror-twin
 * record-holder methods (youngest vs oldest spouse at marriage, youngest vs
 * oldest parent at first child). Each case pins one documented branch so a
 * future contributor cannot quietly change the comparison operator or the
 * tie-break order without the suite catching it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(AgePairExtremum::class)]
final class AgePairExtremumTest extends TestCase
{
    /**
     * Lowest walker over a multi-row iterator returns the row with the smallest
     * `years` value.
     */
    #[Test]
    public function lowestPicksMinimumYearsRow(): void
    {
        $entries = [
            ['xref' => 'I1', 'years' => 40],
            ['xref' => 'I2', 'years' => 18],
            ['xref' => 'I3', 'years' => 27],
        ];

        self::assertSame(
            ['xref' => 'I2', 'years' => 18],
            AgePairExtremum::Lowest->pick($entries),
        );
    }

    /**
     * Highest walker over the same iterator returns the row with the largest
     * `years` value.
     */
    #[Test]
    public function highestPicksMaximumYearsRow(): void
    {
        $entries = [
            ['xref' => 'I1', 'years' => 40],
            ['xref' => 'I2', 'years' => 18],
            ['xref' => 'I3', 'years' => 27],
        ];

        self::assertSame(
            ['xref' => 'I1', 'years' => 40],
            AgePairExtremum::Highest->pick($entries),
        );
    }

    /**
     * Empty iterator returns null on both directions — the caller reads this as
     * "no record holder could be picked" and renders a "no data" placeholder
     * rather than a misleading row.
     */
    #[Test]
    public function pickReturnsNullForEmptyIteratorOnBothDirections(): void
    {
        self::assertNull(AgePairExtremum::Lowest->pick([]));
        self::assertNull(AgePairExtremum::Highest->pick([]));
    }

    /**
     * Single-row iterator returns that row on both directions — the loop's
     * first-entry branch must initialise `$best` before any comparison runs.
     */
    #[Test]
    public function pickReturnsSoleRowOnSingleEntryIterator(): void
    {
        $entries = [['xref' => 'I1', 'years' => 42]];

        self::assertSame(['xref' => 'I1', 'years' => 42], AgePairExtremum::Lowest->pick($entries));
        self::assertSame(['xref' => 'I1', 'years' => 42], AgePairExtremum::Highest->pick($entries));
    }

    /**
     * Ties on the `years` field are broken on the lexicographically SMALLEST
     * `xref`, NOT the first-encountered row — the feeding iterators carry no
     * `ORDER BY`, so a first-wins tie-break would pick a row-order/engine-
     * dependent holder. The smaller xref is placed SECOND in iteration here so
     * the assertion fails on a first-encountered (strict `<`/`>`) implementation
     * and only passes once the deterministic xref tie-break is applied. A
     * strictly-worse row brackets it so a `<=`/`>=` regression cannot pass by
     * the directional winner happening to sort first.
     */
    #[Test]
    public function pickBreaksTieByLowestXref(): void
    {
        $lowestEntries = [
            ['xref' => 'I2', 'years' => 25],
            ['xref' => 'I1', 'years' => 25],
            ['xref' => 'I9', 'years' => 99],
        ];

        self::assertSame(
            ['xref' => 'I1', 'years' => 25],
            AgePairExtremum::Lowest->pick($lowestEntries),
        );

        $highestEntries = [
            ['xref' => 'I2', 'years' => 25],
            ['xref' => 'I1', 'years' => 25],
            ['xref' => 'I0', 'years' => 1],
        ];

        self::assertSame(
            ['xref' => 'I1', 'years' => 25],
            AgePairExtremum::Highest->pick($highestEntries),
        );
    }

    /**
     * The tie-break compares xrefs in BYTE order (strcmp), not numerically. With
     * digit-only xrefs "915" and "1000", byte order makes "1000" the smaller
     * ("1" < "9") while a numeric `<` comparison would pick "915" — so this pins
     * the strcmp tie-break and fails on a `<`-based regression that would treat
     * the numeric-looking xrefs as numbers.
     */
    #[Test]
    public function pickBreaksTieInByteOrderNotNumericOrder(): void
    {
        $entries = [
            ['xref' => '915', 'years' => 30],
            ['xref' => '1000', 'years' => 30],
        ];

        self::assertSame(
            ['xref' => '1000', 'years' => 30],
            AgePairExtremum::Highest->pick($entries),
        );
    }
}
