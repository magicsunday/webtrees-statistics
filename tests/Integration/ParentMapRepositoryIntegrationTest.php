<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * End-to-end test of {@see ParentMapRepository} against `parent-map-multi-famc.ged`,
 * focused on the deterministic resolution of a child carrying MORE THAN ONE
 * `FAMC` link (a birth family plus an adoptive/secondary family):
 *
 *   I1 (Child A) → F2 [father I11, mother I12] and F1 [father I10 only]
 *   I2 (Child B) → F4 [both], F3 [both] and F5 [both] — a three-way tie
 *   I3 (Child C) → F6 [father I20 only] and F7 [no parents]
 *
 * The previous implementation assigned the parent pair unconditionally per
 * `FAMC` row with no ordering, so the LAST family in the database's return order
 * won — an arbitrary, engine-dependent choice. The repository now keeps the
 * family carrying the most parent information (both > one > none) and breaks
 * ties on the lexicographically lowest family xref (via the ascending
 * `ORDER BY l_to` scan), so the result is stable regardless of storage or
 * return order.
 *
 * The fixture deliberately lists each child's `FAMC` links so that BOTH failure
 * modes are caught — the retired last-write-wins bug (which picked the last row)
 * and a removal of the deterministic `ORDER BY` (which would fall back to the
 * first-seen row):
 *
 *   - I1 names the info-winner F2 BEFORE the father-only F1, so last-write would
 *     wrongly pick F1 → guards the parent-information scoring.
 *   - I2 lists its three equal-score families as F4, F3, F5, placing the winner
 *     F3 (lowest xref) in the MIDDLE: last-write would pick the last row F5 and
 *     a dropped `ORDER BY` would pick the first row F4 — only the ascending scan
 *     yields F3 → guards the tie-break AND its determinism at once.
 *   - I3 names the one-parent F6 BEFORE the no-parent F7, so last-write would
 *     wrongly pick F7 → guards the "any parent beats none" scoring level.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ParentMapRepository::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class ParentMapRepositoryIntegrationTest extends AbstractIntegrationTestCase
{
    /**
     * A child with two `FAMC` families resolves to the one carrying the most
     * parent information — even when that family has the HIGHER xref, so the
     * choice cannot be explained by xref order alone. Child A's F1 records only
     * a father, while F2 records both parents; F2 wins. The fixture lists F2
     * before F1, so the retired last-write-wins code would pick the father-only
     * F1 — this assertion therefore fails without the parent-information scoring.
     */
    #[Test]
    public function buildPrefersTheFamilyWithMoreParentInformation(): void
    {
        $tree     = $this->importFixtureTree('parent-map-multi-famc.ged');
        $parentOf = (new ParentMapRepository($tree))->build();

        self::assertArrayHasKey('I1', $parentOf);
        self::assertSame(['I11', 'I12'], $parentOf['I1']);
    }

    /**
     * When several `FAMC` families carry equal parent information the
     * lexicographically lowest family xref wins deterministically. Child B's F3,
     * F4 and F5 all record both parents, so F3 (the lowest xref) is chosen on
     * every run, independent of the database's return order. The fixture lists
     * them F4, F3, F5 — placing the winner F3 in the MIDDLE — so neither the
     * retired last-write-wins code (which would pick the last row F5) nor a
     * dropped `ORDER BY` (which would pick the first row F4) can produce F3; only
     * the ascending `ORDER BY l_to` scan does.
     */
    #[Test]
    public function buildBreaksEqualInformationTiesOnTheLowestFamilyXref(): void
    {
        $tree     = $this->importFixtureTree('parent-map-multi-famc.ged');
        $parentOf = (new ParentMapRepository($tree))->build();

        self::assertArrayHasKey('I2', $parentOf);
        self::assertSame(['I13', 'I14'], $parentOf['I2']);
    }

    /**
     * A family recording a single parent outranks one recording no parents at
     * all — the middle level of the "both > one > none" scoring rule. Child C's
     * F6 names one father while F7 names no parents; F6 wins. The fixture lists
     * F6 before the no-parent F7, so the retired last-write-wins code would pick
     * the empty F7 — this assertion therefore fails without the scoring.
     */
    #[Test]
    public function buildPrefersAnyParentOverAFamilyWithNoParents(): void
    {
        $tree     = $this->importFixtureTree('parent-map-multi-famc.ged');
        $parentOf = (new ParentMapRepository($tree))->build();

        self::assertArrayHasKey('I3', $parentOf);
        self::assertSame(['I20', null], $parentOf['I3']);
    }

    /**
     * A malformed family that lists the child itself as its own parent (a shape
     * reachable both through an imported third-party GEDCOM and through the core
     * "Change family members" editor, neither of which enforces parent ≠ child)
     * must never out-score or displace a valid family. Child D's F8 names the
     * child I4 as BOTH spouses (would naively score 2), while F9 names one real
     * father I21 (score 1); the self-referential slots are dropped, so F8 scores
     * 0 and the child resolves to the valid F9 — never to itself.
     */
    #[Test]
    public function buildDropsSelfReferentialParentsSoAMalformedFamilyCannotWin(): void
    {
        $tree     = $this->importFixtureTree('parent-map-multi-famc.ged');
        $parentOf = (new ParentMapRepository($tree))->build();

        self::assertArrayHasKey('I4', $parentOf);
        self::assertSame(['I21', null], $parentOf['I4']);
    }

    /**
     * A parent duplicated across BOTH spouse slots of one family (a non-child
     * person recorded as both HUSB and WIFE — another shape the editor and GEDCOM
     * import permit) is one parent's worth of information, not two. Child E's
     * only family F10 names I30 as both spouses; the duplicate is collapsed, so
     * the child resolves to a single father `['I30', null]`, never a doubled
     * `['I30', 'I30']`. Without the dedup the mother slot would keep the second
     * I30 and this assertion fails.
     */
    #[Test]
    public function buildDeduplicatesAParentRepeatedAcrossBothSpouseSlots(): void
    {
        $tree     = $this->importFixtureTree('parent-map-multi-famc.ged');
        $parentOf = (new ParentMapRepository($tree))->build();

        self::assertArrayHasKey('I5', $parentOf);
        self::assertSame(['I30', null], $parentOf['I5']);
    }

    /**
     * The parents that are NOT chosen for a multi-FAMC child never leak into the
     * child's resolved pair: only the selected family's members are present, and
     * the discarded family's parents do not appear alongside them.
     */
    #[Test]
    public function buildDoesNotLeakTheDiscardedFamilyParents(): void
    {
        $tree     = $this->importFixtureTree('parent-map-multi-famc.ged');
        $parentOf = (new ParentMapRepository($tree))->build();

        self::assertArrayHasKey('I1', $parentOf);
        self::assertArrayHasKey('I2', $parentOf);

        // I1 resolved to F2, so the F1-only father I10 must not appear as I1's parent.
        self::assertNotContains('I10', $parentOf['I1']);

        // I2 resolved to F3, so no member of the discarded F4 or F5 is surfaced for I2.
        self::assertNotContains('I15', $parentOf['I2']);
        self::assertNotContains('I16', $parentOf['I2']);
        self::assertNotContains('I18', $parentOf['I2']);
        self::assertNotContains('I19', $parentOf['I2']);
    }
}
