<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support;

use MagicSunday\Webtrees\Statistic\Support\SeasonalityScore;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the season-density formula behind the winter-peak indicator.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class SeasonalityScoreTest extends TestCase
{
    /**
     * 120 deaths evenly distributed (10 per month) — winter takes
     * exactly 3 months × 10 = 30, total 120, score = 1.0.
     */
    #[Test]
    public function evenlyDistributedDeathsScoreOne(): void
    {
        $months = [
            'JAN' => 10, 'FEB' => 10, 'MAR' => 10, 'APR' => 10,
            'MAY' => 10, 'JUN' => 10, 'JUL' => 10, 'AUG' => 10,
            'SEP' => 10, 'OCT' => 10, 'NOV' => 10, 'DEC' => 10,
        ];

        $result = SeasonalityScore::score($months, SeasonalityScore::NORTHERN_WINTER);

        self::assertNotNull($result);
        self::assertSame(1.0, $result['score']);
        self::assertSame(30, $result['seasonCount']);
        self::assertSame(120, $result['total']);
    }

    /**
     * Winter doubles the baseline — 6 per winter month vs. 2 per
     * other month — total 36, baseline 3, winter avg 6,
     * score = 6 / 3 = 2.0.
     */
    #[Test]
    public function winterPeakDoublesTheScore(): void
    {
        $months = [
            'JAN' => 6, 'FEB' => 6, 'MAR' => 2, 'APR' => 2,
            'MAY' => 2, 'JUN' => 2, 'JUL' => 2, 'AUG' => 2,
            'SEP' => 2, 'OCT' => 2, 'NOV' => 2, 'DEC' => 6,
        ];

        $result = SeasonalityScore::score($months, SeasonalityScore::NORTHERN_WINTER);

        self::assertNotNull($result);
        self::assertSame(2.0, $result['score']);
        self::assertSame(18, $result['seasonCount']);
        self::assertSame(36, $result['total']);
    }

    /**
     * Winter halved compared to baseline — score < 1.0.
     */
    #[Test]
    public function winterTroughScoresBelowOne(): void
    {
        $months = [
            'JAN' => 5, 'FEB' => 5, 'MAR' => 10, 'APR' => 10,
            'MAY' => 10, 'JUN' => 10, 'JUL' => 10, 'AUG' => 10,
            'SEP' => 10, 'OCT' => 10, 'NOV' => 10, 'DEC' => 5,
        ];

        $result = SeasonalityScore::score($months, SeasonalityScore::NORTHERN_WINTER);

        self::assertNotNull($result);
        self::assertLessThan(1.0, $result['score']);
        self::assertSame(15, $result['seasonCount']);
        self::assertSame(105, $result['total']);
    }

    /**
     * Lower-case month keys still match the season list via
     * case-insensitive lookup. Same fixture as the doubling
     * test — only difference is the key casing.
     */
    #[Test]
    public function lowerCaseMonthKeysStillMatch(): void
    {
        $months = [
            'jan' => 6, 'feb' => 6, 'mar' => 2, 'apr' => 2,
            'may' => 2, 'jun' => 2, 'jul' => 2, 'aug' => 2,
            'sep' => 2, 'oct' => 2, 'nov' => 2, 'dec' => 6,
        ];

        $result = SeasonalityScore::score($months, SeasonalityScore::NORTHERN_WINTER);

        self::assertNotNull($result);
        self::assertSame(2.0, $result['score']);
    }

    /**
     * Sub-threshold sample (< 12 events) returns null — the
     * widget consumer surfaces a "not enough data" placeholder
     * rather than a misleading float.
     */
    #[Test]
    public function subThresholdSampleReturnsNull(): void
    {
        $months = [
            'JAN' => 1, 'FEB' => 0, 'DEC' => 2,
        ];

        self::assertNull(
            SeasonalityScore::score($months, SeasonalityScore::NORTHERN_WINTER, 12),
        );
    }

    /**
     * Empty input returns null (covers the all-zero-and-no-keys
     * edge case).
     */
    #[Test]
    public function emptyInputReturnsNull(): void
    {
        self::assertNull(
            SeasonalityScore::score([], SeasonalityScore::NORTHERN_WINTER),
        );
    }

    /**
     * Empty season list returns null — defensive against a
     * misconfigured caller passing no winter months.
     */
    #[Test]
    public function emptySeasonReturnsNull(): void
    {
        $months = [
            'JAN' => 10, 'FEB' => 10, 'MAR' => 10, 'APR' => 10,
            'MAY' => 10, 'JUN' => 10, 'JUL' => 10, 'AUG' => 10,
            'SEP' => 10, 'OCT' => 10, 'NOV' => 10, 'DEC' => 10,
        ];

        self::assertNull(SeasonalityScore::score($months, []));
    }
}
