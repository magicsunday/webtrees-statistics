<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit;

use MagicSunday\Webtrees\Statistic\Support\ChildMortalityRate;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Unit-level coverage of {@see ChildMortalityRate::compute()} —
 * exercises every branch (empty input, mixed survival outcomes,
 * malformed pair with deathJd < birthJd, custom threshold) so
 * future callers can rely on the contract without re-reading the
 * implementation.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ChildMortalityRateTest extends TestCase
{
    /**
     * An empty input must return null so the caller renders an
     * empty-state placeholder rather than a misleading "0 %".
     */
    #[Test]
    public function emptyInputReturnsNull(): void
    {
        self::assertNull(ChildMortalityRate::compute([]));
    }

    /**
     * The contract from the issue acceptance fixture: 3 children
     * dead before 5, 2 survivors → 60.0 %. Anchors the formula
     * against the exact published scenario.
     */
    #[Test]
    public function reproducesIssueAcceptanceScenario(): void
    {
        // 3 children dead within 1 year (julian-day-365 apart), 2
        // survivors who reached well past 5 years.
        $pairs = [
            ['birthJd' => 2400000, 'deathJd' => 2400100],
            ['birthJd' => 2400000, 'deathJd' => 2400200],
            ['birthJd' => 2400000, 'deathJd' => 2400300],
            ['birthJd' => 2400000, 'deathJd' => 2440000],
            ['birthJd' => 2400000, 'deathJd' => 2450000],
        ];

        $result = ChildMortalityRate::compute($pairs);

        self::assertNotNull($result);
        self::assertSame(5, $result['total']);
        self::assertSame(3, $result['died']);
        self::assertSame(60.0, $result['rate']);
    }

    /**
     * A pair whose death julian-day precedes its birth julian-day
     * is a data-entry error and must be silently dropped — including
     * it would inflate the under-5 count by one without growing the
     * denominator in a meaningful way.
     */
    #[Test]
    public function reversedDatesAreDropped(): void
    {
        $pairs = [
            ['birthJd' => 2400000, 'deathJd' => 2350000],
            ['birthJd' => 2400000, 'deathJd' => 2400100],
            ['birthJd' => 2400000, 'deathJd' => 2450000],
        ];

        $result = ChildMortalityRate::compute($pairs);

        self::assertNotNull($result);
        self::assertSame(2, $result['total']);
        self::assertSame(1, $result['died']);
        self::assertSame(50.0, $result['rate']);
    }

    /**
     * The threshold is configurable so the same helper can drive
     * other "died before age N" widgets in the future (e.g. under-1
     * infant mortality). Verify the parameter actually changes the
     * cut-off.
     */
    #[Test]
    public function thresholdParameterShiftsTheCutoff(): void
    {
        $pairs = [
            ['birthJd' => 2400000, 'deathJd' => 2400200],
            ['birthJd' => 2400000, 'deathJd' => 2400500],
        ];

        $under1 = ChildMortalityRate::compute($pairs, 365);

        self::assertNotNull($under1);
        self::assertSame(2, $under1['total']);
        self::assertSame(1, $under1['died']);
        self::assertSame(50.0, $under1['rate']);

        $under6Months = ChildMortalityRate::compute($pairs, 180);

        self::assertNotNull($under6Months);
        self::assertSame(2, $under6Months['total']);
        self::assertSame(0, $under6Months['died']);
        self::assertSame(0.0, $under6Months['rate']);
    }
}
