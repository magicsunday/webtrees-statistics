<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\ParenthoodRepository;
use PHPUnit\Framework\Attributes\Test;

use function array_sum;

/**
 * End-to-end test of {@see ParenthoodRepository} against
 * `age-at-first-child.ged`:
 *
 *   F1: Anton (BIRT 1880) + Berta (BIRT 1885) → Carl (BIRT 1903)
 *       - father age 23 → bucket 20–24
 *       - mother age 18 → bucket 15–19
 *   F2: Emil (BIRT 1860) + Frieda (BIRT 1870) → Greta (BIRT 1907)
 *       - father age 47 → bucket 45–49
 *       - mother age 37 → bucket 35–39
 *   F3: Hans + Ilse, no children → excluded from both distributions
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ParenthoodRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Fathers' distribution picks up Anton (23 years → 20–24 bucket)
     * and Emil (47 years → 45–49 bucket). The childless family
     * contributes nothing.
     */
    #[Test]
    public function fathersDistributionMatchesFixture(): void
    {
        $tree   = $this->importFixtureTree('age-at-first-child.ged');
        $result = (new ParenthoodRepository($tree))->ageAtFirstChildDistribution('M');

        self::assertSame(1, $result['20–24']);
        self::assertSame(1, $result['45–49']);
        self::assertSame(0, $result['15–19'] ?? 0, 'No father in the 15–19 bucket');
        self::assertSame(0, $result['35–39'] ?? 0, 'No father in the 35–39 bucket');
    }

    /**
     * Mothers' distribution picks up Berta (18 years → 15–19 bucket)
     * and Frieda (37 years → 35–39 bucket). The childless family
     * contributes nothing.
     */
    #[Test]
    public function mothersDistributionMatchesFixture(): void
    {
        $tree   = $this->importFixtureTree('age-at-first-child.ged');
        $result = (new ParenthoodRepository($tree))->ageAtFirstChildDistribution('F');

        self::assertSame(1, $result['15–19']);
        self::assertSame(1, $result['35–39']);
        self::assertSame(0, $result['20–24'] ?? 0, 'No mother in the 20–24 bucket');
        self::assertSame(0, $result['45–49'] ?? 0, 'No mother in the 45–49 bucket');
    }

    /**
     * The family with no children must NOT contribute to either
     * sex's distribution — there is no age to compute without a
     * dated child. Total count across all buckets sums to exactly
     * the families that had a dated child.
     */
    #[Test]
    public function childlessFamiliesAreExcluded(): void
    {
        $tree    = $this->importFixtureTree('age-at-first-child.ged');
        $fathers = (new ParenthoodRepository($tree))->ageAtFirstChildDistribution('M');
        $mothers = (new ParenthoodRepository($tree))->ageAtFirstChildDistribution('F');

        self::assertSame(2, array_sum($fathers), 'Two fathers contributed; the childless father is excluded');
        self::assertSame(2, array_sum($mothers), 'Two mothers contributed; the childless mother is excluded');
    }
}
