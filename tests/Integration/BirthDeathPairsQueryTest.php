<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Integration;

use Fisharebest\Webtrees\DB;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Database\BirthDeathPairsQuery;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Integration test for {@see BirthDeathPairsQuery}, focused on the birth alias
 * row-coherence guarantee its cohort consumers (child-mortality, lifespan-by-
 * century) rely on. The pin to the lower-bound BIRT row keeps a consumer's
 * `MIN(birth.d_year)` / `MIN(birth.d_type)` drawn from ONE row even when an
 * individual carries BIRT facts in more than one calendar.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(BirthDeathPairsQuery::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(GregorianDate::class)]
#[UsesClass(RowCast::class)]
final class BirthDeathPairsQueryTest extends IntegrationTestCase
{
    /**
     * Two BIRT rows that describe the same physical day in different calendars —
     * a Gregorian `1 JAN 1800` and a Julian `21 DEC 1799` — share the exact
     * lower-bound julian day (2378497) but carry diverging Gregorian-scale native
     * years (1800 vs 1799). Independent column minima would draw `birth_type`
     * from the Gregorian row (`@#DGREGORIAN@` < `@#DJULIAN@`) but `birth_year`
     * from the Julian row (1799 < 1800), so the converted cohort year would land
     * one off — across the 1799/1800 century edge. The pin must surface ONE
     * coherent row: the Gregorian birth, year 1800.
     */
    #[Test]
    public function pinsBirthToOneCoherentRowOnAnExactCrossCalendarTie(): void
    {
        $tree = $this->importFixtureTree('birth-death-pairs-cross-calendar-tie.ged');

        // The fixture individual carries only a DEAT (1 JAN 1803). Inject the two
        // tied BIRT rows directly so their julian day is byte-identical — the
        // tie cannot be expressed reliably through GEDCOM, where the calendar
        // offset would have to be hand-computed.
        DB::table('dates')->insert([
            'd_gid'        => 'I1',
            'd_file'       => $tree->id(),
            'd_fact'       => 'BIRT',
            'd_type'       => '@#DGREGORIAN@',
            'd_day'        => 1,
            'd_mon'        => 1,
            'd_month'      => 'JAN',
            'd_year'       => 1800,
            'd_julianday1' => 2378497,
            'd_julianday2' => 2378497,
        ]);
        DB::table('dates')->insert([
            'd_gid'        => 'I1',
            'd_file'       => $tree->id(),
            'd_fact'       => 'BIRT',
            'd_type'       => '@#DJULIAN@',
            'd_day'        => 21,
            'd_mon'        => 12,
            'd_month'      => 'DEC',
            'd_year'       => 1799,
            'd_julianday1' => 2378497,
            'd_julianday2' => 2378497,
        ]);

        // Mirror the child-mortality consumer's per-individual collapse.
        $rows = BirthDeathPairsQuery::for($tree, true)
            ->groupBy('individuals.i_id')
            ->select([
                DateAggregate::min('birth', 'd_type', 'birth_type'),
                DateAggregate::min('birth', 'd_julianday1', 'birth_jd'),
                DateAggregate::min('birth', 'd_year', 'birth_year'),
            ])
            ->get();

        self::assertCount(1, $rows, 'The tied cross-calendar birth pairs the individual exactly once.');

        $row = $rows[0];

        self::assertSame(
            '@#DGREGORIAN@',
            RowCast::string($row, 'birth_type'),
            'The Gregorian calendar wins the deterministic tie-break (G < J).',
        );
        self::assertSame(
            1800,
            RowCast::int($row, 'birth_year'),
            'The birth year is read from the same row as the type — never the Julian row\'s 1799.',
        );
        self::assertSame(
            1800,
            GregorianDate::year(
                RowCast::string($row, 'birth_type'),
                RowCast::int($row, 'birth_year'),
                RowCast::int($row, 'birth_jd'),
            ),
            'The coherent birth row buckets the cohort into 1800, not the off-by-one 1799.',
        );
    }
}
