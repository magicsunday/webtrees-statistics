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
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the {@see DateJoin::on()} helper writes the four standard join
 * conditions in the expected order and only emits the optional `d_julianday1`
 * predicate when a jd operator is passed. Exercises an in-memory SQLite query
 * builder so the produced SQL fragments stay close to what the real Eloquent
 * driver emits.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
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
     * Without a jd operator the helper writes the four-condition core block:
     * file column join, GEDCOM-id column join, fact filter, calendar predicate.
     * No `d_julianday1` clause appears.
     */
    #[Test]
    public function onWritesFourConditionsWithoutJulianDayFilter(): void
    {
        $join = new JoinClause($this->capsule->getConnection()->query(), 'inner', 'dates AS birth');

        DateJoin::on($join, 'birth', 'i_file', 'i_id', 'BIRT');

        $sql = $join->toSql();

        self::assertStringContainsString('"wt_birth"."d_file" = "i_file"', $sql);
        self::assertStringContainsString('"wt_birth"."d_gid" = "i_id"', $sql);
        self::assertStringContainsString('"wt_birth"."d_fact" = ?', $sql);
        self::assertStringContainsString('"wt_birth"."d_type" in (?, ?)', $sql);
        self::assertStringNotContainsString('d_julianday1', $sql);
        self::assertSame(['BIRT', '@#DGREGORIAN@', '@#DJULIAN@'], $join->getBindings());
    }

    /**
     * Passing {@see DateJoin::JD_GREATER_THAN_ZERO} appends the `d_julianday1 >
     * 0` predicate that the deterministic-pair queries (BirthDeathPairs,
     * age-at-first-child) rely on.
     */
    #[Test]
    public function onAppendsJulianDayGreaterThanZero(): void
    {
        $join = new JoinClause($this->capsule->getConnection()->query(), 'inner', 'dates AS death');

        DateJoin::on($join, 'death', 'i_file', 'i_id', 'DEAT', DateJoin::JD_GREATER_THAN_ZERO);

        $sql = $join->toSql();

        self::assertStringContainsString('"wt_death"."d_julianday1" > ?', $sql);
        self::assertSame(['DEAT', '@#DGREGORIAN@', '@#DJULIAN@', 0], $join->getBindings());
    }

    /**
     * Passing {@see DateJoin::JD_NOT_EQUAL_ZERO} appends the `d_julianday1 <>
     * 0` predicate used by the histogram queries.
     */
    #[Test]
    public function onAppendsJulianDayNotEqualZero(): void
    {
        $join = new JoinClause($this->capsule->getConnection()->query(), 'inner', 'dates AS birth');

        DateJoin::on($join, 'birth', 'f_file', 'f_husb', 'BIRT', DateJoin::JD_NOT_EQUAL_ZERO);

        $sql = $join->toSql();

        self::assertStringContainsString('"wt_birth"."d_julianday1" <> ?', $sql);
        self::assertSame(['BIRT', '@#DGREGORIAN@', '@#DJULIAN@', 0], $join->getBindings());
    }

    /**
     * Passing `requireFullDate = true` appends the `d_day > 0` and `d_mon > 0`
     * predicates that the day-precision consumers (multi-birth same-day match,
     * sibling-age-gap distribution) rely on to filter year-only records,
     * month-only records, and the BEF / AFT / ABT / BET..AND / FROM..TO
     * modifier rows that webtrees writes with `d_day = 0` plus a synthesised
     * default julian-day. Both predicates must appear in the generated SQL,
     * each with its own `0` binding alongside the existing julian-day binding
     * so a regression that drops one half stays detectable at the SQL-shape
     * level instead of only surfacing via downstream histogram drift.
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
        self::assertSame(['BIRT', '@#DGREGORIAN@', '@#DJULIAN@', 0, 0, 0], $join->getBindings());
    }

    /**
     * `requireFullDate = true` may also stand on its own without a julian-day
     * operator — covers consumers that want the day-precision gate but skip the
     * `d_julianday1` constraint. The default julian-day path must remain absent
     * while both `d_day > 0` and `d_mon > 0` are emitted.
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

        self::assertStringNotContainsString('d_julianday1', $sql);
        self::assertStringContainsString('"wt_birth"."d_day" > ?', $sql);
        self::assertStringContainsString('"wt_birth"."d_mon" > ?', $sql);
        self::assertSame(['BIRT', '@#DGREGORIAN@', '@#DJULIAN@', 0, 0], $join->getBindings());
    }
}
