<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the {@see DateJoin::on()} helper writes the four standard join
 * conditions in the expected order. The base block keeps EVERY calendar — it
 * guards on `d_julianday1 <> 0` (a resolvable date) rather than on `d_type`, so
 * a non-Gregorian date is no longer dropped at the join and an optional stricter
 * julian-day predicate / full-date gate layers on top. Exercises an in-memory
 * SQLite query builder so the produced SQL fragments stay close to what the real
 * Eloquent driver emits.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(DateJoin::class)]
final class DateJoinTest extends TestCase
{
    private Capsule $capsule;

    /**
     * Spin up a fresh in-memory SQLite via Eloquent's Capsule. The helper
     * writes against a JoinClause, so the connection is only needed to
     * materialise the SQL string for assertion.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->capsule = new Capsule();
        $this->capsule->addConnection(['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => 'wt_']);
        $this->capsule->setAsGlobal();
        $this->capsule->bootEloquent();
    }

    /**
     * The base block writes the four core conditions: file column join,
     * GEDCOM-id column join, fact filter, and the always-on `d_julianday1 <> 0`
     * resolvable-date guard. No `d_type` calendar filter appears (every calendar
     * is kept), and no optional stricter julian-day predicate is emitted.
     */
    #[Test]
    public function onWritesBaseConditionsWithResolvableDateGuard(): void
    {
        $join = new JoinClause($this->capsule->getConnection()->query(), 'inner', 'dates AS birth');

        DateJoin::on($join, 'birth', 'i_file', 'i_id', 'BIRT');

        $sql = $join->toSql();

        self::assertStringContainsString('"wt_birth"."d_file" = "i_file"', $sql);
        self::assertStringContainsString('"wt_birth"."d_gid" = "i_id"', $sql);
        self::assertStringContainsString('"wt_birth"."d_fact" = ?', $sql);
        self::assertStringContainsString('"wt_birth"."d_julianday1" <> ?', $sql);
        self::assertStringNotContainsString('d_type', $sql);
        self::assertSame(['BIRT', 0], $join->getBindings());
    }

    /**
     * Passing {@see DateJoin::JD_GREATER_THAN_ZERO} appends a second, stricter
     * `d_julianday1 > 0` predicate on top of the base guard — the convention the
     * deterministic-pair queries (BirthDeathPairs, age-at-first-child) rely on.
     */
    #[Test]
    public function onAppendsJulianDayGreaterThanZero(): void
    {
        $join = new JoinClause($this->capsule->getConnection()->query(), 'inner', 'dates AS death');

        DateJoin::on($join, 'death', 'i_file', 'i_id', 'DEAT', DateJoin::JD_GREATER_THAN_ZERO);

        $sql = $join->toSql();

        self::assertStringContainsString('"wt_death"."d_julianday1" > ?', $sql);
        self::assertSame(['DEAT', 0, 0], $join->getBindings());
    }

    /**
     * Passing {@see DateJoin::JD_NOT_EQUAL_ZERO} appends a second `d_julianday1
     * <> 0` predicate — redundant with the always-on base guard, kept for
     * call-site intent in the histogram queries.
     */
    #[Test]
    public function onAppendsJulianDayNotEqualZero(): void
    {
        $join = new JoinClause($this->capsule->getConnection()->query(), 'inner', 'dates AS birth');

        DateJoin::on($join, 'birth', 'f_file', 'f_husb', 'BIRT', DateJoin::JD_NOT_EQUAL_ZERO);

        $sql = $join->toSql();

        self::assertStringContainsString('"wt_birth"."d_julianday1" <> ?', $sql);
        self::assertSame(['BIRT', 0, 0], $join->getBindings());
    }

    /**
     * Passing `requireFullDate = true` appends the `d_day > 0` and `d_mon > 0`
     * predicates that the day-precision consumers (multi-birth same-day match,
     * sibling-age-gap distribution) rely on to filter year-only records,
     * month-only records, and the BEF / AFT / ABT / BET..AND / FROM..TO modifier
     * rows that webtrees writes with `d_day = 0` plus a synthesised default
     * julian-day. Both predicates must appear in the generated SQL, each with its
     * own `0` binding alongside the base and operator julian-day bindings so a
     * regression that drops one half stays detectable at the SQL-shape level
     * instead of only surfacing via downstream histogram drift.
     */
    #[Test]
    public function onAppendsFullDateGateWhenRequireFullDateIsTrue(): void
    {
        $join = new JoinClause($this->capsule->getConnection()->query(), 'inner', 'dates AS birth');

        DateJoin::on(
            $join,
            'birth',
            'l_file',
            'l_from',
            'BIRT',
            DateJoin::JD_NOT_EQUAL_ZERO,
            true,
        );

        $sql = $join->toSql();

        self::assertStringContainsString('"wt_birth"."d_julianday1" <> ?', $sql);
        self::assertStringContainsString('"wt_birth"."d_day" > ?', $sql);
        self::assertStringContainsString('"wt_birth"."d_mon" > ?', $sql);
        self::assertSame(['BIRT', 0, 0, 0, 0], $join->getBindings());
    }

    /**
     * `requireFullDate = true` may also stand on its own without a stricter
     * julian-day operator — covers consumers that want the day-precision gate but
     * not the extra `d_julianday1` constraint. The base `d_julianday1 <> 0` guard
     * is still present (it is always written) and both `d_day > 0` and
     * `d_mon > 0` are emitted.
     */
    #[Test]
    public function onAppendsFullDateGateAloneWithoutJulianDayOperator(): void
    {
        $join = new JoinClause($this->capsule->getConnection()->query(), 'inner', 'dates AS birth');

        DateJoin::on(
            $join,
            'birth',
            'i_file',
            'i_id',
            'BIRT',
            null,
            true,
        );

        $sql = $join->toSql();

        self::assertStringContainsString('"wt_birth"."d_julianday1" <> ?', $sql);
        self::assertStringContainsString('"wt_birth"."d_day" > ?', $sql);
        self::assertStringContainsString('"wt_birth"."d_mon" > ?', $sql);
        self::assertSame(['BIRT', 0, 0, 0], $join->getBindings());
    }
}
