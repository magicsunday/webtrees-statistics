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
use Illuminate\Database\SQLiteConnection;
use PDO;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * Pins the destructive-reset boundary of the integration suite: a persistent
 * test database is wiped only with the explicit opt-in and only when it is
 * empty or marked as disposable. Each rejection must leave the tables and the
 * rows of the target untouched.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class DisposableDatabaseGuardTest extends TestCase
{
    #[Test]
    public function rejectsTheResetWithoutTheOptIn(): void
    {
        $connection = $this->connectionWithData(marked: true);

        try {
            (new DisposableDatabaseGuard())->resetSchema($connection, false);
            self::fail('The reset ran without the opt-in.');
        } catch (RuntimeException $runtimeException) {
            self::assertStringContainsString('WT_TEST_DB_ALLOW_DESTRUCTIVE', $runtimeException->getMessage());
        }

        self::assertSame(1, $this->countRows($connection, 'individuals'));
        self::assertContains('wt_' . DisposableDatabaseGuard::MARKER_TABLE, $this->tables($connection));
    }

    #[Test]
    public function rejectsADatabaseThatHoldsTablesWithoutTheMarker(): void
    {
        $connection = $this->connectionWithData(marked: false);

        try {
            (new DisposableDatabaseGuard())->resetSchema($connection, true);
            self::fail('The reset ran on an unmarked database.');
        } catch (RuntimeException $runtimeException) {
            self::assertStringContainsString('wt_' . DisposableDatabaseGuard::MARKER_TABLE, $runtimeException->getMessage());
        }

        self::assertSame(1, $this->countRows($connection, 'individuals'));
    }

    #[Test]
    public function resetsAMarkedDatabaseWithTheOptIn(): void
    {
        $connection = $this->connectionWithData(marked: true);

        (new DisposableDatabaseGuard())->resetSchema($connection, true);

        self::assertSame([], $this->tables($connection));
    }

    #[Test]
    public function resetsAnEmptyDatabaseWithTheOptIn(): void
    {
        $connection = $this->connection();

        (new DisposableDatabaseGuard())->resetSchema($connection, true);

        self::assertSame([], $this->tables($connection));
    }

    #[Test]
    public function aMarkedDatabaseCanBeResetAgainAfterTheFirstReset(): void
    {
        $guard      = new DisposableDatabaseGuard();
        $connection = $this->connection();

        $guard->resetSchema($connection, true);
        $guard->markDisposable($connection);
        $this->createIndividuals($connection);

        $guard->resetSchema($connection, true);

        self::assertSame([], $this->tables($connection));
    }

    private function connection(): SQLiteConnection
    {
        return new SQLiteConnection(new PDO('sqlite::memory:'), ':memory:', 'wt_');
    }

    private function connectionWithData(bool $marked): SQLiteConnection
    {
        $connection = $this->connection();

        $this->createIndividuals($connection);

        if ($marked) {
            (new DisposableDatabaseGuard())->markDisposable($connection);
        }

        return $connection;
    }

    private function createIndividuals(Connection $connection): void
    {
        $connection->getSchemaBuilder()->create(
            'individuals',
            static function (Blueprint $table): void {
                $table->string('i_id');
            },
        );

        $connection->table('individuals')->insert(['i_id' => 'I1']);
    }

    private function countRows(Connection $connection, string $table): int
    {
        return $connection->table($table)->count();
    }

    /**
     * @return list<string>
     */
    private function tables(Connection $connection): array
    {
        return $connection->getSchemaBuilder()->getTableListing(null, false);
    }
}
