<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Enum\MaritalBucket;
use MagicSunday\Webtrees\Statistic\Repository\FamilyRepository;
use PHPUnit\Framework\Attributes\Test;

/**
 * End-to-end test of the marital-status classifier against a curated
 * GEDCOM fixture loaded into an in-memory SQLite database. Mirrors what
 * webtrees core does in its own TestCase: bootstrap the schema, import
 * the records, then exercise the repository against the real tables.
 *
 * The fixture contains the four marital buckets at their boundaries:
 * a living couple (current × 2), a widow with deceased spouse
 * (widowed × 1), a divorced couple (divorced × 2), and two unpartnered
 * individuals (single × 1 living, single × 1 deceased).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class FamilyRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Sum of the four buckets must equal the living count, the canonical
     * invariant the classifier promises.
     */
    #[Test]
    public function classifyLivingIndividualsMatchesTheCuratedFixture(): void
    {
        $tree    = $this->importFixtureTree('marital-status.ged');
        $buckets = (new FamilyRepository($tree))->classifyLivingIndividuals();

        self::assertSame(
            [
                MaritalBucket::Current->value  => 2,
                MaritalBucket::Divorced->value => 2,
                MaritalBucket::Widowed->value  => 1,
                MaritalBucket::Single->value   => 1,
            ],
            $buckets,
            'Four bucket counts must match the curated fixture',
        );
    }
}
