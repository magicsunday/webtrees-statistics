<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use MagicSunday\Webtrees\Statistic\Model\Metric\RecordInventory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the {@see RecordInventory} value object: the enrichment-density
 * calculation, its divide-by-zero guard, and the serialised wire contract — all
 * independent of the database.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(RecordInventory::class)]
final class RecordInventoryTest extends TestCase
{
    /**
     * The density is the enrichment total per 100 individuals, rounded to a
     * whole number: 118 enrichment records over 100 individuals → 118.
     */
    #[Test]
    public function densityIsEnrichmentRecordsPerHundredIndividuals(): void
    {
        $inventory = new RecordInventory(100, 40, 50, 30, 25, 3, 10);

        // 50 + 30 + 25 + 3 + 10 = 118 enrichment records over 100 individuals.
        self::assertSame(118, $inventory->enrichmentDensity());
    }

    /**
     * Rounding is to the nearest whole number: 10 enrichment records over 3
     * individuals is 333.33 per 100 → 333.
     */
    #[Test]
    public function densityRoundsToTheNearestWholeNumber(): void
    {
        $inventory = new RecordInventory(3, 1, 2, 4, 2, 1, 1);

        // 2 + 4 + 2 + 1 + 1 = 10 enrichment records over 3 individuals → 333.33 → 333.
        self::assertSame(333, $inventory->enrichmentDensity());
    }

    /**
     * A tree with enrichment records but no individuals must not divide by zero;
     * the guard returns 0 rather than raising an error. This is the case the
     * empty-tree integration fixture cannot reach, because it has no enrichment
     * records either.
     */
    #[Test]
    public function densityGuardsAgainstZeroIndividualsWithEnrichmentPresent(): void
    {
        $inventory = new RecordInventory(0, 0, 2, 4, 1, 1, 1);

        self::assertSame(0, $inventory->enrichmentDensity());
    }

    /**
     * The serialised shape carries every record count plus the derived density —
     * the wire contract any JSON consumer of the inventory depends on.
     */
    #[Test]
    public function serialisesEveryCountPlusTheDerivedDensity(): void
    {
        $inventory = new RecordInventory(100, 40, 50, 30, 25, 3, 10);

        self::assertSame(
            [
                'individuals'       => 100,
                'families'          => 40,
                'sources'           => 50,
                'media'             => 30,
                'sharedNotes'       => 25,
                'repositories'      => 3,
                'locations'         => 10,
                'enrichmentDensity' => 118,
            ],
            $inventory->jsonSerialize(),
        );
    }
}
