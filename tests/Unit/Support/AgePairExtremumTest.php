<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Support;

use MagicSunday\Webtrees\Statistic\Support\Calc\AgePairExtremum;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the {@see AgePairExtremum} min / max walker used by the
 * mirror-twin record-holder methods (youngest vs oldest spouse at
 * marriage, youngest vs oldest parent at first child). Each case
 * pins one documented branch so a future contributor cannot quietly
 * change the comparison operator or the tie-break order without the
 * suite catching it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class AgePairExtremumTest extends TestCase
{
    /**
     * Lowest walker over a multi-row iterator returns the row with
     * the smallest `years` value.
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
     * Highest walker over the same iterator returns the row with
     * the largest `years` value.
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
     * Empty iterator returns null on both directions — the caller
     * reads this as "no record holder could be picked" and renders a
     * "no data" placeholder rather than a misleading row.
     */
    #[Test]
    public function pickReturnsNullForEmptyIteratorOnBothDirections(): void
    {
        self::assertNull(AgePairExtremum::Lowest->pick([]));
        self::assertNull(AgePairExtremum::Highest->pick([]));
    }

    /**
     * Single-row iterator returns that row on both directions — the
     * loop's first-entry branch must initialise `$best` before any
     * comparison runs.
     */
    #[Test]
    public function pickReturnsSoleRowOnSingleEntryIterator(): void
    {
        $entries = [['xref' => 'I1', 'years' => 42]];

        self::assertSame(['xref' => 'I1', 'years' => 42], AgePairExtremum::Lowest->pick($entries));
        self::assertSame(['xref' => 'I1', 'years' => 42], AgePairExtremum::Highest->pick($entries));
    }

    /**
     * Ties on the `years` field keep the first-encountered row —
     * the comparison is strict `<` / `>` rather than `<=` / `>=`,
     * which means deterministic-iteration callers stay
     * deterministic on ties. Bracketed by a strictly-worse row so
     * a `<=` / `>=` regression cannot accidentally pass because
     * FIRST happens to be the directional winner.
     */
    #[Test]
    public function pickKeepsFirstEncounteredRowOnTie(): void
    {
        $lowestEntries = [
            ['xref' => 'FIRST', 'years' => 25],
            ['xref' => 'SECOND', 'years' => 25],
            ['xref' => 'WORSE', 'years' => 99],
        ];

        self::assertSame(
            ['xref' => 'FIRST', 'years' => 25],
            AgePairExtremum::Lowest->pick($lowestEntries),
        );

        $highestEntries = [
            ['xref' => 'FIRST', 'years' => 25],
            ['xref' => 'SECOND', 'years' => 25],
            ['xref' => 'WORSE', 'years' => 1],
        ];

        self::assertSame(
            ['xref' => 'FIRST', 'years' => 25],
            AgePairExtremum::Highest->pick($highestEntries),
        );
    }
}
