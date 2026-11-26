<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support\Calc;

use MagicSunday\Webtrees\Statistic\Support\Calc\CenturySpan;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The century axis of a timeline runs without holes from the first to the last
 * century that has enough data.
 */
final class CenturySpanTest extends TestCase
{
    /**
     * @return iterable<string, array{list<int>, list<int>}>
     */
    public static function spans(): iterable
    {
        yield 'no qualifying century gives no axis' => [[], []];
        yield 'a lone century stays alone' => [[19], [19]];
        yield 'holes between the ends are filled' => [[17, 20], [17, 18, 19, 20]];
        yield 'unordered input is spanned from the lowest to the highest' => [[20, 18], [18, 19, 20]];
        yield 'the era change has no century zero' => [[-2, 2], [-2, -1, 1, 2]];
    }

    /**
     * @param list<int> $qualifying
     * @param list<int> $expected
     */
    #[Test]
    #[DataProvider('spans')]
    public function coversTheRangeBetweenTheQualifyingCenturies(array $qualifying, array $expected): void
    {
        self::assertSame($expected, CenturySpan::covering($qualifying));
    }
}
