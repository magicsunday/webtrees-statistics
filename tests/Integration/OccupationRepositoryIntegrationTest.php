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
 * `individual-facts.ged` fixture carries seven individuals with a
 * mix of OCCU shapes: single value (Anna/Berta — case variants),
 * different value (Carl/Emil/Doris), multi-occurrence (Gerda has
 * two OCCU lines), no value (Franz). The aggregation must collapse
 * case variants under the first-seen casing and rank descending.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class OccupationRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * `Schmied` appears three times (Anna, Berta with lowercase
     * variant `schmied`, and Gerda's first OCCU line). `Bauer` twice
     * (Carl + Emil). `Lehrerin` and `Schmiedin` once each. The
     * lowercase variant `schmied` merges into the `Schmied` bucket
     * via case-folded keys, with the first-seen casing winning as
     * the display label.
     */
    #[Test]
    public function topOccupationsReturnsCaseFoldedFrequencies(): void
    {
        $tree   = $this->importFixtureTree('individual-facts.ged');
        $result = (new OccupationRepository($tree))->top(10);

        self::assertSame(
            ['Schmied' => 3, 'Bauer' => 2, 'Lehrerin' => 1, 'Schmiedin' => 1],
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

        self::assertSame(['Schmied', 'Bauer'], array_keys($result));
    }

    /**
     * Distinct count = number of case-folded keys, independent of
     * top-N truncation.
     */
    #[Test]
    public function countDistinctOccupationsReturnsTheFullKeyCount(): void
    {
        $tree = $this->importFixtureTree('individual-facts.ged');

        self::assertSame(4, (new OccupationRepository($tree))->countDistinct());
    }
}
