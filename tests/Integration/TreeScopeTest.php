<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use InvalidArgumentException;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;

/**
 * Verifies {@see TreeScope::table()} against an actual webtrees DB connection:
 * the per-tree filter renders into the produced SQL, the alias overload
 * qualifies the column correctly, and an unknown table raises an explicit error
 * instead of silently skipping the tree scope.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(TreeScope::class)]
final class TreeScopeTest extends AbstractIntegrationTestCase
{
    /**
     * Without an alias the helper emits the bare `<x>_file` qualifier and binds
     * the tree's primary-key id.
     */
    #[Test]
    public function tableEmitsBareFileColumnForKnownTable(): void
    {
        $tree    = $this->importFixtureTree('records.ged');
        $builder = TreeScope::table($tree, 'individuals');

        // Portable: the tree-id binding is identical on every driver, so assert
        // it on the MySQL lane too — it proves the per-tree filter binds the
        // right tree id, the cross-tree-leak class this lane exists to guard.
        self::assertSame([$tree->id()], $builder->getBindings());

        // Driver-specific: only the SQLite grammar double-quotes identifiers.
        $this->skipUnlessSqlite('Asserts the SQLite-quoted compiled SQL string');

        self::assertSame('select * from "wt_individuals" where "i_file" = ?', $builder->toSql());
    }

    /**
     * With an alias the helper rewrites the FROM clause to `<table> AS <alias>`
     * and qualifies the where-column with the alias prefix so the caller's join
     * chain stays unambiguous.
     */
    #[Test]
    public function tableEmitsAliasQualifiedFileColumn(): void
    {
        $tree    = $this->importFixtureTree('records.ged');
        $builder = TreeScope::table($tree, 'families', 'fam');

        // Portable: assert the alias overload binds the right tree id on every
        // driver before skipping the SQLite-specific string checks below.
        self::assertSame([$tree->id()], $builder->getBindings());

        // Driver-specific: only the SQLite grammar double-quotes identifiers.
        $this->skipUnlessSqlite('Asserts the SQLite-quoted compiled SQL string');

        $sql = $builder->toSql();

        self::assertStringContainsString('from "wt_families" as "wt_fam"', $sql);
        self::assertStringContainsString('where "wt_fam"."f_file" = ?', $sql);
    }

    /**
     * Unknown tables raise an explicit `InvalidArgumentException` — pinning the
     * helper's failure mode so a typo in a calling repository fails loudly at
     * call time rather than producing a tree-id-less query that scans every
     * gedcom in the database.
     */
    #[Test]
    public function tableRejectsUnknownTables(): void
    {
        $tree = $this->importFixtureTree('records.ged');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote('Unknown per-tree table "source"', '/') . '/');

        TreeScope::table($tree, 'source');
    }
}
