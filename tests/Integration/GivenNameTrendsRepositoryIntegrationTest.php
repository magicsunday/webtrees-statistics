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
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GivenNameNormalizer;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

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
#[UsesClass(GivenNameNormalizer::class)]
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

        // Top-N order: ksort (fold key ascending) then a stable arsort (count
        // descending), so ties break on the fold key, engine-independently.
        // Fold keys sort anna < friedrich < hans < lisa < maria; the count-3
        // pair (Anna, Hans) leads in that key order, then the count-2 pair
        // (Friedrich, Maria), then the lone count-1 Lisa.
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
     * BCE given-name births fold into negative decade keys now that
     * GedcomScanner reads the `B.C.` marker and short years. name-trends-bce.ged
     * seeds five "Gaius" in the 50s B.C. (decade −50) and three "Marcus" in the
     * 40s B.C. (decade −40); both clear top-N so the dense decade axis stays
     * BCE-bounded (−50…−40, oldest first) with no CE-straddle. The view renders
     * the keys through DecadeName as "50s BCE" / "40s BCE".
     */
    #[Test]
    public function countByDecadeBucketsBceBirthsIntoNegativeDecades(): void
    {
        $tree   = $this->importFixtureTree('name-trends-bce.ged');
        $result = (new GivenNameTrendsRepository($tree))->countByDecade(10);

        self::assertSame([-50, -40], $result->decades);
        self::assertSame(['Gaius', 'Marcus'], $result->names);
        self::assertSame([-50 => 5, -40 => 0], $result->series['Gaius']);
        self::assertSame([-50 => 0, -40 => 3], $result->series['Marcus']);
    }

    /**
     * The reported bug (issue #135): a birth written in a non-Gregorian calendar
     * must bucket into the Gregorian decade it actually occurred in, not its
     * native-calendar year. name-trends-non-gregorian.ged seeds three "Napoleon"
     * born `@#DFRENCH R@ 1 VEND 12` (An XII = 1803) and two "Moses" born
     * `@#DHEBREW@ 1 TSH 5661` (= 1900). The former must land in the 1800s decade
     * (NOT decade 10, the literal An-XII year the old raw-GEDCOM scan produced)
     * and the latter in the 1900s (NOT decade 5660).
     */
    #[Test]
    public function countByDecadeConvertsNonGregorianBirthsToTheirGregorianDecade(): void
    {
        $tree   = $this->importFixtureTree('name-trends-non-gregorian.ged');
        $result = (new GivenNameTrendsRepository($tree))->countByDecade(10);

        self::assertSame(range(1800, 1900, 10), $result->decades);
        self::assertSame(['Napoleon', 'Moses'], $result->names);

        self::assertSame(3, $result->series['Napoleon'][1800], 'French Republican An XII → the 1800s decade.');
        self::assertSame(2, $result->series['Moses'][1900], 'Hebrew 5661 → the 1900s decade.');

        // The native-year buckets the old raw-GEDCOM scan produced must not exist.
        self::assertArrayNotHasKey(10, $result->series['Napoleon'], 'An XII is not bucketed as Gregorian year 12.');
        self::assertArrayNotHasKey(5660, $result->series['Moses'], 'Hebrew 5661 is not bucketed as year 5661.');
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
     * Spelling variants fold into one stream band labelled with the dominant
     * spelling. The fixture's José (1850, 1860) and Jose (1870) share a fold
     * key, so the band is labelled "José" and carries the 1870 birth too —
     * proving the variant's decade counts under the dominant name rather than
     * splitting into its own band.
     */
    #[Test]
    public function countByDecadeFoldsSpellingVariantsUnderTheDominantName(): void
    {
        $tree   = $this->importFixtureTree('given-name-fold.ged');
        $result = (new GivenNameTrendsRepository($tree))->countByDecade(10);

        self::assertContains('José', $result->names);
        self::assertContains('Sofia', $result->names);
        self::assertNotContains('Jose', $result->names);
        self::assertNotContains('Sofía', $result->names);

        // The folded band spans the variant's decade: José in the 1850s/1860s
        // plus the folded-in Jose in the 1870s, each a single birth.
        self::assertSame(1, $result->series['José'][1850]);
        self::assertSame(1, $result->series['José'][1860]);
        self::assertSame(1, $result->series['José'][1870]);
    }
}
