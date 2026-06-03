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
}
