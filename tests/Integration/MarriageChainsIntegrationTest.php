<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\MarriageMapRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\MarriageChains;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;
use function count;

/**
 * End-to-end test of {@see MarriageChains} against the `partner-chains.ged`
 * fixture, fed through the real {@see MarriageMapRepository}. The fixture
 * encodes exactly two marriage groups of three-plus people: a large 41-person
 * web of 40 marriages and a smaller 11-person chain; every other family is a
 * lone couple that the three-person threshold drops.
 *
 * Expected: largest group = 41 members / 40 edges, qualifying component sizes
 * = [41, 11].
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(MarriageChains::class)]
#[UsesClass(MarriageMapRepository::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class MarriageChainsIntegrationTest extends IntegrationTestCase
{
    /**
     * The largest connected marriage group in the fixture spans 41 people
     * joined by 40 marriages.
     */
    #[Test]
    public function largestGroupHas41MembersAnd40Edges(): void
    {
        $tree      = $this->importFixtureTree('partner-chains.ged');
        $adjacency = (new MarriageMapRepository($tree))->build();
        $largest   = MarriageChains::largestGroup($adjacency);

        self::assertNotNull($largest);
        self::assertCount(41, $largest['members'], 'partner-chains.ged largest marriage web spans 41 people');
        self::assertSame(40, $largest['edges'], 'partner-chains.ged largest marriage web has 40 marriages');
    }

    /**
     * Only two groups clear the three-person threshold, of sizes 41 and 11;
     * every other family in the fixture is a lone couple that is dropped.
     */
    #[Test]
    public function qualifyingComponentSizesAreFortyOneAndEleven(): void
    {
        $tree       = $this->importFixtureTree('partner-chains.ged');
        $adjacency  = (new MarriageMapRepository($tree))->build();
        $components = MarriageChains::components($adjacency);

        self::assertSame(
            [41, 11],
            array_map(count(...), $components),
            'partner-chains.ged has exactly two groups of 3+ people: a 41-person web and an 11-person chain',
        );
    }
}
