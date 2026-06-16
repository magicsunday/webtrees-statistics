<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\DB;
use MagicSunday\Webtrees\Statistic\Model\Ranking\RankingEntry;
use MagicSunday\Webtrees\Statistic\Model\Tree\GenerationDepthReport;
use MagicSunday\Webtrees\Statistic\Repository\GenerationDepthRepository;
use MagicSunday\Webtrees\Statistic\Repository\ParentMapRepository;
use MagicSunday\Webtrees\Statistic\Support\Calc\GenerationDepth;
use MagicSunday\Webtrees\Statistic\Support\Database\ChunkedWhereIn;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

use function array_map;
use function array_search;
use function count;

/**
 * End-to-end test of {@see GenerationDepthRepository} against
 * `generation-depth.ged`:
 *
 *   I2 (grandparent) → I1 (parent) → I3 (child)
 *   I4 — isolated individual, not in any FAMC/FAMS relationship
 *
 * The grandparent deliberately carries the HIGHER xref (I2) so the
 * descendant-count ranking (I2=2, I1=1) reverses the xref order — a dropped
 * sort would surface the parent first. The depth distribution is keyed by
 * depth, not xref, so it is unaffected by the role/xref assignment.
 *
 * Expected:
 *   - maxDepth: 2
 *   - distribution: {0: 1 (I3), 1: 1 (I1), 2: 1 (I2)}
 *   - I4 contributes nothing (no parentage links)
 *   - capped: false
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(GenerationDepthRepository::class)]
#[UsesClass(RankingEntry::class)]
#[UsesClass(GenerationDepthReport::class)]
#[UsesClass(ParentMapRepository::class)]
#[UsesClass(GenerationDepth::class)]
#[UsesClass(ChunkedWhereIn::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class GenerationDepthRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * The summary captures the longest descent and the distribution of
     * individuals across generation distances. The isolated individual is
     * correctly excluded because they have no entry in the parent-of map (no
     * FAMC link).
     */
    #[Test]
    public function summaryMatchesAcceptanceFixture(): void
    {
        $tree   = $this->importFixtureTree('generation-depth.ged');
        $result = (new GenerationDepthRepository($tree, new ParentMapRepository($tree)))->summary();

        self::assertSame(2, $result->maxDepth);
        self::assertFalse($result->capped);
        self::assertSame(
            [0 => 1, 1 => 1, 2 => 1],
            $result->distribution,
        );
    }

    /**
     * The rendered chain must be resolved with a single bulk gedcom fetch, not
     * one `IndividualFactory::make()` round-trip per chain member (GH-154). On a
     * deep linear lineage the chain is `maxDepth + 1` individuals long, so a
     * per-id resolution loop issued one `SELECT i_gedcom` per member and grew
     * with the lineage depth; the bulk fetch issues a single chunked query
     * regardless.
     *
     * The discriminator is depth-INDEPENDENCE rather than an absolute bound: the
     * structural scans cost the same number of queries for a three-deep and an
     * eight-deep lineage, so summary()'s query count must not grow between them.
     * The old per-id loop made the eight-deep chain cost five extra queries (one
     * per additional member); the bulk fetch keeps the two equal. This is robust
     * to an incidental change in the structural query count (a core bump) that a
     * hard-coded bound would mask.
     */
    #[Test]
    public function summaryResolvesChainWithoutPerMemberQueries(): void
    {
        $shallow = $this->importFixtureTree('generation-depth.ged');
        $deep    = $this->importFixtureTree('generation-depth-deep-chain.ged');

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        (new GenerationDepthRepository($shallow, new ParentMapRepository($shallow)))->summary();
        $shallowQueries = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        DB::connection()->flushQueryLog();
        DB::connection()->enableQueryLog();
        $deepResult  = (new GenerationDepthRepository($deep, new ParentMapRepository($deep)))->summary();
        $deepQueries = count(DB::connection()->getQueryLog());
        DB::connection()->disableQueryLog();

        // The deep lineage is eight individuals deep, so the rendered chain holds
        // all eight — proving the chain was actually reconstructed and resolved,
        // so the query-count assertion cannot pass on a short-circuited chain.
        self::assertSame(7, $deepResult->maxDepth);
        self::assertCount(1, $deepResult->chains);
        self::assertCount(8, $deepResult->chains[0]);
        self::assertStringContainsString('Ancestor1', $deepResult->chains[0][0]->fullName());
        self::assertStringContainsString('Leaf8', $deepResult->chains[0][7]->fullName());

        // The eight-deep chain must not cost more queries than the three-deep
        // one — chain resolution does not scale one query per chain member.
        self::assertLessThanOrEqual(
            $shallowQueries,
            $deepQueries,
            'Chain resolution must not scale one query per chain member',
        );
    }

    /**
     * Top-N ancestors podium: the grandparent (I2) carries two transitive
     * descendants (the parent I1 plus the child I3), the parent itself one. The
     * child I3 sits at zero descendants and the isolated individual I4 has no
     * parentage links at all — both are excluded because zero-descendant
     * entries do not belong on a "structural roots" podium. So the ranking is
     * exactly two rows: Grandparent (I2) first with 2, Parent (I1) second with
     * 1. The grandparent deliberately carries the HIGHER xref, so a regression
     * that dropped the descendant-count sort and fell back to xref order would
     * surface the parent first and fail this test.
     */
    #[Test]
    public function topAncestorsByDescendantCountRanksGrandparentFirst(): void
    {
        $tree   = $this->importFixtureTree('generation-depth.ged');
        $result = (new GenerationDepthRepository($tree, new ParentMapRepository($tree)))
            ->topAncestorsByDescendantCount(10);

        self::assertCount(2, $result, 'Only the two individuals with descendants land on the podium');

        // Result is an ordered list of RankingEntry, most descendants first —
        // count order (I2, I1) is the reverse of xref order (I1, I2).
        self::assertSame('I2', $result[0]->xref);
        self::assertSame(2, $result[0]->value, 'Grandparent leads with two descendants (parent + grandchild)');
        self::assertStringContainsString('Grandparent', $result[0]->label);

        self::assertSame('I1', $result[1]->xref);
        self::assertSame(1, $result[1]->value, 'Parent follows with one descendant (the child)');
        self::assertStringContainsString('Parent', $result[1]->label);
    }

    /**
     * Edge: when the requested top-N exceeds the number of individuals with at
     * least one descendant, the result simply stops at the available rows — no
     * zero-padding, no overflow. Locks the implicit "no leaves on the podium"
     * contract.
     */
    #[Test]
    public function topAncestorsLimitClampsToAvailableRanks(): void
    {
        $tree   = $this->importFixtureTree('generation-depth.ged');
        $result = (new GenerationDepthRepository($tree, new ParentMapRepository($tree)))
            ->topAncestorsByDescendantCount(50);

        self::assertCount(2, $result, 'Asking for 50 rows on a 2-row tree still yields 2 rows');
    }

    /**
     * Regression for #71: a tree whose XREFs are digit-only (e.g. "1", "54")
     * must not crash the summary. PHP coerces numeric string array keys to
     * integers, so the parent-of map ends up keyed by int; the depth walk and
     * the chain reconstruction must tolerate that and still produce the same
     * result as the alphabetic-XREF fixture. Same topology as {@see
     * summaryMatchesAcceptanceFixture}, only the XREFs differ.
     */
    #[Test]
    public function summaryHandlesNumericOnlyXrefs(): void
    {
        $tree   = $this->importFixtureTree('generation-depth-numeric-xrefs.ged');
        $result = (new GenerationDepthRepository($tree, new ParentMapRepository($tree)))->summary();

        self::assertSame(2, $result->maxDepth);
        self::assertFalse($result->capped);
        self::assertSame(
            [0 => 1, 1 => 1, 2 => 1],
            $result->distribution,
        );

        // The reconstructed chain must resolve to real Individuals,
        // eldest-first. Asserting the resolved name (not just the
        // count) proves the digit-only XREFs reached the string-typed
        // Registry::make() intact rather than as coerced integers that
        // would have failed to resolve.
        self::assertCount(1, $result->chains);
        self::assertCount(3, $result->chains[0]);
        self::assertStringContainsString('Grandparent', $result->chains[0][0]->fullName());
        self::assertStringContainsString('Child', $result->chains[0][2]->fullName());
    }

    /**
     * Regression for #71: the top-ancestors podium must survive digit-only
     * XREFs too. The descendant-count map is keyed by the coerced integer XREF
     * and then resolved via Registry::make(), which is string-typed. Same
     * expected podium as {@see
     * topAncestorsByDescendantCountRanksGrandparentFirst}.
     */
    #[Test]
    public function topAncestorsHandlesNumericOnlyXrefs(): void
    {
        $tree   = $this->importFixtureTree('generation-depth-numeric-xrefs.ged');
        $result = (new GenerationDepthRepository($tree, new ParentMapRepository($tree)))
            ->topAncestorsByDescendantCount(10);

        self::assertCount(2, $result, 'Only the two individuals with descendants land on the podium');

        // Digit-only XREFs round-trip into the entry as strings; the grandparent
        // ("2") carries the higher xref, so count order ("2", "1") reverses xref
        // order — a dropped sort would surface "1" first.
        self::assertSame('2', $result[0]->xref);
        self::assertSame(2, $result[0]->value, 'Grandparent leads with two descendants (parent + grandchild)');
        self::assertStringContainsString('Grandparent', $result[0]->label);

        self::assertSame('1', $result[1]->xref);
        self::assertSame(1, $result[1]->value, 'Parent follows with one descendant (the child)');
        self::assertStringContainsString('Parent', $result[1]->label);
    }

    /**
     * Two distinct ancestors that share a display name ("Hans Müller", I1 with
     * 3 descendants and I2 with 1) must stay separate podium rows. The previous
     * name-keyed map collapsed them: iterating in descending-count order, I2's
     * count overwrote I1's under the same label, so the row showed the LOWER
     * value sitting above higher-valued rows — the "ordering looks wrong"
     * symptom. Keying each row by XREF keeps both, with the correct values and
     * order.
     */
    #[Test]
    public function topAncestorsByDescendantCountKeepsSameNamedAncestorsDistinct(): void
    {
        $tree   = $this->importFixtureTree('top-ancestors-duplicate-names.ged');
        $result = (new GenerationDepthRepository($tree, new ParentMapRepository($tree)))
            ->topAncestorsByDescendantCount(10);

        // I1, I3, I4, I2 all have at least one descendant.
        self::assertCount(4, $result);

        // Highest-count ancestor first, carrying its own XREF + value.
        self::assertSame('I1', $result[0]->xref);
        self::assertSame(3, $result[0]->value);

        // Both same-named ancestors survive as distinct rows — the old
        // name-keyed map would have produced a single "Hans Müller".
        $byXref = [];

        foreach ($result as $entry) {
            $byXref[$entry->xref] = $entry;
        }

        self::assertArrayHasKey('I1', $byXref);
        self::assertArrayHasKey('I2', $byXref);
        self::assertStringContainsString('Müller', $byXref['I1']->label);
        self::assertStringContainsString('Müller', $byXref['I2']->label);
        self::assertSame(3, $byXref['I1']->value);
        self::assertSame(1, $byXref['I2']->value);

        // The higher-count namesake ranks above the lower-count one.
        $xrefOrder = array_map(static fn (RankingEntry $entry): string => $entry->xref, $result);
        self::assertLessThan(
            array_search('I2', $xrefOrder, true),
            array_search('I1', $xrefOrder, true),
        );
    }
}
