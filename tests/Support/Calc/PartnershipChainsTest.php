<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Calc;

use MagicSunday\Webtrees\Statistic\Support\Calc\PartnershipChains;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_reverse;
use function array_unique;
use function count;

/**
 * Unit coverage of the pure {@see PartnershipChains} graph helper — exercises the
 * connected-component split over hand-coded adjacency maps without a Tree: the
 * three-person threshold that drops a couple, the size-descending then
 * smallest-xref ordering, insertion-order independence, the internal
 * edge-count, and the empty / no-qualifying-component cases — plus the
 * longest-simple-path ("longest chain") diameter computation: hand-verifiable
 * diameters, the branch-hub case where a naive eccentricity-from-the-first-node
 * walk under-counts but the diameter is correct, the equal-length tie-break
 * pinned to an exact sequence, the three-person threshold, and order
 * independence.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(PartnershipChains::class)]
final class PartnershipChainsTest extends TestCase
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
        self::assertSame([], PartnershipChains::components([]));
        self::assertNull(PartnershipChains::largestGroup([]));
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

        self::assertSame([], PartnershipChains::components($adjacency));
        self::assertNull(PartnershipChains::largestGroup($adjacency));
    }

    /**
     * A symmetric three-person chain is the smallest qualifying component:
     * three members, two internal edges (sum of degrees 4, halved).
     */
    #[Test]
    public function threePersonChainIsTheSmallestQualifyingComponent(): void
    {
        $components = PartnershipChains::components($this->threePersonChain());

        self::assertCount(1, $components);
        self::assertSame(['A', 'B', 'C'], $components[0]);

        $largest = PartnershipChains::largestGroup($this->threePersonChain());

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

        $components = PartnershipChains::components($adjacency);

        self::assertSame(
            [4, 3],
            array_map(count(...), $components),
        );

        $largest = PartnershipChains::largestGroup($adjacency);

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

        $components = PartnershipChains::components($adjacency);

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

        $components = PartnershipChains::components($adjacency);

        self::assertCount(1, $components);
        // Strict equality pins both the byte-order sort and the string type:
        // a coerced [54, 7, 9] of ints would not match.
        self::assertSame(['54', '7', '9'], $components[0]);

        $largest = PartnershipChains::largestGroup($adjacency);

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
        $components = PartnershipChains::components($adjacency);

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

    /**
     * A graph with no component of {@see PartnershipChains::MIN_GROUP_SIZE} people
     * has no chain: an empty map, a lone couple, and an isolated single person
     * all return an empty path.
     *
     * @param array<array-key, list<string>> $adjacency A sub-threshold graph
     *
     * @return void
     */
    #[Test]
    #[DataProvider('subThresholdGraphProvider')]
    public function longestChainIsEmptyBelowThreshold(array $adjacency): void
    {
        self::assertSame([], PartnershipChains::longestChain($adjacency));
    }

    /**
     * Graphs whose every component holds fewer than three people.
     *
     * @return array<string, array{array<array-key, list<string>>}>
     */
    public static function subThresholdGraphProvider(): array
    {
        return [
            'empty map'   => [[]],
            'lone couple' => [[
                'A' => ['B'],
                'B' => ['A'],
            ]],
            'two lone couples' => [[
                'A' => ['B'],
                'B' => ['A'],
                'C' => ['D'],
                'D' => ['C'],
            ]],
            // A degree-0 person: a single individual with no partnership edge — its
            // own one-member component is far below the threshold.
            'isolated single person' => [[
                'A' => [],
            ]],
        ];
    }

    /**
     * The longest chain across hand-coded graphs whose diameter can be checked
     * by eye. Each row pins the EXACT returned sequence (not just its length),
     * so the byte-order tie-break — smallest-xref start node, smallest-xref
     * farthest pick, sorted neighbour visitation — is locked against any
     * regression that would still yield a same-length but differently-ordered
     * path.
     *
     * @param array<array-key, list<string>> $adjacency The partnership graph
     * @param list<string>                   $expected  The exact longest-path xref sequence
     *
     * @return void
     */
    #[Test]
    #[DataProvider('longestChainProvider')]
    public function longestChainReturnsTheExactDiameterPath(array $adjacency, array $expected): void
    {
        self::assertSame($expected, PartnershipChains::longestChain($adjacency));
    }

    /**
     * Hand-verifiable diameter graphs.
     *
     * @return array<string, array{array<array-key, list<string>>, list<string>}>
     */
    public static function longestChainProvider(): array
    {
        return [
            // A straight three-person chain A–B–C: the diameter is the whole
            // chain, reported smallest-endpoint first.
            'three-person line' => [
                [
                    'A' => ['B'],
                    'B' => ['A', 'C'],
                    'C' => ['B'],
                ],
                ['A', 'B', 'C'],
            ],

            // A five-person line A–B–C–D–E: the diameter spans all five.
            'five-person line' => [
                [
                    'A' => ['B'],
                    'B' => ['A', 'C'],
                    'C' => ['B', 'D'],
                    'D' => ['C', 'E'],
                    'E' => ['D'],
                ],
                ['A', 'B', 'C', 'D', 'E'],
            ],

            // Branch-hub case (THE discriminator for diameter vs eccentricity):
            // four leaves A/B/C/D on a single hub H. Every longest simple path
            // is leaf–H–leaf (three people); among those the lexicographically
            // smallest sequence is A–H–B, which the byte-order tie-break and the
            // path orientation pick deterministically.
            'star hub picks smallest leaf pair' => [
                [
                    'A' => ['H'],
                    'B' => ['H'],
                    'C' => ['H'],
                    'D' => ['H'],
                    'H' => ['A', 'B', 'C', 'D'],
                ],
                ['A', 'H', 'B'],
            ],

            // A "T" graph: a spine A–B–C–D with a branch B–E. The longest simple
            // path is the spine A–B–C–D (4); the branch E–B–C–D is also 4 but
            // starts at the larger endpoint "E", so the smaller-endpoint
            // A–B–C–D wins the tie-break. A naive eccentricity walk from the
            // first node "A" happens to find the spine here, but the branch-hub
            // row above is the real discriminator.
            'T-graph prefers smallest endpoint path' => [
                [
                    'A' => ['B'],
                    'B' => ['A', 'C', 'E'],
                    'C' => ['B', 'D'],
                    'D' => ['C'],
                    'E' => ['B'],
                ],
                ['A', 'B', 'C', 'D'],
            ],

            // Two equal-length components: chain A–B–C and chain P–Q–R. Both are
            // length 3; the lexicographically smallest path (A,B,C) wins.
            'equal-length components tie-break on smallest path' => [
                [
                    'P' => ['Q'],
                    'Q' => ['P', 'R'],
                    'R' => ['Q'],
                    'A' => ['B'],
                    'B' => ['A', 'C'],
                    'C' => ['B'],
                ],
                ['A', 'B', 'C'],
            ],

            // A longer component must win over a shorter qualifying one,
            // regardless of insertion order: a 3-chain written first, a 4-line
            // written second.
            'longer component wins over shorter' => [
                [
                    'A' => ['B'],
                    'B' => ['A', 'C'],
                    'C' => ['B'],
                    'M' => ['N'],
                    'N' => ['M', 'O'],
                    'O' => ['N', 'P'],
                    'P' => ['O'],
                ],
                ['M', 'N', 'O', 'P'],
            ],
        ];
    }

    /**
     * THE discriminator at the unit level: the graph diameter (longest simple
     * path, computed by double-BFS) joins the TWO longest arms through a branch
     * hub, which a path confined to a single arm cannot reach. Hub C carries
     * three arms: A–B–C (two edges), C–D–E (two edges) and C–F–G–X (three
     * edges). The longest simple path picks the two longest arms — A–B–C and
     * C–F–G–X — and joins them across C into A–B–C–F–G–X (six people, five
     * partnerships). A walk that measured only one arm, or only the eccentricity
     * from a fixed proband down a single line, would stop short (the C–D–E arm
     * is a five-person path A–B–C–D–E and would be the wrong, shorter answer).
     */
    #[Test]
    public function longestChainExceedsSingleArmEccentricityOnBranchHub(): void
    {
        $adjacency = [
            'A' => ['B'],
            'B' => ['A', 'C'],
            'C' => ['B', 'D', 'F'],
            'D' => ['C', 'E'],
            'E' => ['D'],
            'F' => ['C', 'G'],
            'G' => ['F', 'X'],
            'X' => ['G'],
        ];

        // Two longest arms through C: A–B–C and C–F–G–X join into
        // A–B–C–F–G–X = six people. The C–D–E arm is shorter, so the
        // A–B–C–D–E (five-person) path it would form loses the tie — a
        // single-arm walk would stop at five, the diameter reaches six.
        self::assertSame(['A', 'B', 'C', 'F', 'G', 'X'], PartnershipChains::longestChain($adjacency));
    }

    /**
     * The longest chain must not depend on the insertion order of the adjacency
     * map nor of any neighbour list. The BFS sorts every neighbour list before
     * use, so reversing neighbour lists alone would be inert; this test ALSO
     * reverses the top-level key order — the algorithm's most order-sensitive
     * input, since it seeds which node BFS starts from — so the assertion
     * exercises the start-node, farthest-pick and reconstruction tie-breaks, not
     * just the (normalised-away) neighbour order.
     */
    #[Test]
    public function longestChainIsInsertionOrderIndependent(): void
    {
        $adjacency = [
            'A' => ['B'],
            'B' => ['A', 'C'],
            'C' => ['B', 'D', 'F'],
            'D' => ['C', 'E'],
            'E' => ['D'],
            'F' => ['C', 'G'],
            'G' => ['F', 'X'],
            'X' => ['G'],
        ];

        $shuffled = array_map(array_reverse(...), array_reverse($adjacency, true));

        self::assertSame(
            PartnershipChains::longestChain($adjacency),
            PartnershipChains::longestChain($shuffled),
        );
    }

    /**
     * A component that contains a CYCLE: the longest simple path is NP-hard in
     * general, so double-BFS returns a deterministic lower bound rather than a
     * proven optimum. The contract this test locks is that the result stays
     * deterministic and is a real simple path — not its exact optimality. The
     * graph is a four-node square A–B–C–D–A with a tail D–E; the byte-order
     * double-BFS yields the same chain regardless of insertion order.
     */
    #[Test]
    public function longestChainStaysDeterministicOnACyclicComponent(): void
    {
        $adjacency = [
            'A' => ['B', 'D'],
            'B' => ['A', 'C'],
            'C' => ['B', 'D'],
            'D' => ['A', 'C', 'E'],
            'E' => ['D'],
        ];

        $shuffled = array_map(array_reverse(...), array_reverse($adjacency, true));

        $chain = PartnershipChains::longestChain($adjacency);

        // Deterministic across insertion order.
        self::assertSame($chain, PartnershipChains::longestChain($shuffled));

        // A real simple path: at least the three-person threshold, no repeated
        // person, and every consecutive pair is a genuine partnership edge.
        self::assertGreaterThanOrEqual(PartnershipChains::MIN_GROUP_SIZE, count($chain));
        self::assertCount(count($chain), array_unique($chain));

        $previous = null;

        foreach ($chain as $person) {
            if ($previous !== null) {
                self::assertContains($person, $adjacency[$previous], $previous . ' and ' . $person . ' must be married');
            }

            $previous = $person;
        }
    }

    /**
     * Digit-only XREFs must order in BYTE order throughout the chain — including
     * the neighbour visitation that routes the interior of the path — so the
     * result matches the `strcmp` tie-breaks used everywhere else in the class.
     * The interior node "9" is reachable through two equal-distance parents,
     * "54" and "7"; in byte order "54" precedes "7" (digit '5' < '7'), whereas a
     * numeric sort would route through "7" instead. Pinning the exact sequence
     * locks the neighbour sort to {@see SORT_STRING}: a regression to the default
     * numeric `sort()` would route the interior through "7" and fail here.
     */
    #[Test]
    public function longestChainOrdersDigitOnlyXrefsInByteOrder(): void
    {
        // A diamond 1–{54,7}–9 with a tail 3–2–1 on one side and 9–8 on the
        // other. The diameter runs 3–2–1–?–9–8 (six people); the "?" hub ties
        // between "54" and "7", and the byte-order tie-break picks "54".
        $adjacency = [
            '3'  => ['2'],
            '2'  => ['3', '1'],
            '1'  => ['2', '54', '7'],
            '54' => ['1', '9'],
            '7'  => ['1', '9'],
            '9'  => ['54', '7', '8'],
            '8'  => ['9'],
        ];

        self::assertSame(
            ['3', '2', '1', '54', '9', '8'],
            PartnershipChains::longestChain($adjacency),
        );

        $shuffled = array_map(array_reverse(...), array_reverse($adjacency, true));

        self::assertSame(
            PartnershipChains::longestChain($adjacency),
            PartnershipChains::longestChain($shuffled),
        );
    }

    /**
     * The median year over the supplied multiset: an odd count yields the middle
     * value, an even count the LOWER of the two middle values (deterministic, no
     * averaging — averaging could produce a non-integer year), an empty list
     * yields `null`. The selection sorts a local copy, so the result is
     * order-independent and the caller's array is never mutated.
     *
     * @param list<int> $years    The birth+death year multiset
     * @param int|null  $expected The expected median year, or `null` when empty
     *
     * @return void
     */
    #[Test]
    #[DataProvider('medianYearProvider')]
    public function medianYearReturnsLowerMedian(array $years, ?int $expected): void
    {
        self::assertSame($expected, PartnershipChains::medianYear($years));
    }

    /**
     * The caller's array is never mutated: the helper must sort a local copy.
     * The unsorted input would become `[1850, 1900, 1950]` if the helper sorted
     * in place, so asserting the original order is preserved proves the copy.
     *
     * @param list<int> $years An unsorted year multiset, passed via the data
     *                         provider so PHPStan cannot constant-fold it to a
     *                         literal and flag the assertion as always-true
     *
     * @return void
     */
    #[Test]
    #[DataProvider('unsortedYearsProvider')]
    public function medianYearDoesNotMutateTheCallerArray(array $years): void
    {
        $before = $years;

        PartnershipChains::medianYear($years);

        self::assertSame($before, $years);
    }

    /**
     * A single unsorted multiset whose in-place sort would reorder it, so a
     * preserved order proves the helper copied rather than mutated.
     *
     * @return array<string, array{list<int>}>
     */
    public static function unsortedYearsProvider(): array
    {
        return [
            'descending then unsorted tail' => [[1950, 1850, 1900]],
        ];
    }

    /**
     * Median-year cases: odd → middle, even → lower median, unsorted → same as
     * sorted, single → itself, empty → null, and a duplicate-heavy multiset.
     *
     * @return array<string, array{list<int>, int|null}>
     */
    public static function medianYearProvider(): array
    {
        return [
            'empty list'                    => [[], null],
            'single element'                => [[1875], 1875],
            'odd count picks the middle'    => [[1850, 1900, 1950], 1900],
            'even count picks lower median' => [[1900, 1910], 1900],
            'unsorted input equals sorted'  => [[1950, 1850, 1900], 1900],
            'even unsorted lower median'    => [[1920, 1880, 1910, 1890], 1890],
            'duplicate-heavy multiset'      => [[1900, 1900, 1900, 1950], 1900],
            'all identical years'           => [[1880, 1880, 1880], 1880],
        ];
    }
}
