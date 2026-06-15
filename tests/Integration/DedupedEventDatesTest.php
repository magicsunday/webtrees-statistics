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
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\UsesClass;

/**
 * Integration test for {@see DedupedEventDates}. Webtrees writes TWO `dates`
 * rows for every range date (`BET..AND`, `FROM..TO`) — a lower-bound and an
 * upper-bound row — so any raw `dates` aggregation double-counts the individual
 * and, when the bounds straddle a bucket edge, splits one record across two
 * buckets. The helper collapses every individual to its single lower-bound
 * (representative) row.
 *
 * Fixture (`deduped-event-dates.ged`), BIRT events:
 *  - I1 `1 JAN 1900`               → precise → ONE row (year 1900, month 1, day 1)
 *  - I2 `BET 1850 AND 1855`        → two rows (1850 / 1855), same century, no month
 *  - I3 `1870`                     → year-only → ONE row (year 1870, no month);
 *                                    min == max date, so `array_unique` collapses it
 *  - I4 `BET DEC 1880 AND JAN 1881`→ two rows (Dec 1880 / Jan 1881)
 *  - I5 `MAR 1900`                 → month-only → ONE row (year 1900, month 3)
 *  - I6 `1 JAN 1910` BIRT + `1 JAN 1980` DEAT → exercises fact scoping
 *  - I7 `@#DHEBREW@ 1 TSH 5661`    → non-Gregorian → KEPT (native year 5661,
 *                                    julian day 2415287, converts to 1900)
 *  - I8 `(uncertain)`              → no parseable year → excluded (d_year<>0 filter)
 *
 * So `query(tree, 'BIRT')` yields seven individuals (I1–I7); only the year-less
 * I8 drops out. The Hebrew I7 is no longer filtered by calendar — consumers
 * convert its lower-bound julian day to a Gregorian period via {@see GregorianDate}.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(DedupedEventDates::class)]
#[UsesClass(DateAggregate::class)]
#[UsesClass(GregorianDate::class)]
#[UsesClass(RowCast::class)]
final class DedupedEventDatesTest extends IntegrationTestCase
{
    /**
     * The raw table carries the doubled rows (guards the premise); the helper
     * collapses them to one row per individual, keeps every calendar (the Hebrew
     * birth is no longer dropped — consumers convert it), and still excludes the
     * year-less row that has no parseable period at all.
     */
    #[Test]
    public function collapsesDoubledRowsKeepsEveryCalendarAndExcludesYearlessRows(): void
    {
        $tree = $this->importFixtureTree('deduped-event-dates.ged');

        $rawRanged = DB::table('dates')
            ->where('d_file', '=', $tree->id())
            ->where('d_fact', '=', 'BIRT')
            ->whereIn('d_gid', ['I2', 'I4'])
            ->count();

        self::assertSame(4, $rawRanged, 'Premise: each BET..AND date writes two rows.');

        $gids = $this->birthGids($tree);

        self::assertSame(['I1', 'I2', 'I3', 'I4', 'I5', 'I6', 'I7'], $gids);
        self::assertContains('I7', $gids, 'Hebrew (non-Gregorian) birth is kept, not excluded.');
        self::assertNotContains('I8', $gids, 'Year-less birth is excluded.');
    }

    /**
     * A non-Gregorian representative row exposes the inputs a period-bucketing
     * consumer needs to convert it: its native `d_year` plus the calendar
     * `d_type` and lower-bound `d_julianday1`. The Hebrew birth `1 TSH 5661`
     * keeps native year 5661 here, and {@see GregorianDate} converts it to the
     * Gregorian year 1900.
     */
    #[Test]
    public function nonGregorianRowExposesConversionInputs(): void
    {
        $tree = $this->importFixtureTree('deduped-event-dates.ged');

        $row = null;

        foreach (DedupedEventDates::query($tree, 'BIRT')->get() as $candidate) {
            if (RowCast::string($candidate, 'd_gid') === 'I7') {
                $row = $candidate;

                break;
            }
        }

        self::assertNotNull($row, 'The Hebrew birth surfaces as a representative row.');
        self::assertSame('@#DHEBREW@', RowCast::string($row, 'd_type'));
        self::assertSame(5661, RowCast::int($row, 'd_year'), 'Native Hebrew year is exposed unconverted.');
        self::assertSame(2415287, RowCast::int($row, 'd_julianday1'), 'Lower-bound julian day for the conversion.');
        self::assertSame(
            1900,
            GregorianDate::year(
                RowCast::string($row, 'd_type'),
                RowCast::int($row, 'd_year'),
                RowCast::int($row, 'd_julianday1'),
            ),
            'Hebrew 5661 converts to Gregorian 1900.',
        );
    }

    /**
     * Each individual's representative row is the lower-bound row — the one with
     * the minimum `d_julianday1` — so its year and month are the lower-bound
     * values, never the upper bound and never a mix.
     */
    #[Test]
    public function representativeRowCarriesLowerBoundYearAndMonth(): void
    {
        $tree = $this->importFixtureTree('deduped-event-dates.ged');

        $yearByGid = [];
        $monByGid  = [];
        $dayByGid  = [];

        foreach (DedupedEventDates::query($tree, 'BIRT')->get() as $row) {
            $gid             = RowCast::string($row, 'd_gid');
            $yearByGid[$gid] = RowCast::int($row, 'd_year');
            $monByGid[$gid]  = RowCast::int($row, 'd_mon');
            $dayByGid[$gid]  = RowCast::int($row, 'd_day');
        }

        self::assertSame(1900, $yearByGid['I1']);
        self::assertSame(1, $monByGid['I1']);
        self::assertSame(1, $dayByGid['I1'], 'Precise date keeps its day.');

        self::assertSame(1850, $yearByGid['I2'], 'Lower bound of BET 1850 AND 1855.');

        // Year-only 1870 stays a single row with no month (d_mon = 0).
        self::assertSame(1870, $yearByGid['I3']);
        self::assertSame(0, $monByGid['I3'], 'Year-only date carries no month.');

        // BET DEC 1880 AND JAN 1881: the lower bound is December 1880, so the
        // representative month must be 12 and the year 1880 — independent
        // column MINs would wrongly pick month 1 from the January row.
        self::assertSame(1880, $yearByGid['I4']);
        self::assertSame(12, $monByGid['I4'], 'Lower bound month spans backward across the year edge.');
        self::assertSame(0, $dayByGid['I4'], 'A year/month range carries no day.');

        // Month-only MAR 1900 keeps its month.
        self::assertSame(1900, $yearByGid['I5']);
        self::assertSame(3, $monByGid['I5']);
    }

    /**
     * The query is scoped to the requested fact — I6 carries both a BIRT and a
     * DEAT, and each fact surfaces only its own row.
     */
    #[Test]
    public function scopesToTheRequestedFact(): void
    {
        $tree = $this->importFixtureTree('deduped-event-dates.ged');

        $births = [];

        foreach (DedupedEventDates::query($tree, 'BIRT')->get() as $row) {
            $births[RowCast::string($row, 'd_gid')] = RowCast::int($row, 'd_year');
        }

        $deaths = [];

        foreach (DedupedEventDates::query($tree, 'DEAT')->get() as $row) {
            $deaths[RowCast::string($row, 'd_gid')] = RowCast::int($row, 'd_year');
        }

        self::assertSame(1910, $births['I6'], 'Birth fact returns the birth year.');
        self::assertSame(['I6' => 1980], $deaths, 'Death fact returns only the single dated death.');
    }

    /**
     * Two same-fact rows that share the minimum `d_julianday1` (e.g. a Gregorian
     * and a Julian birth that map to the same julian day) must not surface the
     * individual twice — the `GROUP BY d_gid` keeps it exact-once. The collapse
     * is deterministic (numeric column minima), and the outer fact / date-type /
     * year filters keep an off-fact, off-calendar or year-less tie row from
     * joining back and corrupting the surviving values. Each injected poison row
     * carries a MIN-winning year (below the genuine 1900) so that a dropped
     * outer filter would visibly lower the surviving `d_year`.
     */
    #[Test]
    public function collapsesJulianDayTieToOneRow(): void
    {
        $tree = $this->importFixtureTree('deduped-event-dates.ged');

        self::assertSame(['I1', 'I2', 'I3', 'I4', 'I5', 'I6', 'I7'], $this->birthGids($tree));

        // I1's Gregorian birth is 1 JAN 1900 (d_julianday1 = 2415021, month 1).
        // Every poison row below shares that julian day, so the join-back admits
        // it unless an outer filter rejects it.

        // (a) A second Gregorian BIRT row, month 12 (December): the numeric
        // minimum (1) must win, never the ASCII-smaller 'DEC'. Year stays 1900
        // so this row legitimately survives — it only proves the tie collapses
        // to one row and the numeric month minimum wins.
        DB::table('dates')->insert($this->dateRow($tree, 'BIRT', '@#DGREGORIAN@', 12, 1900));

        // (b) Off-fact (DEAT): the outer d_fact filter must reject it.
        DB::table('dates')->insert($this->dateRow($tree, 'DEAT', '@#DGREGORIAN@', 1, 1));

        // (c) A Hebrew BIRT tie row is no longer rejected by calendar — it joins
        // I1's collapse. Its native Hebrew year (5661) sits far above the
        // Gregorian 1900, so the year minimum still surfaces 1900 and the
        // Gregorian d_type (G < H) wins the type minimum: the cross-calendar tie
        // stays one coherent row and does not corrupt the survivor.
        DB::table('dates')->insert($this->dateRow($tree, 'BIRT', '@#DHEBREW@', 1, 5661));

        // (d) Year-less (d_year = 0): the outer d_year<>0 filter must reject it.
        DB::table('dates')->insert($this->dateRow($tree, 'BIRT', '@#DGREGORIAN@', 1, 0));

        $yearByGid = [];
        $monByGid  = [];
        $typeByGid = [];

        foreach (DedupedEventDates::query($tree, 'BIRT')->get() as $row) {
            $gid             = RowCast::string($row, 'd_gid');
            $yearByGid[$gid] = RowCast::int($row, 'd_year');
            $monByGid[$gid]  = RowCast::int($row, 'd_mon');
            $typeByGid[$gid] = RowCast::string($row, 'd_type');
        }

        self::assertSame(
            ['I1', 'I2', 'I3', 'I4', 'I5', 'I6', 'I7'],
            $this->birthGids($tree),
            'The shared-julian-day tie stays one row per individual.',
        );
        self::assertSame(1900, $yearByGid['I1'], 'No off-fact or year-less tie row corrupts the survivor, and the kept Hebrew tie keeps the lower Gregorian-scale year.');
        self::assertSame(1, $monByGid['I1'], 'The numeric month minimum wins the Gregorian tie.');
        self::assertSame(
            '@#DGREGORIAN@',
            $typeByGid['I1'],
            'The Gregorian calendar wins the cross-calendar type minimum (G < H), so a consumer converts I1 by the native Gregorian year, not the Hebrew julian day.',
        );
    }

    /**
     * Build a `dates` row sharing I1's birth julian day (2415021) for the
     * tie-collapse test, parameterised by the columns the outer filters guard.
     *
     * @return array<string, int|string>
     */
    private function dateRow(Tree $tree, string $fact, string $type, int $mon, int $year): array
    {
        return [
            'd_gid'        => 'I1',
            'd_file'       => $tree->id(),
            'd_fact'       => $fact,
            'd_type'       => $type,
            'd_day'        => 0,
            'd_mon'        => $mon,
            'd_month'      => '',
            'd_year'       => $year,
            'd_julianday1' => 2415021,
            'd_julianday2' => 2415021,
        ];
    }

    /**
     * Sorted list of individual XREFs the helper returns for BIRT.
     *
     * @return list<string>
     */
    private function birthGids(Tree $tree): array
    {
        $gids = [];

        foreach (DedupedEventDates::query($tree, 'BIRT')->get() as $row) {
            $gids[] = RowCast::string($row, 'd_gid');
        }

        sort($gids);

        return $gids;
    }
}
