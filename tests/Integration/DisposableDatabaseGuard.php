<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use RuntimeException;

use function in_array;

/**
 * Decides whether the integration suite may wipe a database on a persistent
 * server. The reset drops every table of the configured database, so it runs
 * only when two things hold. The caller opted in explicitly, and the database
 * is either empty or carries the marker table that this class creates after the
 * first reset. A database that already holds tables without the marker is never
 * touched, whatever its name, because a name convention does not prove that the
 * database is disposable.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DisposableDatabaseGuard
{
    /**
     * Name of the marker table, without the connection's table prefix.
     */
    public const string MARKER_TABLE = 'disposable_test_database';

    /**
     * Drop every table of the database after the checks passed.
     *
     * @param Connection $connection Connection to the database that is about to be reset
     * @param bool       $optedIn    Whether the caller explicitly allowed destructive resets
     *
     * @throws RuntimeException When the opt-in is missing or the database is not marked as disposable
     */
    public function resetSchema(Connection $connection, bool $optedIn): void
    {
        $this->assertMayReset($connection, $optedIn);

        $connection->getSchemaBuilder()->dropAllTables();
    }

    /**
     * Mark the freshly built database as disposable, so a later run may reset it again.
     *
     * @param Connection $connection Connection to the database that was just reset
     */
    public function markDisposable(Connection $connection): void
    {
        $connection->getSchemaBuilder()->create(
            self::MARKER_TABLE,
            static function (Blueprint $table): void {
                $table->integer('id');
            },
        );
    }

    /**
     * Refuse the reset unless the opt-in is given and the database is empty or marked.
     *
     * @param Connection $connection Connection to the database that is about to be reset
     * @param bool       $optedIn    Whether the caller explicitly allowed destructive resets
     *
     * @throws RuntimeException When the opt-in is missing or the database is not marked as disposable
     */
    private function assertMayReset(Connection $connection, bool $optedIn): void
    {
        if (!$optedIn) {
            throw new RuntimeException(
                'Refusing to reset a persistent test database: set WT_TEST_DB_ALLOW_DESTRUCTIVE=1 to allow it.',
            );
        }

        // Only the configured database counts, the same set that the reset drops.
        // A listing without a schema also returns the tables of every other
        // database the account can see.
        $schemaBuilder = $connection->getSchemaBuilder();
        $tables        = $schemaBuilder->getTableListing($schemaBuilder->getCurrentSchemaListing(), false);

        if ($tables === []) {
            return;
        }

        if (!in_array($connection->getTablePrefix() . self::MARKER_TABLE, $tables, true)) {
            throw new RuntimeException(
                'Refusing to reset a database that holds tables but no marker table "'
                . $connection->getTablePrefix() . self::MARKER_TABLE
                . '". Point WT_TEST_DB_NAME at an empty database that is used for tests only.',
            );
        }
    }
}
