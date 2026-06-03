<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use MagicSunday\Webtrees\Statistic\Model\Metric\IslandSummary;
use MagicSunday\Webtrees\Statistic\Repository\IslandRepository;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Integration tests for {@see IslandRepository} against `islands.ged`, which
 * carries five disconnected components of known size and surname:
 *
 *   Island A — 6 members (surname IslandA) across TWO families joined by a
 *              marrying child (FA1: A1+A2+A3+A4, FA2: A3+A5+A6) → exercises the
 *              union of separate families into one island.
 *   Island B — 3 members (surname IslandB, one nuclear family).
 *   Island C — 2 members (surname IslandC, a childless couple).
 *   Island D — 1 member (surname IslandD, an individual in no family).
 *   Island E — 1 member (surname IslandE, likewise).
 *
 * Expected ranking [6, 3, 2, 1, 1], 5 islands, largest 6, 13 individuals; the
 * two size-1 islands tie-break by XREF (D before E).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(IslandRepository::class)]
#[UsesClass(IslandSummary::class)]
#[UsesClass(TreeScope::class)]
#[UsesClass(RowCast::class)]
final class IslandRepositoryIntegrationTest extends IntegrationTestCase
{
    /**
     * The components are unioned from family co-members (spouses and
     * parent-child), singletons count as size-1 islands, and each top island
     * carries its dominant surname as a label. Island A's two families merge
     * through the marrying child A3, so a dropped union would split it into
     * 4 + 3 instead of one island of 6.
     */
    #[Test]
    public function ranksConnectedComponentsBySizeWithDominantSurnameLabels(): void
    {
        $tree   = $this->importFixtureTree('islands.ged');
        $result = (new IslandRepository($tree))->getConnectedComponents(10);

        self::assertSame(5, $result->totalIslands);
        self::assertSame(13, $result->totalPersons);
        self::assertSame(6, $result->largestSize);
        self::assertSame(0, $result->restMembers, 'all five islands fit within the top 10');
        // 6 of 13 individuals ≈ 46.15 %, rounded to the whole percent the card shows.
        self::assertSame(46, $result->largestPercent());

        self::assertSame(
            [
                ['rank' => 1, 'members' => 6, 'label' => 'IslandA'],
                ['rank' => 2, 'members' => 3, 'label' => 'IslandB'],
                ['rank' => 3, 'members' => 2, 'label' => 'IslandC'],
                ['rank' => 4, 'members' => 1, 'label' => 'IslandD'],
                ['rank' => 5, 'members' => 1, 'label' => 'IslandE'],
            ],
            $result->top,
        );

        // The serialised shape is the wire contract the treemap/diagnosis cards consume.
        self::assertSame(
            [
                'totalIslands' => 5,
                'totalPersons' => 13,
                'largestPct'   => 46,
                'top'          => $result->top,
                'restMembers'  => 0,
            ],
            $result->jsonSerialize(),
        );
    }

    /**
     * The limit caps the individually-listed islands; everything beyond folds
     * into `restMembers`, while the headline counts still reflect the whole
     * tree. Capping at two keeps islands A (6) and B (3); the remaining 2 + 1 + 1
     * members aggregate into the rest tile.
     */
    #[Test]
    public function limitCapsTopButFoldsTheRestAndKeepsHeadlineCounts(): void
    {
        $tree   = $this->importFixtureTree('islands.ged');
        $result = (new IslandRepository($tree))->getConnectedComponents(2);

        self::assertSame(
            [
                ['rank' => 1, 'members' => 6, 'label' => 'IslandA'],
                ['rank' => 2, 'members' => 3, 'label' => 'IslandB'],
            ],
            $result->top,
        );
        self::assertSame(4, $result->restMembers, '2 + 1 + 1 members beyond the top two');
        self::assertSame(5, $result->totalIslands);
        self::assertSame(6, $result->largestSize);
        self::assertSame(13, $result->totalPersons);
    }

    /**
     * The island label is the dominant surname; ties resolve alphabetically and
     * an island whose members carry no recorded surname gets an empty label.
     * `island-labels.ged` has island Q (4 members, surnames Alpha ×2 / Beta ×2 →
     * tie → "Alpha") and island P (2 members, given-name-only NAMEs → no surname
     * → "").
     */
    #[Test]
    public function labelsUseDominantSurnameWithAlphabeticalTieBreakAndEmptyWhenAbsent(): void
    {
        $tree   = $this->importFixtureTree('island-labels.ged');
        $result = (new IslandRepository($tree))->getConnectedComponents(10);

        self::assertSame(
            [
                ['rank' => 1, 'members' => 4, 'label' => 'Alpha'],
                ['rank' => 2, 'members' => 2, 'label' => ''],
            ],
            $result->top,
        );
    }

    /**
     * An empty tree yields no islands and a zero largest-share, not a division
     * error.
     */
    #[Test]
    public function emptyTreeYieldsNoIslands(): void
    {
        $tree   = $this->importFixtureTree('empty-tree.ged');
        $result = (new IslandRepository($tree))->getConnectedComponents(10);

        self::assertSame([], $result->top);
        self::assertSame(0, $result->totalIslands);
        self::assertSame(0, $result->totalPersons);
        self::assertSame(0, $result->largestSize);
        self::assertSame(0, $result->restMembers);
        self::assertSame(0, $result->largestPercent());
    }
}
