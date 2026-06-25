<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Aggregator;

use Illuminate\Support\Collection;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\TopNAggregator;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function sprintf;
use function ucfirst;

/**
 * Unit tests for {@see TopNAggregator}: case-folded counting, the first-seen
 * display casing, and the deterministic ordering of equal-frequency entries.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(TopNAggregator::class)]
final class TopNAggregatorTest extends TestCase
{
    /**
     * Build a row collection whose single GEDCOM column carries the label to
     * count, so the extractor is a trivial pass-through.
     *
     * @param list<string> $labels
     *
     * @return Collection<int, object>
     */
    private function rows(array $labels): Collection
    {
        return new Collection(array_map(
            static fn (string $label): object => new class($label) {
                public function __construct(public string $gedcom)
                {
                }
            },
            $labels,
        ));
    }

    /**
     * Equal-frequency entries are ordered by their case-folded label as a
     * secondary key, so two labels sharing a count never depend on input order.
     * "Apple" and "Zebra" both occur twice; "Apple" must precede "Zebra"
     * regardless of the order the rows arrived in.
     */
    #[Test]
    public function ordersEqualFrequencyEntriesAlphabeticallyAsTieBreak(): void
    {
        $rows = $this->rows(['Zebra', 'Apple', 'Mango', 'Zebra', 'Apple', 'Mango', 'Mango']);

        $result = TopNAggregator::topN($rows, static fn (string $gedcom): array => [$gedcom], 0);

        self::assertSame(
            ['Mango' => 3, 'Apple' => 2, 'Zebra' => 2],
            $result,
        );
    }

    /**
     * The tie-break makes the limit cut deterministic: at the Top-2 boundary
     * "Apple" survives over the equally-frequent "Zebra" because it sorts first.
     */
    #[Test]
    public function tieBreakKeepsTheLimitCutDeterministic(): void
    {
        $rows = $this->rows(['Zebra', 'Apple', 'Mango', 'Zebra', 'Apple', 'Mango', 'Mango']);

        $result = TopNAggregator::topN($rows, static fn (string $gedcom): array => [$gedcom], 2);

        self::assertSame(
            ['Mango' => 3, 'Apple' => 2],
            $result,
        );
    }

    /**
     * Counting is case-folded so spelling-case variants merge, and the display
     * label keeps the first-seen casing.
     */
    #[Test]
    public function foldsCaseForCountingAndKeepsFirstSeenDisplayCasing(): void
    {
        $rows = $this->rows(['Catholic', 'catholic', 'CATHOLIC']);

        $result = TopNAggregator::topN($rows, static fn (string $gedcom): array => [$gedcom], 0);

        self::assertSame(['Catholic' => 3], $result);
    }

    /**
     * {@see TopNAggregator::rankKeys()} is the single source of truth for the
     * ordering shared across the three Top-N sites: a fold-key => count map is
     * ordered by descending count, then by the fold key ascending as the stable
     * secondary tie-break.
     */
    #[Test]
    public function rankKeysOrdersByCountDescendingThenFoldKeyAscending(): void
    {
        $keys = TopNAggregator::rankKeys(['zebra' => 2, 'mango' => 3, 'apple' => 2], 0);

        self::assertSame(['mango', 'apple', 'zebra'], $keys);
    }

    /**
     * The limit cut applies after the deterministic ordering: at the Top-2
     * boundary the equal-count "apple" survives over "zebra" because its fold
     * key sorts first.
     */
    #[Test]
    public function rankKeysHonoursTheLimitAtTheTieBoundary(): void
    {
        $keys = TopNAggregator::rankKeys(['zebra' => 2, 'mango' => 3, 'apple' => 2], 2);

        self::assertSame(['mango', 'apple'], $keys);
    }

    /**
     * A negative limit means "no cap" just like the zero limit, mirroring the
     * {@see TopNAggregator::topN()} limit contract: every key is returned in
     * ranked order, never an empty or reversed list.
     */
    #[Test]
    public function rankKeysTreatsANegativeLimitAsNoCap(): void
    {
        $keys = TopNAggregator::rankKeys(['zebra' => 2, 'mango' => 3, 'apple' => 2], -1);

        self::assertSame(['mango', 'apple', 'zebra'], $keys);
    }

    /**
     * An empty count map yields an empty result from both entry points,
     * whatever the limit — the ordering and the display-resolution loop both
     * degrade to a no-op rather than erroring.
     */
    #[Test]
    public function rankKeysAndRankReturnEmptyForAnEmptyCountMap(): void
    {
        self::assertSame([], TopNAggregator::rankKeys([], 0));
        self::assertSame([], TopNAggregator::rankKeys([], 5));
        self::assertSame([], TopNAggregator::rank([], static fn (string $key): string => $key, 3));
    }

    /**
     * {@see TopNAggregator::rank()} layers a display-resolution strategy over
     * {@see TopNAggregator::rankKeys()}: the ranked fold keys are mapped to their
     * display labels while the counts are preserved.
     */
    #[Test]
    public function rankResolvesDisplayLabelsViaTheStrategy(): void
    {
        $result = TopNAggregator::rank(
            ['zebra' => 2, 'mango' => 3, 'apple' => 2],
            static fn (string $key): string => ucfirst($key),
            0,
        );

        self::assertSame(['Mango' => 3, 'Apple' => 2, 'Zebra' => 2], $result);
    }

    /**
     * The tie-break is decided on the fold KEY, never on the resolved display
     * label. With two equal-count keys whose display labels sort in the opposite
     * order to the keys, the Top-1 survivor is the key that sorts first
     * ("a" → display "Z"), not the alphabetically-first display label ("A").
     * This pins the canonical fold-key tie-break that the three call sites now
     * share, distinguishing it from a display-label tie-break.
     */
    #[Test]
    public function rankBreaksTiesOnTheFoldKeyNotTheDisplayLabel(): void
    {
        $display = ['a' => 'Z', 'b' => 'A'];

        $result = TopNAggregator::rank(
            ['a' => 2, 'b' => 2],
            static fn (string $key): string => $display[$key] ?? self::fail(sprintf('Expected a display label for key "%s"', $key)),
            1,
        );

        self::assertSame(['Z' => 2], $result);
    }
}
