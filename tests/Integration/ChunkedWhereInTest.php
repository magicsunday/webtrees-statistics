<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;
use MagicSunday\Webtrees\Statistic\Support\Database\ChunkedWhereIn;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

use function array_map;
use function count;
use function range;
use function sort;

/**
 * Guards the placeholder-budget fix for issue #82: a tree-sized id list fed to a
 * single `whereIn` builds one prepared statement with one placeholder per id,
 * which overruns the database's variable ceiling (MySQL/MariaDB cap at 65535)
 * and aborts the whole statistics page. {@see ChunkedWhereIn} splits the id list
 * into bounded slices so no single round-trip exceeds the configured budget,
 * while still returning every matching row.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ChunkedWhereIn::class)]
final class ChunkedWhereInTest extends IntegrationTestCase
{
    /**
     * Seed a throwaway table with the string ids `I1`..`I<count>` so the helper
     * can be exercised against a real connection without importing a tree.
     *
     * @param int $count Number of id rows to insert
     *
     * @return list<string> The seeded ids
     */
    private function seedProbeTable(int $count): array
    {
        Capsule::schema()->create('chunk_probe', static function (Blueprint $table): void {
            $table->string('xref');
        });

        $ids = array_map(static fn (int $n): string => 'I' . $n, range(1, $count));

        foreach ($ids as $id) {
            Capsule::table('chunk_probe')->insert(['xref' => $id]);
        }

        return $ids;
    }

    /**
     * An id list larger than the chunk size must be fetched across several
     * round-trips — each bounded to the chunk size — and the union must equal
     * the full matching set. Asserts both halves of the contract: correctness
     * (every row returned) and the placeholder bound (no logged query carries
     * more bindings than the chunk size), which is the actual #82 regression.
     * The per-query binding bound also catches a clone that failed to isolate
     * slices (an accumulating builder would bind 3, then 6, then 7).
     */
    #[Test]
    public function splitsAcrossChunksWithoutExceedingThePlaceholderBudget(): void
    {
        $ids = $this->seedProbeTable(7);

        $base = Capsule::table('chunk_probe')->select('xref');

        Capsule::connection()->flushQueryLog();
        Capsule::connection()->enableQueryLog();

        $rows = ChunkedWhereIn::get($base, 'xref', $ids, 3);

        $log = Capsule::connection()->getQueryLog();

        // ceil(7 / 3) = 3 round-trips, with chunk sizes 3, 3, 1.
        self::assertCount(3, $log, 'Seven ids at chunk size three must split into three queries');

        foreach ($log as $entry) {
            self::assertLessThanOrEqual(
                3,
                count($entry['bindings']),
                'No single query may bind more placeholders than the chunk size',
            );
        }

        $returned = $rows->pluck('xref')->all();
        sort($returned);

        self::assertSame($ids, $returned, 'The union across chunks must equal the full matching set');
    }

    /**
     * An id list no larger than the chunk size is a single round-trip and still
     * returns every matching row.
     */
    #[Test]
    public function fetchesASingleChunkInOneQuery(): void
    {
        $ids = $this->seedProbeTable(3);

        $base = Capsule::table('chunk_probe')->select('xref');

        Capsule::connection()->flushQueryLog();
        Capsule::connection()->enableQueryLog();

        $rows = ChunkedWhereIn::get($base, 'xref', $ids, 10);

        self::assertCount(1, Capsule::connection()->getQueryLog(), 'Ids within one chunk need a single query');

        $returned = $rows->pluck('xref')->all();
        sort($returned);

        self::assertSame($ids, $returned);
    }

    /**
     * An empty id list short-circuits: no query is issued and the result is an
     * empty collection. Guards the caller idiom that skips a pointless
     * round-trip when nothing matches.
     */
    #[Test]
    public function emptyIdListIssuesNoQuery(): void
    {
        $this->seedProbeTable(3);

        $base = Capsule::table('chunk_probe')->select('xref');

        Capsule::connection()->flushQueryLog();
        Capsule::connection()->enableQueryLog();

        $rows = ChunkedWhereIn::get($base, 'xref', [], 10);

        self::assertCount(0, Capsule::connection()->getQueryLog(), 'An empty id list must not hit the database');
        self::assertTrue($rows->isEmpty(), 'An empty id list yields an empty collection');
    }

    /**
     * A non-positive chunk size is clamped to one id per round-trip rather than
     * letting `array_chunk` raise a `ValueError`. The result is still the full
     * matching set; the guard degrades gracefully instead of crashing.
     */
    #[Test]
    public function clampsNonPositiveChunkSizeToOneIdPerQuery(): void
    {
        $ids = $this->seedProbeTable(3);

        $base = Capsule::table('chunk_probe')->select('xref');

        Capsule::connection()->flushQueryLog();
        Capsule::connection()->enableQueryLog();

        $rows = ChunkedWhereIn::get($base, 'xref', $ids, 0);

        self::assertCount(
            3,
            Capsule::connection()->getQueryLog(),
            'A non-positive chunk size falls back to one id per query',
        );

        $returned = $rows->pluck('xref')->all();
        sort($returned);

        self::assertSame($ids, $returned, 'Clamping must still return the full matching set');
    }

    /**
     * The base query's own WHERE and SELECT must survive every per-chunk clone —
     * the repositories hand the helper a query already constrained by tree id,
     * fact type and date, plus a narrowed column list, and expect each slice to
     * honour them. A clone that dropped accumulated state would silently widen
     * the result. Seeds rows where only some satisfy the base predicate and
     * asserts that exactly those (and no others) come back across the chunk
     * boundary.
     */
    #[Test]
    public function preservesBaseQueryConstraintsAcrossChunks(): void
    {
        Capsule::schema()->create('chunk_keep', static function (Blueprint $table): void {
            $table->string('xref');
            $table->integer('keep');
        });

        $ids = array_map(static fn (int $n): string => 'I' . $n, range(1, 7));

        foreach ($ids as $index => $id) {
            // Keep the even-indexed rows (I2, I4, I6); the rest must be filtered
            // out by the base predicate, not by the id slice.
            Capsule::table('chunk_keep')->insert(['xref' => $id, 'keep' => ($index % 2 === 1) ? 1 : 0]);
        }

        $base = Capsule::table('chunk_keep')->where('keep', '=', 1)->select('xref');

        Capsule::connection()->flushQueryLog();
        Capsule::connection()->enableQueryLog();

        $rows = ChunkedWhereIn::get($base, 'xref', $ids, 2);

        // ceil(7 / 2) = 4 slices — the base predicate must hold in every one.
        self::assertCount(4, Capsule::connection()->getQueryLog(), 'Seven ids at chunk size two must split into four queries');

        $returned = $rows->pluck('xref')->all();
        sort($returned);

        self::assertSame(
            ['I2', 'I4', 'I6'],
            $returned,
            'Only rows satisfying the base WHERE may return — the predicate must survive every clone',
        );
    }
}
