<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Aggregator;

use MagicSunday\Webtrees\Statistic\Model\Pyramid\PopulationPyramidPayload;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\CoupleAgeGapRowMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks the fold from the two-sided couple-age-gap distribution into the
 * population-pyramid payload — bands become rows, husband counts feed the left
 * column, wife counts the right.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(CoupleAgeGapRowMapper::class)]
#[UsesClass(PopulationPyramidPayload::class)]
final class CoupleAgeGapRowMapperTest extends TestCase
{
    /**
     * Empty input short-circuits to an empty payload so the caller renders the
     * empty placeholder.
     */
    #[Test]
    public function emptyInputReturnsEmptyPayload(): void
    {
        $payload = CoupleAgeGapRowMapper::toModel([]);

        self::assertSame([], $payload->groups);
        self::assertSame([], $payload->bands);
        self::assertSame([], $payload->data);
    }

    /**
     * A distribution whose every band is zero carries no data and yields the
     * empty payload, not a chart of empty bars.
     */
    #[Test]
    public function allZeroCountsReturnEmptyPayload(): void
    {
        $payload = CoupleAgeGapRowMapper::toModel([
            '0–4' => ['left' => 0, 'right' => 0],
            '5–9' => ['left' => 0, 'right' => 0],
        ]);

        self::assertSame([], $payload->groups);
    }

    /**
     * The payload is a single group whose rows preserve the distribution's band
     * order and counts, husband on the left and wife on the right.
     */
    #[Test]
    public function foldsBandsIntoASingleGroupPayload(): void
    {
        $payload = CoupleAgeGapRowMapper::toModel([
            '0–4'   => ['left' => 7, 'right' => 12],
            '5–9'   => ['left' => 3, 'right' => 0],
            '10–14' => ['left' => 0, 'right' => 4],
        ]);

        self::assertSame([''], $payload->groups);
        self::assertSame(['0–4', '5–9', '10–14'], $payload->bands);
        self::assertSame(
            [
                [
                    ['left' => 7, 'right' => 12],
                    ['left' => 3, 'right' => 0],
                    ['left' => 0, 'right' => 4],
                ],
            ],
            $payload->data
        );
    }

    /**
     * A distribution carrying a count on only one side still renders that band,
     * with the empty side left at zero.
     */
    #[Test]
    public function oneSidedBandKeepsTheEmptySideAtZero(): void
    {
        $payload = CoupleAgeGapRowMapper::toModel([
            '30+' => ['left' => 5, 'right' => 0],
        ]);

        self::assertSame(['30+'], $payload->bands);
        self::assertSame(['left' => 5, 'right' => 0], $payload->data[0][0]);
    }
}
