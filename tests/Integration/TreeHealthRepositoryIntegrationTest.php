<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\TreeHealthRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end test of the Tree Health repository against a curated
 * fixture that exercises every documented data-quality scenario:
 * fully sourced individuals, half-sourced ones, individuals missing
 * BIRT or DEAT, individuals with no events at all, plus a parent-child
 * chain with parseable birth years on both ends.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class TreeHealthRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Two of six individuals carry a SOUR citation; the headline ratio
     * must report 2 / 6.
     */
    #[Test]
    public function sourceCitationCoverageReturnsTheExpectedRatio(): void
    {
        $tree     = $this->importFixtureTree('tree-health.ged');
        $coverage = (new TreeHealthRepository($tree))->sourceCitationCoverage();

        self::assertSame(['value' => 2, 'total' => 6], $coverage);
    }

    /**
     * The fixture seeds known missing-event counts: BIRT missing on 2 of 6
     * individuals (the no-events-stub and the sparse child); BIRT place
     * missing additionally on the half-data parent (5 of 6); DEAT missing
     * on 4 of 6 (only the sourced parent and the documented child have
     * a death event); DEAT place missing on the same 4.
     */
    #[Test]
    public function missingEventGapsReturnTheExpectedCounts(): void
    {
        $tree = $this->importFixtureTree('tree-health.ged');
        $gaps = (new TreeHealthRepository($tree))->missingEventGaps();

        self::assertSame(2, $gaps['BIRT_event']['value']);
        self::assertSame(6, $gaps['BIRT_event']['total']);

        self::assertSame(3, $gaps['BIRT_place']['value']);

        self::assertSame(4, $gaps['DEAT_event']['value']);
        self::assertSame(4, $gaps['DEAT_place']['value']);
    }

    /**
     * The fixture has two usable parent-child pairs (I1 1900 → I3 1928,
     * delta 28; I2 1902 → I3 1928, delta 26); the average is 27.
     */
    #[Test]
    public function averageGenerationLengthMatchesTheFixture(): void
    {
        $tree    = $this->importFixtureTree('tree-health.ged');
        $average = (new TreeHealthRepository($tree))->averageGenerationLength();

        self::assertNotNull($average);
        self::assertEqualsWithDelta(27.0, $average, 0.001);
    }
}
