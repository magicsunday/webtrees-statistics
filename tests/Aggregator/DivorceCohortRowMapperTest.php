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
use MagicSunday\Webtrees\Statistic\Aggregator\DivorceCohortRowMapper;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the fold from a divorce-rate-by-cohort map into the parallel lists of
 * the line chart widget: whole percentages, decade labels and tooltip texts.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class DivorceCohortRowMapperTest extends TestCase
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

    /**
     * Each rate becomes a whole percentage, the cohort key becomes a decade, and
     * the four lists stay parallel in input order.
     */
    #[Test]
    public function rateBecomesAWholePercentagePerDecade(): void
    {
        $series = DivorceCohortRowMapper::toLineSeries(['1880' => 0.478, 1900 => 0.0, 1920 => 0.995]);

        self::assertSame(
            [
                'categories'    => ['1880s', '1900s', '1920s'],
                'values'        => [48, 0, 100],
                'tooltips'      => ['48% divorced', '0% divorced', '100% divorced'],
                'tooltipLabels' => ['Period: 1880–1889', 'Period: 1900–1909', 'Period: 1920–1929'],
            ],
            $series
        );
    }

    /**
     * An empty map yields four empty lists.
     */
    #[Test]
    public function emptyMapYieldsEmptyLists(): void
    {
        self::assertSame(
            ['categories' => [], 'values' => [], 'tooltips' => [], 'tooltipLabels' => []],
            DivorceCohortRowMapper::toLineSeries([])
        );
    }
}
