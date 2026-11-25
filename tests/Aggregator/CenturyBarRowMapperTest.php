<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Aggregator;

use Closure;
use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\Webtrees;
use MagicSunday\Webtrees\Statistic\Aggregator\CenturyBarRowMapper;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Locks the fold from a century histogram into the rows of the bar chart widget:
 * the label pair comes from the century, the value is the count, and the tooltip
 * pluralises the noun of the event the histogram counts.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 *
 * @phpstan-type CenturyFold Closure(array<int, int>): list<array{label: string, value: int, tooltipLabel: string, tooltip: string}>
 */
final class CenturyBarRowMapperTest extends TestCase
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
     * Every histogram kind pluralises its own noun, in the singular for one event
     * and in the plural with the thousands separator otherwise.
     *
     * @return array<string, array{CenturyFold, string, string}>
     */
    public static function eventNounProvider(): array
    {
        return [
            'births'    => [CenturyBarRowMapper::births(...), '1 birth', '1,234 births'],
            'deaths'    => [CenturyBarRowMapper::deaths(...), '1 death', '1,234 deaths'],
            'marriages' => [CenturyBarRowMapper::marriages(...), '1 marriage', '1,234 marriages'],
            'divorces'  => [CenturyBarRowMapper::divorces(...), '1 divorce', '1,234 divorces'],
        ];
    }

    /**
     * A signed century map becomes one row per century in input order, with the
     * compact label, the count, the long label and the pluralised tooltip.
     *
     * @param CenturyFold $fold
     */
    #[Test]
    #[DataProvider('eventNounProvider')]
    public function eventHistogramBecomesOneRowPerCentury(Closure $fold, string $singular, string $plural): void
    {
        $rows = $fold([20 => 1, -2 => 1234]);

        self::assertSame(
            [
                [
                    'label'        => '20th cent.',
                    'value'        => 1,
                    'tooltipLabel' => '20th Century',
                    'tooltip'      => $singular,
                ],
                [
                    'label'        => '2nd cent. BCE',
                    'value'        => 1234,
                    'tooltipLabel' => '2nd Century BCE',
                    'tooltip'      => $plural,
                ],
            ],
            $rows
        );
    }

    /**
     * The fold functions of the mapper, one per case.
     *
     * @return array<string, array{CenturyFold}>
     */
    public static function methodProvider(): array
    {
        return [
            'births'    => [CenturyBarRowMapper::births(...)],
            'deaths'    => [CenturyBarRowMapper::deaths(...)],
            'marriages' => [CenturyBarRowMapper::marriages(...)],
            'divorces'  => [CenturyBarRowMapper::divorces(...)],
        ];
    }

    /**
     * An empty histogram yields no rows.
     *
     * @param CenturyFold $fold
     */
    #[Test]
    #[DataProvider('methodProvider')]
    public function emptyHistogramYieldsNoRows(Closure $fold): void
    {
        self::assertSame([], $fold([]));
    }

    /**
     * The coverage bar carries the percentage rounded to a whole number, and the
     * tooltip names the raw counts the percentage came from.
     */
    #[Test]
    public function sourceCoverageRoundsThePercentageAndNamesTheCounts(): void
    {
        $rows = CenturyBarRowMapper::sourceCoverage([
            ['century' => 19, 'total' => 1200, 'sourced' => 600, 'percentage' => 49.6],
            ['century' => 18, 'total' => 3, 'sourced' => 1, 'percentage' => 33.4],
        ]);

        self::assertSame(
            [
                [
                    'label'        => '19th cent.',
                    'value'        => 50,
                    'tooltipLabel' => '19th Century',
                    'tooltip'      => '50% — 600 of 1,200 individuals sourced',
                ],
                [
                    'label'        => '18th cent.',
                    'value'        => 33,
                    'tooltipLabel' => '18th Century',
                    'tooltip'      => '33% — 1 of 3 individuals sourced',
                ],
            ],
            $rows
        );
    }

    /**
     * An empty coverage list yields no rows.
     */
    #[Test]
    public function emptySourceCoverageYieldsNoRows(): void
    {
        self::assertSame([], CenturyBarRowMapper::sourceCoverage([]));
    }
}
