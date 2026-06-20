<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Calc;

use MagicSunday\Webtrees\Statistic\Support\Calc\MarriageChains;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;

/**
 * Unit coverage of the pure {@see MarriageChains} graph helper — exercises the
 * connected-component split over hand-coded adjacency maps without a Tree: the
 * three-person threshold that drops a couple, the size-descending then
 * smallest-xref ordering, insertion-order independence, the internal
 * edge-count, and the empty / no-qualifying-component cases.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(MarriageChains::class)]
final class MarriageChainsTest extends TestCase
{
    /**
     * A symmetric three-person chain A–B–C: one qualifying component with all
     * three members and two internal edges.
     *
     * @return array<array-key, list<string>>
     */
    private function threePersonChain(): array
    {
        return [
            'A' => ['B'],
            'B' => ['A', 'C'],
            'C' => ['B'],
        ];
    }

    /**
     * An empty adjacency map has no components and therefore no largest group.
     */
    #[Test]
    public function emptyMapHasNoComponentsAndNoLargestGroup(): void
    {
        self::assertSame([], MarriageChains::components([]));
        self::assertNull(MarriageChains::largestGroup([]));
    }

    /**
     * A lone couple (two mutually-connected people) sits below the
     * three-person threshold, so it is dropped from the component list and
     * yields no largest group.
     */
    #[Test]
    public function twoPersonComponentIsDroppedByTheThreshold(): void
    {
        $adjacency = [
            'A' => ['B'],
            'B' => ['A'],
        ];

        self::assertSame([], MarriageChains::components($adjacency));
        self::assertNull(MarriageChains::largestGroup($adjacency));
    }

    /**
     * A symmetric three-person chain is the smallest qualifying component:
     * three members, two internal edges (sum of degrees 4, halved).
     */
    #[Test]
    public function threePersonChainIsTheSmallestQualifyingComponent(): void
    {
        $components = MarriageChains::components($this->threePersonChain());

        self::assertCount(1, $components);
        self::assertSame(['A', 'B', 'C'], $components[0]);

        $largest = MarriageChains::largestGroup($this->threePersonChain());

        self::assertNotNull($largest);
        self::assertSame(['A', 'B', 'C'], $largest['members']);
        self::assertSame(2, $largest['edges']);
    }

    /**
     * Two qualifying components of different size sort by member count
     * descending: the four-person group comes before the three-person group,
     * and the largest group reports the four-person set.
     */
    #[Test]
    public function componentsSortBySizeDescending(): void
    {
        // First a 3-chain (D–E–F), then a 4-star (W centre, X/Y/Z leaves).
        $adjacency = [
            'D' => ['E'],
            'E' => ['D', 'F'],
            'F' => ['E'],
            'W' => ['X', 'Y', 'Z'],
            'X' => ['W'],
            'Y' => ['W'],
            'Z' => ['W'],
        ];

        $components = MarriageChains::components($adjacency);

        self::assertSame(
            [4, 3],
            array_map(count(...), $components),
        );

        $largest = MarriageChains::largestGroup($adjacency);

        self::assertNotNull($largest);
        self::assertSame(['W', 'X', 'Y', 'Z'], $largest['members']);
        // Star: centre degree 3 + three leaf degree-1 = 6, halved → 3 edges.
        self::assertSame(3, $largest['edges']);
    }

    /**
     * Equal-size components break the sort tie by the byte-order-smallest member
     * XREF: a component whose smallest member is "A" precedes one whose smallest
     * member is "P", regardless of the order they were discovered in.
     */
    #[Test]
    public function equalSizeComponentsTieBreakOnSmallestMemberXref(): void
    {
        // The "P" component is written first so a stable sort would keep it
        // first; the smallest-member tie-break must still float the "A"
        // component ahead of it.
        $adjacency = [
            'P' => ['Q'],
            'Q' => ['P', 'R'],
            'R' => ['Q'],
            'A' => ['B'],
            'B' => ['A', 'C'],
            'C' => ['B'],
        ];

        $components = MarriageChains::components($adjacency);

        self::assertSame(['A', 'B', 'C'], $components[0]);
        self::assertSame(['P', 'Q', 'R'], $components[1]);
    }

    /**
     * Digit-only XREFs ("54") are coerced to int the moment they index the
     * adjacency map, so `array_keys()` hands the walk integer keys. The members
     * must come back string-typed and byte-order sorted ("54" < "7" < "9"),
     * proving the `(string)` cast on the start node is not optional — without it
     * a coerced `int` member would fail the strict `assertSame` (regression for
     * the numeric-XREF coercion boundary the class documents).
     */
    #[Test]
    public function digitOnlyXrefsComeBackAsByteSortedStrings(): void
    {
        $adjacency = [
            '54' => ['7'],
            '7'  => ['54', '9'],
            '9'  => ['7'],
        ];

        $components = MarriageChains::components($adjacency);

        self::assertCount(1, $components);
        // Strict equality pins both the byte-order sort and the string type:
        // a coerced [54, 7, 9] of ints would not match.
        self::assertSame(['54', '7', '9'], $components[0]);

        $largest = MarriageChains::largestGroup($adjacency);

        self::assertNotNull($largest);
        self::assertSame(['54', '7', '9'], $largest['members']);
        self::assertSame(2, $largest['edges']);
    }

    /**
     * The result must not depend on the insertion order of the adjacency map:
     * a shuffled map describing the same graph produces an identical component
     * list (same members, same order, same internal member order).
     *
     * @param array<array-key, list<string>> $adjacency A permutation of the same A–B–C / W-star graph
     *
     * @return void
     */
    #[Test]
    #[DataProvider('shuffledAdjacencyProvider')]
    public function resultIsIndependentOfInsertionOrder(array $adjacency): void
    {
        $components = MarriageChains::components($adjacency);

        self::assertSame(
            [
                ['W', 'X', 'Y', 'Z'],
                ['A', 'B', 'C'],
            ],
            $components,
        );
    }

    /**
     * Three permutations of the same two-component graph (one 4-star, one
     * 3-chain), each with the keys and neighbour lists written in a different
     * order.
     *
     * @return array<string, array{array<array-key, list<string>>}>
     */
    public static function shuffledAdjacencyProvider(): array
    {
        return [
            'star first, sorted neighbours' => [[
                'W' => ['X', 'Y', 'Z'],
                'X' => ['W'],
                'Y' => ['W'],
                'Z' => ['W'],
                'A' => ['B'],
                'B' => ['A', 'C'],
                'C' => ['B'],
            ]],
            'chain first, reversed neighbours' => [[
                'C' => ['B'],
                'B' => ['C', 'A'],
                'A' => ['B'],
                'Z' => ['W'],
                'Y' => ['W'],
                'X' => ['W'],
                'W' => ['Z', 'Y', 'X'],
            ]],
            'interleaved keys' => [[
                'Z' => ['W'],
                'A' => ['B'],
                'W' => ['Y', 'X', 'Z'],
                'B' => ['A', 'C'],
                'X' => ['W'],
                'C' => ['B'],
                'Y' => ['W'],
            ]],
        ];
    }
}
