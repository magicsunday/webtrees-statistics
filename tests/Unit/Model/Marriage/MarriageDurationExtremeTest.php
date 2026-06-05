<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Unit\Model\Marriage;

use MagicSunday\Webtrees\Statistic\Enum\MarriageEndReason;
use MagicSunday\Webtrees\Statistic\Model\Marriage\MarriageDurationExtreme;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Branch coverage for the per-entry duration-unit logic: marriages below two
 * whole years read in days, two years and beyond read in whole years, with the
 * one-year/two-year boundary pinned on both sides.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(MarriageDurationExtreme::class)]
#[UsesClass(MarriageEndReason::class)]
final class MarriageDurationExtremeTest extends TestCase
{
    /**
     * @return array<string, array{durationDays: int, durationYears: int, expectedUnit: string, expectedValue: int}>
     */
    public static function durationProvider(): array
    {
        return [
            'a few weeks reads in days'          => ['durationDays' => 31, 'durationYears' => 0, 'expectedUnit' => 'days', 'expectedValue' => 31],
            'over a year still reads in days'    => ['durationDays' => 400, 'durationYears' => 1, 'expectedUnit' => 'days', 'expectedValue' => 400],
            'just under two years reads in days' => ['durationDays' => 730, 'durationYears' => 1, 'expectedUnit' => 'days', 'expectedValue' => 730],
            'two full years switches to years'   => ['durationDays' => 731, 'durationYears' => 2, 'expectedUnit' => 'years', 'expectedValue' => 2],
            'a long marriage reads in years'     => ['durationDays' => 21915, 'durationYears' => 60, 'expectedUnit' => 'years', 'expectedValue' => 60],
        ];
    }

    /**
     * The display unit and value are picked per entry by magnitude.
     */
    #[Test]
    #[DataProvider('durationProvider')]
    public function displayUnitAndValueScaleWithDuration(
        int $durationDays,
        int $durationYears,
        string $expectedUnit,
        int $expectedValue,
    ): void {
        $extreme = new MarriageDurationExtreme(
            familyXref: 'F1',
            label: 'Anton & Berta',
            durationDays: $durationDays,
            durationYears: $durationYears,
            endReason: MarriageEndReason::Death,
        );

        self::assertSame($expectedUnit, $extreme->displayUnit());
        self::assertSame($expectedValue, $extreme->displayValue());
    }

    /**
     * The end-cause is null when suppressed because the record is not visible
     * to the current user. The duration — the ranked metric — is unaffected.
     */
    #[Test]
    public function endReasonIsNullWhenSuppressedForAHiddenRecord(): void
    {
        $extreme = new MarriageDurationExtreme(
            familyXref: 'F1',
            label: 'Private',
            durationDays: 15,
            durationYears: 0,
            endReason: null,
        );

        self::assertNull($extreme->endReason);
        self::assertSame('days', $extreme->displayUnit());
        self::assertSame(15, $extreme->displayValue());
    }
}
