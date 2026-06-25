<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\DB;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeySample;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RecordName;
use MagicSunday\Webtrees\Statistic\Support\Sankey\SankeySampleResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function is_string;

/**
 * End-to-end test of the privacy-safe Sankey sample resolver against a real
 * tree. The resolver routes a flow contribution through the record factory so
 * the surfaced name passes the same `canShow()` gate as the rest of the UI:
 * a visible individual yields a `SankeySample`, while a record the current user
 * cannot see yields `null` so the caller can drop it and promote the next
 * contributor.
 *
 * The `make()` call always receives the already-loaded row GEDCOM, so a record
 * is always built — the factory never returns `null` here. The resolver's
 * `!Individual` guard is therefore a static-analysis type guard for the
 * factory's nullable return rather than a reachable runtime branch, and is not
 * exercised separately.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(SankeySampleResolver::class)]
#[UsesClass(SankeySample::class)]
#[UsesClass(RecordName::class)]
final class SankeySampleResolverIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * A visible individual resolves to a sample carrying its plain-text name and
     * its XREF. The importing admin sees every record, so the public Anton
     * resolves to his real name.
     */
    #[Test]
    public function resolveReturnsASampleForAVisibleIndividual(): void
    {
        $tree = $this->importFixtureTree('migration-flows-privacy.ged');

        $sample = SankeySampleResolver::resolve($tree, 'I1', $this->gedcomOf($tree, 'I1'));

        self::assertInstanceOf(SankeySample::class, $sample);
        self::assertSame('Anton Public', $sample->name);
        self::assertSame('I1', $sample->xref);
    }

    /**
     * A record the current user cannot see resolves to `null` — the `1 RESN
     * confidential` Berta is hidden from an anonymous visitor, so her name is
     * never built into a sample. Returning `null` is what lets the caller drop
     * the private contributor and promote the next one.
     */
    #[Test]
    public function resolveReturnsNullForARecordTheUserCannotSee(): void
    {
        $tree = $this->importFixtureTree('migration-flows-privacy.ged');
        $tree->setPreference('HIDE_LIVE_PEOPLE', '1');
        $tree->setPreference('SHOW_DEAD_PEOPLE', (string) Auth::PRIV_PRIVATE);

        $gedcom = $this->gedcomOf($tree, 'I2');

        // Control: as the importing admin the confidential record IS visible,
        // so the gate — not a missing record — is what suppresses it.
        self::assertInstanceOf(SankeySample::class, SankeySampleResolver::resolve($tree, 'I2', $gedcom));

        Auth::logout();

        self::assertNull(SankeySampleResolver::resolve($tree, 'I2', $gedcom));
    }

    /**
     * Read an individual's raw GEDCOM body from the tree, mirroring the row the
     * aggregators already hold when they call the resolver.
     */
    private function gedcomOf(Tree $tree, string $xref): string
    {
        $gedcom = DB::table('individuals')
            ->where('i_id', '=', $xref)
            ->where('i_file', '=', $tree->id())
            ->value('i_gedcom');

        return is_string($gedcom) ? $gedcom : '';
    }
}
