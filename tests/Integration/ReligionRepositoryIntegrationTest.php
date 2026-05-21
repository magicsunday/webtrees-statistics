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
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end test of {@see ReligionRepository}. The shared
 * `individual-facts.ged` fixture carries four individuals with
 * RELI facts (Anna, Berta — case variant, Carl, Emil) and three
 * without (Doris, Franz, Gerda). The aggregation must collapse
 * case variants and rank descending by frequency.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ReligionRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * `Katholisch` appears twice (Anna + Berta with lowercase
     * variant `katholisch`), `Evangelisch` once (Carl), `Jüdisch`
     * once (Emil). The lowercase variant merges via case-folded keys.
     */
    #[Test]
    public function topReligionsReturnsCaseFoldedFrequencies(): void
    {
        $tree   = $this->importFixtureTree('individual-facts.ged');
        $result = (new ReligionRepository($tree))->topReligions(10);

        self::assertSame(
            ['Katholisch' => 2, 'Evangelisch' => 1, 'Jüdisch' => 1],
            $result,
        );
    }

    /**
     * Distinct count = number of case-folded religion keys.
     */
    #[Test]
    public function countDistinctReligionsReturnsTheFullKeyCount(): void
    {
        $tree   = $this->importFixtureTree('individual-facts.ged');

        self::assertSame(3, (new ReligionRepository($tree))->countDistinctReligions());
    }
}
