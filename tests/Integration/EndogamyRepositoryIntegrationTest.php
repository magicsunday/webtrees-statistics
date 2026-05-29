<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\EndogamyRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end test of {@see EndogamyRepository} against the `endogamy.ged`
 * fixture, which contains exactly two testable couples:
 *
 *   F_COUSIN — CousinHusb (I7) + CousinWife (I8). Their parents
 *       (I3 + I5) are siblings whose parents are the shared
 *       grandparents I1 + I2. Shared ancestors exist within 4
 *       generations → ENDOGAMOUS.
 *
 *   F_UNREL — UnrelHusb (I9) + UnrelWife (I10). Both have recorded
 *       parents, but the two parentage trees are completely
 *       disjoint → NOT endogamous.
 *
 * Five additional families (F0, F1, F2, F_UH, F_UW) exist purely to populate
 * the parent-of map; their spouses have no FAMC records themselves and are
 * therefore EXCLUDED from the testable-couple denominator.
 *
 * Expected: total=2, endogamous=1, rate=50.0 %.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class EndogamyRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * The 50 % acceptance scenario from the issue: one cousin couple sharing
     * grandparents I1 + I2, one completely unrelated couple. Couples whose
     * spouses lack recorded parents do not contribute to either side of the
     * ratio.
     */
    #[Test]
    public function summaryMatchesAcceptanceScenario(): void
    {
        $tree   = $this->importFixtureTree('endogamy.ged');
        $result = (new EndogamyRepository($tree, new ParentMapRepository($tree)))->summary();

        self::assertNotNull($result);
        self::assertSame(2, $result->total);
        self::assertSame(1, $result->endogamous);
        self::assertSame(50.0, $result->rate);
        self::assertSame(EndogamyRepository::DEFAULT_DEPTH, $result->depth);
    }
}
