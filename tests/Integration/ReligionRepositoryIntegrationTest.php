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

    /**
     * Event-bound `2 RELI` sub-tags under BAPM / CONF / etc. are
     * picked up alongside top-level `1 RELI` so a tree imported from
     * church books (where the affiliation lives only on the
     * baptism / confirmation event, not as a free-standing religion
     * fact) still surfaces a meaningful Top-N.
     *
     * Fixture composition:
     *   I1: 1 BAPM / 2 RELI evangelisch-lutherisch                     → +1 lutherisch sub
     *   I2: 1 BAPM / 2 RELI evangelisch-lutherisch + 1 CONF / 2 RELI … → +2 sub
     *   I3: 1 RELI Katholisch + 1 BAPM / 2 RELI Katholisch             → +1 top + +1 sub
     *   I4: 1 BAPM / 2 RELI lutherisch                                  → +1 sub
     *   I5: 1 BAPM (no RELI)                                            → 0
     */
    #[Test]
    public function topReligionsCombinesTopLevelAndEventBoundReli(): void
    {
        $tree   = $this->importFixtureTree('religion-event-bound.ged');
        $result = (new ReligionRepository($tree))->topReligions(10);

        self::assertSame(
            ['evangelisch-lutherisch' => 3, 'Katholisch' => 2, 'lutherisch' => 1],
            $result,
        );
    }
}
