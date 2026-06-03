<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Model\Metric\RecordInventory;
use MagicSunday\Webtrees\Statistic\Repository\RecordInventoryRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Integration tests for {@see RecordInventoryRepository} against
 * `record-inventory.ged`, which carries a known mix of record types:
 *
 *   Core        — 3 individuals, 1 family.
 *   Enrichment  — 2 sources, 5 media objects, 1 note, 1 shared note, 1
 *                 repository, 1 shared location.
 *
 * One of the five media objects carries two photo files, so the tree holds 5
 * media objects but 6 media files: a tombstone, an untyped file, and four photos
 * (two standalone + two on the multi-file object). This is the object-vs-file
 * divergence the by-type card surfaces — `getRecordInventory()->media` counts
 * objects, `getMediaByType()` counts files. Expected enrichment total 11 over 3
 * individuals → density 367 per 100.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(RecordInventoryRepository::class)]
#[UsesClass(RecordInventory::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class RecordInventoryRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * Every record type is counted from its own normalised table, the four
     * enrichment `other` types are split by `o_type`, and the serialised shape
     * is the wire contract the inventory card consumes.
     */
    #[Test]
    public function countsCoreAndEnrichmentRecordsByType(): void
    {
        $tree      = $this->importFixtureTree('record-inventory.ged');
        $inventory = (new RecordInventoryRepository($tree))->getRecordInventory();

        self::assertSame(3, $inventory->individuals);
        self::assertSame(1, $inventory->families);
        self::assertSame(2, $inventory->sources);
        self::assertSame(5, $inventory->media, 'OBJE records, counted regardless of how many files each carries');
        self::assertSame(1, $inventory->notes);
        self::assertSame(1, $inventory->sharedNotes);
        self::assertSame(1, $inventory->repositories);
        self::assertSame(1, $inventory->locations);

        // 2 + 5 + 1 + 1 + 1 + 1 = 11 enrichment records over 3 individuals → 367 per 100.
        self::assertSame(367, $inventory->enrichmentDensity());

        self::assertSame(
            [
                'individuals'       => 3,
                'families'          => 1,
                'sources'           => 2,
                'media'             => 5,
                'notes'             => 1,
                'sharedNotes'       => 1,
                'repositories'      => 1,
                'locations'         => 1,
                'enrichmentDensity' => 367,
            ],
            $inventory->jsonSerialize(),
        );
    }

    /**
     * Media objects are grouped by their recorded source-media type at the FILE
     * level, most frequent first, keyed by the raw `source_media_type` token as
     * webtrees stores it (the GEDCOM `TYPE` value, upper-cased on import). The
     * four photo files (two standalone + two on the multi-file object) outrank
     * the single tombstone; the untyped file surfaces under the empty token,
     * which sorts ahead of the tombstone on the alphabetical tie-break. The view
     * canonicalises each token to look up its translated label, so the storage
     * casing is opaque to the consumer.
     */
    #[Test]
    public function groupsMediaByRecordedSourceMediaTypeAtFileLevel(): void
    {
        $tree       = $this->importFixtureTree('record-inventory.ged');
        $mediaTypes = (new RecordInventoryRepository($tree))->getMediaByType();

        // Six media files (4 + 1 + 1) across five media objects: the breakdown
        // counts files, not objects, because the source-media type is a per-file
        // attribute. getRecordInventory()->media stays at the object count (5).
        self::assertSame(
            [
                'PHOTO'     => 4,
                ''          => 1,
                'TOMBSTONE' => 1,
            ],
            $mediaTypes,
        );
    }

    /**
     * An empty tree yields zero across every record type and a zero enrichment
     * density rather than a division error, and no media types.
     */
    #[Test]
    public function emptyTreeYieldsZeroInventory(): void
    {
        $tree      = $this->importFixtureTree('empty-tree.ged');
        $inventory = (new RecordInventoryRepository($tree))->getRecordInventory();

        self::assertSame(0, $inventory->individuals);
        self::assertSame(0, $inventory->enrichmentDensity());
        self::assertSame([], (new RecordInventoryRepository($tree))->getMediaByType());
    }
}
