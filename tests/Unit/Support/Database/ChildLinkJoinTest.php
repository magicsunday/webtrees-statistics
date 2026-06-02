<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Support\Database;

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Support\Database\ChildLinkJoin;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies that the {@see ChildLinkJoin::famc()} helper writes the three
 * standard FAMC link-join conditions — file column join, family-id join and the
 * `l_type = 'FAMC'` filter — on the given link alias. Exercises an in-memory
 * SQLite query builder so the produced SQL fragment stays close to what the real
 * Eloquent driver emits.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ChildLinkJoin::class)]
final class ChildLinkJoinTest extends TestCase
{
    private Capsule $capsule;

    /**
     * Spin up a fresh in-memory SQLite via Eloquent's Capsule. The helper writes
     * against a JoinClause, so the connection is only needed to materialise the
     * SQL string for assertion.
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
     * The helper joins `famc` on the `fam` family's file + id columns and pins
     * the link type to `FAMC`, leaving the child-side filter to the caller.
     */
    #[Test]
    public function famcWritesTheFileFamilyAndTypeConditions(): void
    {
        $join = new JoinClause($this->capsule->getConnection()->query(), 'inner', 'link AS famc');

        ChildLinkJoin::famc($join);

        $sql = $join->toSql();

        self::assertStringContainsString('"wt_famc"."l_file" = "wt_fam"."f_file"', $sql);
        self::assertStringContainsString('"wt_famc"."l_to" = "wt_fam"."f_id"', $sql);
        self::assertStringContainsString('"wt_famc"."l_type" = ?', $sql);
        self::assertSame(['FAMC'], $join->getBindings());
    }
}
