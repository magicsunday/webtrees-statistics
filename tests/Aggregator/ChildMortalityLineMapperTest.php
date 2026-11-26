<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Aggregator;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\Webtrees\Statistic\Aggregator\ChildMortalityLineMapper;
use MagicSunday\Webtrees\Statistic\Test\Support\Narrowing\PayloadNarrowing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The child-mortality timeline keeps a century without enough data on its axis.
 */
final class ChildMortalityLineMapperTest extends TestCase
{
    /**
     * The labels and the tooltips are translated, so the translator needs a language.
     */
    protected function setUp(): void
    {
        parent::setUp();

        (new Webtrees())->bootstrap();
        I18N::init('en-US', true);
    }

    #[Test]
    public function aCenturyBetweenTwoEntriesIsAGapAndNotAZero(): void
    {
        $payload = ChildMortalityLineMapper::toLineChartPayload([
            ['century' => 17, 'total' => 6, 'died' => 2, 'rate' => 100 / 3],
            ['century' => 19, 'total' => 5, 'died' => 0, 'rate' => 0.0],
        ]);

        self::assertSame(['17th cent.', '18th cent.', '19th cent.'], $payload->categories);

        $series = PayloadNarrowing::firstSeries($payload);

        self::assertSame('Mortality rate', $series->name);
        self::assertSame([100 / 3, null, 0.0], $series->values);
        self::assertSame(
            ['33.3% — 2 of 6 children died before age 5', '', '0% — 0 of 5 children died before age 5'],
            $series->tooltips,
        );
        self::assertSame(['17th Century', '18th Century', '19th Century'], $series->tooltipLabels);
    }

    #[Test]
    public function noEntryGivesAnEmptyAxis(): void
    {
        self::assertSame([], ChildMortalityLineMapper::toLineChartPayload([])->categories);
    }
}
