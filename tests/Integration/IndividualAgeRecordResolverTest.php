<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Support\Calc\IndividualAgeRecordResolver;
use PHPUnit\Framework\Attributes\Test;

/**
 * Verifies the {@see IndividualAgeRecordResolver} branches that the
 * repository-side record-holder integration tests cannot reach:
 * both-null and either-null short-circuits, the
 * unknown-xref-after-deletion fallback, and the happy-path
 * materialisation against a real tree. Lives under tests/Integration/
 * because the resolver calls `Registry::individualFactory()->make()`
 * which needs the webtrees container booted by IntegrationTestCase.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class IndividualAgeRecordResolverTest extends IntegrationTestCase
{
    /**
     * Both inputs null collapses to null — `null + null` shorts out
     * before the Registry lookup so a tree-less caller is safe.
     */
    #[Test]
    public function resolveReturnsNullWhenBothInputsAreNull(): void
    {
        $tree = $this->importFixtureTree('records.ged');

        self::assertNull(IndividualAgeRecordResolver::resolve($tree, null, null));
    }

    /**
     * `years === null` short-circuits even when a valid xref is present.
     * Mirrors the case where the pair-iterator never picked a winning
     * candidate (empty tree, all candidates filtered).
     */
    #[Test]
    public function resolveReturnsNullWhenYearsIsNull(): void
    {
        $tree = $this->importFixtureTree('records.ged');

        self::assertNull(IndividualAgeRecordResolver::resolve($tree, 'I1', null));
    }

    /**
     * `xref === null` short-circuits even when a numeric age is present.
     */
    #[Test]
    public function resolveReturnsNullWhenXrefIsNull(): void
    {
        $tree = $this->importFixtureTree('records.ged');

        self::assertNull(IndividualAgeRecordResolver::resolve($tree, null, 42));
    }

    /**
     * Xref that no longer resolves to a live Individual collapses to
     * null — covers the post-deletion race where the candidate row
     * still points at a tombstone.
     */
    #[Test]
    public function resolveReturnsNullForUnknownXref(): void
    {
        $tree = $this->importFixtureTree('records.ged');

        self::assertNull(IndividualAgeRecordResolver::resolve($tree, 'I9999', 42));
    }

    /**
     * Happy path: a known xref + an integer age materialises the DTO
     * with both fields populated and the live Individual reachable
     * via the public property.
     */
    #[Test]
    public function resolveBuildsRecordForKnownXref(): void
    {
        $tree   = $this->importFixtureTree('records.ged');
        $record = IndividualAgeRecordResolver::resolve($tree, 'I1', 110);

        self::assertNotNull($record);
        self::assertSame('I1', $record->individual->xref());
        self::assertSame(110, $record->ageYears);
    }
}
