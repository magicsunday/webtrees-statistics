<?php

declare(strict_types=1);

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Gedcom;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Services\GedcomImportService;
use Fisharebest\Webtrees\Services\MigrationService;
use Fisharebest\Webtrees\Services\TreeService;
use Fisharebest\Webtrees\Site;
use Fisharebest\Webtrees\Tree;
use Fisharebest\Webtrees\Webtrees;
use Illuminate\Database\Capsule\Manager as Capsule;
use PHPUnit\Framework\TestCase;

use function basename;
use function file_get_contents;
use function preg_split;

/**
 * Minimal in-memory SQLite bootstrap for tests that exercise repositories
 * against a real GEDCOM tree. Mirrors the production schema (no test-only
 * fixtures) so the assertions read the same tables the live module reads.
 *
 * Each test class gets its own fresh database via {@see setUp()} and an
 * importer that loads any GEDCOM file from `tests/fixtures/`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
abstract class IntegrationTestCase extends TestCase
{
    /**
     * Connect to an in-memory SQLite database and run every webtrees
     * migration so the production schema is in place before any test
     * body runs.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Drop any site preferences cached from a previous test —
        // MigrationService reads WT_SCHEMA_VERSION from this static and
        // a stale value would skip the schema build, leaving us with an
        // empty :memory: database that the seed phase then can't query.
        Site::$preferences = [];

        // Boot webtrees' DI container so Registry::container() resolves —
        // every service the import path touches (Log, Site, Gedcom factory)
        // looks the container up statically.
        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);

        DB::connect(
            driver: DB::SQLITE,
            host: '',
            port: '',
            database: ':memory:',
            username: '',
            password: '',
            prefix: 'wt_',
            key: '',
            certificate: '',
            ca: '',
            verify_certificate: false,
        );

        $migrationService = new MigrationService();
        $migrationService->updateSchema('\Fisharebest\Webtrees\Schema', 'WT_SCHEMA_VERSION', Webtrees::SCHEMA_VERSION);
        $migrationService->seedDatabase();

        I18N::init('en-US');

        // Element factory normally bound by webtrees' RoutingMiddleware.
        (new Gedcom())->registerTags(Registry::elementFactory(), true);
    }

    protected function tearDown(): void
    {
        DB::connection()->disconnect();

        // Wipe the static site-preferences cache so the next test's
        // fresh :memory: database does not see this run's schema version.
        Site::$preferences = [];

        parent::tearDown();
    }

    /**
     * Import a GEDCOM file from this module's `tests/fixtures/`
     * directory into a fresh tree and return the resulting Tree.
     *
     * @param string $fixture Filename relative to `tests/fixtures/`
     */
    final protected function importFixtureTree(string $fixture): Tree
    {
        $gedcomImportService = new GedcomImportService();
        $treeService         = new TreeService($gedcomImportService);
        $tree                = $treeService->create(basename($fixture, '.ged'), basename($fixture, '.ged'));

        // TreeService::create seeds a placeholder header + INDI — wipe
        // before importing the fixture so the assertions are deterministic.
        $treeId = $tree->id();
        foreach (
            [
                'individuals' => 'i_file',
                'families'    => 'f_file',
                'sources'     => 's_file',
                'other'       => 'o_file',
                'places'      => 'p_file',
                'placelinks'  => 'pl_file',
                'name'        => 'n_file',
                'dates'       => 'd_file',
                'change'      => 'gedcom_id',
                'link'        => 'l_file',
                'media_file'  => 'm_file',
                'media'       => 'm_file',
            ] as $table => $column
        ) {
            Capsule::table($table)
                ->where($column, '=', $treeId)
                ->delete();
        }

        $gedcom  = (string) file_get_contents(__DIR__ . '/../fixtures/' . $fixture);
        $records = preg_split('/\n(?=0)/', $gedcom) ?: [];

        foreach ($records as $record) {
            $gedcomImportService->importRecord($record, $tree, false);
        }

        return $tree;
    }
}
