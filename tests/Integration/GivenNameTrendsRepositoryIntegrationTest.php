<?php

declare(strict_types=1);

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\GivenNameTrendsRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end test of the per-decade given-name aggregator against a
 * curated fixture covering: a name peaking in one decade, a name with
 * two peaks, a name spanning more than one decade, and an individual
 * with no birth date (must be silently skipped).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class GivenNameTrendsRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * The fixture has eleven dated individuals across five decades:
     * Anna×2 in the 1850s and Anna×1 in the 1900s (total 3), Friedrich×2
     * in the 1860s, Maria×2 in the 1900s, Hans×3 in the 1950s, Lisa×1
     * in the 1960s. A twelfth individual carries no BIRT date and is
     * silently skipped by the aggregator.
     */
    #[Test]
    public function countByDecadeReturnsTheExpectedSeries(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = (new GivenNameTrendsRepository($tree))->countByDecade(10);

        // Decades present in the fixture (the 1900 birth contributes to
        // the 1900s decade, the 1905 birth also lands there).
        self::assertSame([1850, 1860, 1900, 1950, 1960], $result['decades']);

        // Top-N order: arsort is stable, so within a tie the first-seen
        // name wins. The fixture insertion order is Anna, Friedrich,
        // Maria, Hans, Lisa, but Anna and Hans both end with count 3 so
        // Anna leads, Hans follows. Friedrich and Maria both have 2 in
        // first-seen order. Lisa is the only count-1 entry.
        self::assertSame(
            ['Anna', 'Hans', 'Friedrich', 'Maria', 'Lisa'],
            $result['names'],
        );

        self::assertSame(
            [
                1850 => 2,
                1860 => 0,
                1900 => 1,
                1950 => 0,
                1960 => 0,
            ],
            $result['series']['Anna'],
            'Anna peaks twice — in the 1850s and again in the 1900s',
        );

        self::assertSame(
            [
                1850 => 0,
                1860 => 2,
                1900 => 0,
                1950 => 0,
                1960 => 0,
            ],
            $result['series']['Friedrich'],
        );

        self::assertSame(
            [
                1850 => 0,
                1860 => 0,
                1900 => 0,
                1950 => 3,
                1960 => 0,
            ],
            $result['series']['Hans'],
        );
    }

    /**
     * Asking for a top-N smaller than the distinct-name count truncates
     * the result to exactly that many bands and to the decades where at
     * least one of those bands actually has data. Within each kept
     * decade the series rows stay dense — missing entries default to 0.
     */
    #[Test]
    public function countByDecadeRespectsTheTopNLimit(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = (new GivenNameTrendsRepository($tree))->countByDecade(2);

        self::assertSame(['Anna', 'Hans'], $result['names']);
        self::assertSame([1850, 1900, 1950], $result['decades']);

        self::assertSame([1850 => 2, 1900 => 1, 1950 => 0], $result['series']['Anna']);
        self::assertSame([1850 => 0, 1900 => 0, 1950 => 3], $result['series']['Hans']);
    }
}
