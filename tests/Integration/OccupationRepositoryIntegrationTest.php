<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\OccupationRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_keys;

/**
 * End-to-end test of {@see OccupationRepository}. The shared
 * `individual-facts.ged` fixture carries seven individuals with a mix of OCCU
 * shapes: single value (Anna/Berta — case variants), different value
 * (Carl/Emil/Doris), multi-occurrence (Gerda has two OCCU lines), no value
 * (Franz). The aggregation must collapse case variants under the first-seen
 * casing and rank descending.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class OccupationRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * `Blacksmith` appears three times (Anna, Berta with lowercase variant
     * `blacksmith`, and Gerda's first OCCU line). `Farmer` twice (Carl + Emil).
     * `Teacher` and `Carpenter` once each. The lowercase variant `blacksmith`
     * merges into the `Blacksmith` bucket via case-folded keys, with the
     * first-seen casing winning as the display label.
     */
    #[Test]
    public function topOccupationsReturnsCaseFoldedFrequencies(): void
    {
        $tree   = $this->importFixtureTree('individual-facts.ged');
        $result = (new OccupationRepository($tree))->top(10);

        self::assertSame(
            ['Blacksmith' => 3, 'Farmer' => 2, 'Teacher' => 1, 'Carpenter' => 1],
            $result,
        );
    }

    /**
     * A top-N limit truncates the tail without changing the order.
     */
    #[Test]
    public function topOccupationsRespectsTheLimit(): void
    {
        $tree   = $this->importFixtureTree('individual-facts.ged');
        $result = (new OccupationRepository($tree))->top(2);

        self::assertSame(['Blacksmith', 'Farmer'], array_keys($result));
    }

    /**
     * Distinct count = number of case-folded keys, independent of top-N
     * truncation.
     */
    #[Test]
    public function countDistinctOccupationsReturnsTheFullKeyCount(): void
    {
        $tree = $this->importFixtureTree('individual-facts.ged');

        self::assertSame(4, (new OccupationRepository($tree))->countDistinct());
    }
}
