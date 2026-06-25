<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Narrowing;

use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeyLink;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeySample;
use PHPUnit\Framework\Assert;

use function sprintf;

/**
 * Shared element-presence helpers for the test suite. Every helper turns a
 * possibly-absent array offset into a hard {@see Assert::fail()} (which is
 * `@return never`) so PHPStan's `reportPossiblyNonexistentGeneralArrayOffset`
 * sees a concrete, present value at the call site. The asserted value of each
 * test is preserved exactly — the helpers add only the implicit "the offset
 * exists" precondition the assertion already relies on, and fail with a
 * descriptive message instead of a `TypeError` / silent `null` when the
 * production code ever returns a shorter list than the test expects.
 *
 * Every helper is TYPED end to end (no `mixed` escape hatch): the accessors
 * return the concrete element type so a chained `->property` / `[$offset]`
 * access stays statically sound, and {@see assertValueAt()} keeps the value at
 * the assertion boundary. Implemented as a static-only utility (not a trait —
 * the project's Symplify `ForbiddenNodeRule` bans traits) and routed through
 * `PHPUnit\Framework\Assert` statically, so it resolves cleanly whether the
 * consuming class is an `IntegrationTestCase` subclass or a stand-alone test.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class PayloadNarrowing
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Return the {@see LineChartSeries} at the given index of a payload's
     * `series` list or fail when it is absent.
     *
     * @param LineChartPayload $payload The payload whose series list is read
     * @param int              $index   Zero-based series index expected to exist
     */
    public static function seriesAt(LineChartPayload $payload, int $index): LineChartSeries
    {
        return $payload->series[$index] ?? Assert::fail(sprintf('Expected a chart series at index %d', $index));
    }

    /**
     * Return the first {@see LineChartSeries} of a payload or fail when the
     * series list is empty.
     *
     * @param LineChartPayload $payload The payload whose first series is read
     */
    public static function firstSeries(LineChartPayload $payload): LineChartSeries
    {
        return self::seriesAt($payload, 0);
    }

    /**
     * Return the {@see LineChartSeries} stored under the given key of a
     * `name => series` map or fail when it is absent.
     *
     * @param array<string, LineChartSeries> $seriesByName Map keyed by series name
     * @param string                         $name         Series name expected to exist
     */
    public static function seriesNamed(array $seriesByName, string $name): LineChartSeries
    {
        return $seriesByName[$name] ?? Assert::fail(sprintf('Expected a chart series named "%s"', $name));
    }

    /**
     * Return the {@see SankeyLink} at the given index of a link list or fail
     * when it is absent.
     *
     * @param list<SankeyLink> $links The sankey link list being read
     * @param int              $index Zero-based link index expected to exist
     */
    public static function sankeyLinkAt(array $links, int $index): SankeyLink
    {
        return $links[$index] ?? Assert::fail(sprintf('Expected a sankey link at index %d', $index));
    }

    /**
     * Return the {@see SankeySample} at the given index of a sample list or fail
     * when it is absent.
     *
     * @param list<SankeySample> $samples The sankey sample list being read
     * @param int                $index   Zero-based sample index expected to exist
     */
    public static function sankeySampleAt(array $samples, int $index): SankeySample
    {
        return $samples[$index] ?? Assert::fail(sprintf('Expected a sankey sample at index %d', $index));
    }

    /**
     * Assert that the value stored under the given offset of an array is
     * identical to the expected value, failing with a descriptive message when
     * the offset is absent rather than reading past the end of the array. The
     * helper terminates in {@see Assert::assertSame()} (it returns void and is
     * never chained), so a `mixed` value at the assertion boundary is sound —
     * feeding a typed list such as a series' `values` raises no argument-type
     * concern.
     *
     * @param mixed                   $expected The value the offset must hold
     * @param array<array-key, mixed> $values   The array being read
     * @param int|string              $offset   Offset expected to exist
     */
    public static function assertValueAt(mixed $expected, array $values, int|string $offset): void
    {
        Assert::assertSame(
            $expected,
            $values[$offset] ?? Assert::fail(sprintf('Expected a value at offset %s', (string) $offset)),
        );
    }
}
