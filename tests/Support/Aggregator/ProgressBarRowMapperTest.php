<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Aggregator;

use MagicSunday\Webtrees\Statistic\Model\Ranking\RankingEntry;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\ProgressBarRowMapper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;
use PHPUnit\Framework\TestCase;

/**
 * Locks the row-mapping contract shared by the bar lists ({@see
 * ProgressBarRowMapper::toRows()}) and the entity podiums ({@see
 * ProgressBarRowMapper::fromRankingEntries()}). The podium path must keep two
 * entries that share a display label as separate rows — keying a podium by
 * label would collapse two distinct individuals or families onto one row and
 * drop the higher-ranked one, which is the bug the {@see RankingEntry} identity
 * fixes.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(ProgressBarRowMapper::class)]
#[UsesClass(RankingEntry::class)]
final class ProgressBarRowMapperTest extends TestCase
{
    /**
     * Empty input short-circuits so the card renders its empty placeholder
     * instead of an axis with no bars.
     */
    #[Test]
    public function emptyEntryListReturnsEmptyRows(): void
    {
        self::assertSame([], ProgressBarRowMapper::fromRankingEntries([]));
    }

    /**
     * Two ranking entries that carry the same display label still produce two
     * rows — the label-keyed map this replaced would have merged them into one,
     * losing the higher-value entry. Each row keeps its own value and the
     * rank/percentage are derived from the caller-supplied order and the
     * largest value.
     */
    #[Test]
    public function sameLabelEntriesStayDistinctRows(): void
    {
        $rows = ProgressBarRowMapper::fromRankingEntries([
            new RankingEntry('I1', 'Hans Müller', 5),
            new RankingEntry('I2', 'Hans Müller', 2),
        ]);

        self::assertCount(2, $rows);

        self::assertSame('01', $rows[0]['rank']);
        self::assertSame('Hans Müller', $rows[0]['label']);
        self::assertSame(5.0, $rows[0]['value']);
        self::assertSame(100.0, $rows[0]['percentage']);

        self::assertSame('02', $rows[1]['rank']);
        self::assertSame('Hans Müller', $rows[1]['label']);
        self::assertSame(2.0, $rows[1]['value']);
        self::assertSame(40.0, $rows[1]['percentage']);
    }

    /**
     * A list whose every value is non-positive yields no rows: the percentage
     * basis would be zero, so the card shows the empty placeholder rather than
     * zero-width bars. Covers both the zero and the negative branch of the
     * `$maxValue <= 0` guard.
     */
    #[Test]
    public function nonPositiveValuesReturnEmptyRows(): void
    {
        self::assertSame([], ProgressBarRowMapper::fromRankingEntries([
            new RankingEntry('I1', 'Nobody', 0),
        ]));

        self::assertSame([], ProgressBarRowMapper::fromRankingEntries([
            new RankingEntry('I2', 'Negative', -3),
        ]));
    }
}
