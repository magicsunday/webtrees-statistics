<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Model\StreamGraph\GivenNameTrendsPayload;
use MagicSunday\Webtrees\Statistic\Repository\GivenNameTrendsRepository;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_column;

/**
 * End-to-end test of the per-decade given-name aggregator against a curated
 * fixture covering: a name peaking in one decade, a name with two peaks, a name
 * spanning more than one decade, and an individual with no birth date (must be
 * silently skipped).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(GivenNameTrendsRepository::class)]
#[UsesClass(GivenNameTrendsPayload::class)]
#[UsesClass(GedcomScanner::class)]
#[UsesClass(RowCast::class)]
final class GivenNameTrendsRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * The fixture has eleven dated individuals across five decades: Anna×2 in
     * the 1850s and Anna×1 in the 1900s (total 3), Friedrich×2 in the 1860s,
     * Maria×2 in the 1900s, Hans×3 in the 1950s, Lisa×1 in the 1960s. A twelfth
     * individual carries no BIRT date and is silently skipped by the
     * aggregator.
     */
    #[Test]
    public function countByDecadeReturnsTheExpectedSeries(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = (new GivenNameTrendsRepository($tree))->countByDecade(10);

        // Decades span the entire dated population (1850 → 1960) in
        // ten-year steps, including the two gap decades (1870s, 1880s,
        // 1890s, 1910s, 1920s, 1930s, 1940s) where the top names have
        // no births. Holding the full range stable lets the renderer
        // show fade-out tails at both ends of the stream.
        self::assertSame(
            [1850, 1860, 1870, 1880, 1890, 1900, 1910, 1920, 1930, 1940, 1950, 1960],
            $result->decades,
        );

        // Top-N order: arsort is stable, so within a tie the first-seen
        // name wins. The fixture insertion order is Anna, Friedrich,
        // Maria, Hans, Lisa, but Anna and Hans both end with count 3 so
        // Anna leads, Hans follows. Friedrich and Maria both have 2 in
        // first-seen order. Lisa is the only count-1 entry.
        self::assertSame(
            ['Anna', 'Hans', 'Friedrich', 'Maria', 'Lisa'],
            $result->names,
        );

        self::assertSame(
            [
                1850 => 2,
                1860 => 0,
                1870 => 0,
                1880 => 0,
                1890 => 0,
                1900 => 1,
                1910 => 0,
                1920 => 0,
                1930 => 0,
                1940 => 0,
                1950 => 0,
                1960 => 0,
            ],
            $result->series['Anna'],
            'Anna peaks twice — in the 1850s and again in the 1900s',
        );

        self::assertSame(3, $result->series['Hans'][1950] ?? null);
        self::assertSame(2, $result->series['Friedrich'][1860] ?? null);
        self::assertSame(0, $result->series['Friedrich'][1900] ?? null);
    }

    /**
     * Asking for a top-N smaller than the distinct-name count truncates the
     * result to exactly that many bands and to the decades where at least one
     * of those bands actually has data. Within each kept decade the series rows
     * stay dense — missing entries default to 0.
     */
    #[Test]
    public function countByDecadeRespectsTheTopNLimit(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = (new GivenNameTrendsRepository($tree))->countByDecade(2);

        self::assertSame(['Anna', 'Hans'], $result->names);

        // Decade range still spans the entire population's birth history
        // (1850–1960). The smaller top-N only narrows the bands, never
        // the x-axis.
        self::assertCount(12, $result->decades);
        self::assertSame(1850, $result->decades[0]);
        self::assertSame(1960, $result->decades[11]);

        self::assertSame(2, $result->series['Anna'][1850]);
        self::assertSame(1, $result->series['Anna'][1900]);
        self::assertSame(0, $result->series['Anna'][1950]);
        self::assertSame(3, $result->series['Hans'][1950]);
    }

    /**
     * The last-year aggregate keeps the same top-N-by-frequency selection as the
     * decade series, but reports each name's most recent birth year and orders
     * the result by that year, descending. The fixture's most recent births per
     * name are Anna 1900, Friedrich 1865, Maria 1905, Hans 1955, Lisa 1960; the
     * undated individual is skipped. With a reference year of 1980 and the
     * default 25-year active window, only names last seen in 1955 or later
     * (Hans, Lisa) count as active.
     */
    #[Test]
    public function lastYearByNameReportsMostRecentYearAndActiveFlag(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = (new GivenNameTrendsRepository($tree))->lastYearByName(10, 1980);

        self::assertSame(
            [
                ['name' => 'Lisa', 'lastYear' => 1960, 'total' => 1, 'isActive' => true],
                ['name' => 'Hans', 'lastYear' => 1955, 'total' => 3, 'isActive' => true],
                ['name' => 'Maria', 'lastYear' => 1905, 'total' => 2, 'isActive' => false],
                ['name' => 'Anna', 'lastYear' => 1900, 'total' => 3, 'isActive' => false],
                ['name' => 'Friedrich', 'lastYear' => 1865, 'total' => 2, 'isActive' => false],
            ],
            $result,
        );
    }

    /**
     * The selection is balanced between still-active and extinct names rather
     * than taken purely by frequency. With a top-3 request and a 1980 reference
     * year (25-year window → active from 1955), half the slots (intdiv(3, 2) = 1)
     * go to the most frequent active name and the remaining two to the most
     * frequent extinct names: Hans (active, 3) leads, then Anna (3) and
     * Friedrich (2). Lisa (active but only 1 occurrence) loses the single active
     * slot to Hans; Maria (2) loses the last extinct slot to Friedrich on the
     * first-seen tie-break.
     */
    #[Test]
    public function lastYearByNameBalancesActiveAndExtinctSlots(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = (new GivenNameTrendsRepository($tree))->lastYearByName(3, 1980);

        self::assertSame(
            [
                ['name' => 'Hans', 'lastYear' => 1955, 'total' => 3, 'isActive' => true],
                ['name' => 'Anna', 'lastYear' => 1900, 'total' => 3, 'isActive' => false],
                ['name' => 'Friedrich', 'lastYear' => 1865, 'total' => 2, 'isActive' => false],
            ],
            $result,
        );
    }

    /**
     * The balanced split surfaces a surviving name that a pure top-N-by-frequency
     * cut would bury. With reference year 1984 (active from 1959) only Lisa
     * (1960) is still active; Hans (1955) has now fallen extinct. A plain top-2
     * by frequency would pick Anna (3) and Hans (3) — two extinct names, hiding
     * every survivor. The balanced rule instead reserves one slot for the most
     * frequent active name (Lisa) and one for the most frequent extinct name
     * (Anna), so the contrast survivors-vs-vanished is always visible.
     */
    #[Test]
    public function lastYearByNameSurfacesActiveNamesOverMoreFrequentExtinctOnes(): void
    {
        $tree   = $this->importFixtureTree('name-trends.ged');
        $result = (new GivenNameTrendsRepository($tree))->lastYearByName(2, 1984);

        self::assertSame(
            [
                ['name' => 'Lisa', 'lastYear' => 1960, 'total' => 1, 'isActive' => true],
                ['name' => 'Anna', 'lastYear' => 1900, 'total' => 3, 'isActive' => false],
            ],
            $result,
            'The reserved active slot keeps Lisa visible even though Anna and Hans are more frequent',
        );
    }

    /**
     * The "no given name" placeholder (`@P.N.`) is not a real name. The
     * dedicated fixture pairs two dated "Anna" births (1900, 1910) with a third
     * individual who has only the placeholder and a 2010 birth. The aggregator
     * must drop the placeholder from both the decade series and the last-year
     * list — and because it is dropped before the decade range is computed, the
     * 2010 birth never widens the x-axis past the 1910s.
     */
    #[Test]
    public function excludesTheNoGivenNamePlaceholder(): void
    {
        $tree = $this->importFixtureTree('given-name-placeholder.ged');
        $repo = new GivenNameTrendsRepository($tree);

        $trends = $repo->countByDecade(20);
        self::assertSame(['Anna'], $trends->names);
        self::assertNotContains('@P.N.', $trends->names);
        // The placeholder's 2010 birth must not widen the range past 1910.
        self::assertSame([1900, 1910], $trends->decades);

        $lastYear = $repo->lastYearByName(20);
        self::assertSame(['Anna'], array_column($lastYear, 'name'));
        self::assertNotContains('@P.N.', array_column($lastYear, 'name'));
    }
}
