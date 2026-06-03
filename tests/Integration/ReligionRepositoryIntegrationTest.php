<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\ReligionRepository;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\TopNAggregator;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * End-to-end test of {@see ReligionRepository}. The shared
 * `individual-facts.ged` fixture carries four individuals with RELI facts
 * (Anna, Berta — case variant, Carl, Emil) and three without (Doris, Franz,
 * Gerda). The aggregation must collapse case variants and rank descending by
 * frequency.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ReligionRepository::class)]
#[UsesClass(TopNAggregator::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(GedcomScanner::class)]
final class ReligionRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * `Catholic` appears twice (Anna + Berta with lowercase variant
     * `catholic`), `Protestant` once (Carl), `Jewish` once (Emil). The
     * lowercase variant merges via case-folded keys. The two count-1 entries
     * exercise the alphabetical tie-break: Jewish sorts before Protestant.
     */
    #[Test]
    public function topReligionsReturnsCaseFoldedFrequencies(): void
    {
        $tree   = $this->importFixtureTree('individual-facts.ged');
        $result = (new ReligionRepository($tree))->top(10);

        self::assertSame(
            ['Catholic' => 2, 'Jewish' => 1, 'Protestant' => 1],
            $result,
        );
    }

    /**
     * Distinct count = number of case-folded religion keys.
     */
    #[Test]
    public function countDistinctReligionsReturnsTheFullKeyCount(): void
    {
        $tree = $this->importFixtureTree('individual-facts.ged');

        self::assertSame(3, (new ReligionRepository($tree))->countDistinct());
    }

    /**
     * Event-bound `2 RELI` sub-tags under BAPM / CONF / etc. are picked up
     * alongside top-level `1 RELI` so a tree imported from church books (where
     * the affiliation lives only on the baptism / confirmation event, not as a
     * free-standing religion fact) still surfaces a meaningful Top-N.
     *
     * Fixture composition:
     *   I1: 1 BAPM / 2 RELI Lutheran                          → +1 sub
     *   I2: 1 BAPM / 2 RELI Lutheran + 1 CONF / 2 RELI Lutheran → +2 sub
     *   I3: 1 RELI Catholic + 1 BAPM / 2 RELI Catholic        → +1 top + +1 sub
     *   I4: 1 BAPM / 2 RELI Methodist                         → +1 sub
     *   I5: 1 BAPM (no RELI)                                  → 0
     */
    #[Test]
    public function topReligionsCombinesTopLevelAndEventBoundReli(): void
    {
        $tree   = $this->importFixtureTree('religion-event-bound.ged');
        $result = (new ReligionRepository($tree))->top(10);

        self::assertSame(
            ['Lutheran' => 3, 'Catholic' => 2, 'Methodist' => 1],
            $result,
        );
    }
}
