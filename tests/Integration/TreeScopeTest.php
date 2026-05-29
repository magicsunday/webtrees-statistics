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
final class TreeScopeTest extends IntegrationTestCase
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

        self::assertSame('select * from "wt_individuals" where "i_file" = ?', $builder->toSql());
        self::assertSame([$tree->id()], $builder->getBindings());
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

        $sql = $builder->toSql();

        self::assertStringContainsString('from "wt_families" as "wt_fam"', $sql);
        self::assertStringContainsString('where "wt_fam"."f_file" = ?', $sql);
        self::assertSame([$tree->id()], $builder->getBindings());
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
        $this->expectExceptionMessage('Unknown per-tree table "sources"');

        TreeScope::table($tree, 'sources');
    }
}
