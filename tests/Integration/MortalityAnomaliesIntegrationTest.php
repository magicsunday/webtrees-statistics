<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Mortality\MortalityAnomaly;
use MagicSunday\Webtrees\Statistic\Repository\LifeSpanRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\MortalityAnomalies;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\HistoricalEventCatalog;
use MagicSunday\Webtrees\Statistic\Support\Locale\IsoCountryMap;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * End-to-end test of {@see LifeSpanRepository::mortalityAnomalies()} against
 * a curated fixture: two deaths per year across 1853–1867 with a twelve-death
 * spike in 1860. Only 1860 sits at the centre of a full window whose baseline
 * clears the minimum, so it is the single anomaly the detector reports.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(LifeSpanRepository::class)]
#[UsesClass(MortalityAnomalies::class)]
#[UsesClass(MortalityAnomaly::class)]
#[UsesClass(HistoricalEventCatalog::class)]
#[UsesClass(IsoCountryMap::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class MortalityAnomaliesIntegrationTest extends IntegrationTestCase
{
    /**
     * Build a {@see LifeSpanRepository} bound to the given fixture tree.
     */
    private function repository(Tree $tree): LifeSpanRepository
    {
        return new LifeSpanRepository(
            $tree,
            $this->statisticsData($tree),
            new IsoCountryMap(),
        );
    }

    /**
     * The spike year is reported with the rolling-window median as its baseline
     * and the deaths-over-baseline multiplier.
     */
    #[Test]
    public function detectsTheSpikeYearWithBaselineAndMultiplier(): void
    {
        $tree   = $this->importFixtureTree('mortality-anomalies.ged');
        $result = $this->repository($tree)->mortalityAnomalies();

        self::assertCount(1, $result);

        $anomaly = $result[0];
        self::assertSame(1860, $anomaly->year);
        self::assertSame(12, $anomaly->deaths);
        self::assertSame(2, $anomaly->baseline);
        self::assertSame(6.0, $anomaly->multiplier);
        self::assertGreaterThan(2.0, $anomaly->zScore);
        // The deaths carry no place, so no country resolves and the year is
        // not annotated with a historical event.
        self::assertSame([], $anomaly->events);
    }

    /**
     * An anomaly year whose death places resolve to a covered country in a
     * covered period is annotated with the coinciding historical event. The
     * fixture spikes 1915 with twelve deaths in Germany, which falls in the
     * First World War.
     */
    #[Test]
    public function annotatesAnomalyYearWithCoincidingHistoricalEvent(): void
    {
        $tree   = $this->importFixtureTree('mortality-events.ged');
        $result = $this->repository($tree)->mortalityAnomalies();

        self::assertCount(1, $result);

        $anomaly = $result[0];
        self::assertSame(1915, $anomaly->year);
        self::assertSame(['First World War (1914–1918)'], $anomaly->events);
    }

    /**
     * A country represented by a single individual does not reach the
     * per-country threshold, even when that individual's death date is an
     * imprecise range (which webtrees stores as two `dates` rows, one per
     * bound). The fixture spikes 1580 with twelve individuals — eleven placeless
     * plus one whose death in France is recorded as "between Jan and Dec 1580" —
     * so France stays at one distinct individual and the year is not annotated,
     * and the spike's death count is the twelve distinct individuals rather than
     * the thirteen underlying date rows. This exercises both the
     * COUNT(DISTINCT d_gid) per-year dedup and the per-country distinct-set
     * dedup against a naive row count.
     */
    #[Test]
    public function singleImpreciseDeathDoesNotReachCountryThreshold(): void
    {
        $tree   = $this->importFixtureTree('mortality-events-approx.ged');
        $result = $this->repository($tree)->mortalityAnomalies();

        self::assertCount(1, $result);
        self::assertSame(1580, $result[0]->year);
        self::assertSame(12, $result[0]->deaths);
        self::assertSame([], $result[0]->events);
    }

    /**
     * Every catalogued event key resolves to a non-empty label: sweeping the
     * full historical year range against all countries exercises every event
     * and would raise on a key without a label.
     */
    #[Test]
    public function everyCatalogueEventResolvesToALabel(): void
    {
        $countries = [
            'DE', 'US', 'FR', 'GB', 'NL', 'PL', 'CH', 'CA', 'DK', 'AU',
            'RU', 'IT', 'CN', 'CZ', 'AT', 'BE', 'NO', 'SE', 'ES', 'IE',
        ];

        for ($year = 1300; $year <= 2025; ++$year) {
            foreach (HistoricalEventCatalog::labelsFor($year, $countries) as $label) {
                self::assertNotSame('', $label);
            }
        }

        // Spot-check a known coincidence resolves to its English label.
        self::assertSame(
            ['First World War (1914–1918)'],
            HistoricalEventCatalog::labelsFor(1915, ['DE']),
        );
    }

    /**
     * A threshold above the spike's standard score suppresses it, confirming the
     * parameter reaches the detector.
     */
    #[Test]
    public function thresholdAboveScoreReturnsNoAnomalies(): void
    {
        $tree = $this->importFixtureTree('mortality-anomalies.ged');

        self::assertSame([], $this->repository($tree)->mortalityAnomalies(4.0));
    }

    /**
     * A tree with no deaths yields an empty list rather than throwing on the
     * empty-window statistics.
     */
    #[Test]
    public function emptyTreeReturnsNoAnomalies(): void
    {
        $tree = $this->importFixtureTree('empty-tree.ged');

        self::assertSame([], $this->repository($tree)->mortalityAnomalies());
    }
}
