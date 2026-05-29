<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\DeathCauseRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end test of {@see DeathCauseRepository}. The shared
 * `individual-facts.ged` fixture carries three individuals with a DEAT/CAUS
 * sub-fact (Anna=Cholera, Berta=Tuberculosis, Carl= Tuberculosis), one with
 * DEAT but no CAUS (Doris — must NOT contribute), and three with no DEAT at all
 * (Emil, Franz, Gerda — also must NOT contribute).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class DeathCauseRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * `Tuberculosis` appears twice (Berta + Carl), `Cholera` once (Anna). The
     * DEAT-without-CAUS individual (Doris) is silently skipped — proves the
     * sub-tag-absence branch.
     */
    #[Test]
    public function topDeathCausesReturnsAggregatedFrequencies(): void
    {
        $tree   = $this->importFixtureTree('individual-facts.ged');
        $result = (new DeathCauseRepository($tree))->top(10);

        self::assertSame(
            ['Tuberculosis' => 2, 'Cholera' => 1],
            $result,
        );
    }

    /**
     * Distinct count excludes the no-CAUS-on-DEAT case (Doris) and the no-DEAT
     * cases (Emil/Franz/Gerda).
     */
    #[Test]
    public function countDistinctDeathCausesIgnoresMissingSubTags(): void
    {
        $tree = $this->importFixtureTree('individual-facts.ged');

        self::assertSame(2, (new DeathCauseRepository($tree))->countDistinct());
    }
}
