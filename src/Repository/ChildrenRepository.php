<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\I18N;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarPayload;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarSeries;
use MagicSunday\Webtrees\Statistic\Support\Calc\CalendarSpan;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use MagicSunday\Webtrees\Statistic\Support\Locale\DecadeName;
use MagicSunday\Webtrees\Statistic\Support\Locale\MonthName;

use function array_combine;
use function array_fill_keys;
use function array_keys;
use function count;
use function intdiv;
use function ksort;
use function max;
use function min;
use function round;
use function sort;
use function substr;
use function usort;

/**
 * Children-related aggregations for the Family tab. Combines core's public
 * accessors (averageChildrenPerFamily, statsChildrenQuery,
 * countFamiliesWithNoChildren) with local queries for the sibling-age-gap,
 * family-size and first-child-by-month distributions. Entity rankings and
 * record holders live in {@see FamilyRankingRepository}.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ChildrenRepository
{
    /**
     * Children-per-family histogram is integer-bucketed: 0 child, 1 child, 2
     * children, …, 9 children, and a "10+" overflow for the heroic outliers.
     */
    private const int CHILDREN_HISTOGRAM_MAX = 10;

    /**
     * Sibling age gap is bucketed into 1-year bands up to 10 years plus a "10+"
     * overflow. Birth-spacing peaks usually sit in the 1–3 year range so 1-year
     * resolution is what surfaces the typical curve.
     */
    private const int SIBLING_GAP_MAX = 10;

    /**
     * Minimum per-century sample size for the multiple-birth rate. The
     * biological baseline sits at ~1 % of births so 200 children per century is
     * the cohort floor below which a single missing twin set would swing the
     * rate by 0.5 percentage points or more.
     */
    private const int MIN_COHORT_MULTIPLE_BIRTH = 200;

    /**
     * Minimum per-century family count for the average-family-size line. Mirrors
     * the five-sample cohort floor the other timeline charts apply (child
     * mortality and tree health gate five per century, parental age five per
     * decade): below five dated families a single large or childless family
     * swings the mean by tenths of a child, so a thinner century would render a
     * spiky, unrepresentative point with the same visual weight as one backed by
     * thousands.
     */
    private const int MIN_COHORT_FAMILY_SIZE = 5;

    /**
     * Multiplicity cap for the per-century breakdown — twin / triplet /
     * quadruplet sets each get their own series, sets of five and above
     * collapse onto a single "quintuplet+" bucket so the chart stays readable
     * even on a tree with a heroic outlier.
     */
    private const int MULTIPLE_BIRTH_CAP = 5;

    /**
     * Maximum BIRT julian-day gap between two consecutively-born children of
     * the same FAM for them to count as one multiple-birth set. One day
     * accommodates cross-midnight twins (e.g. 31 DEC / 1 JAN) without merging
     * genuinely separate births, which a single mother cannot place within a
     * calendar day of each other anyway.
     */
    private const int MULTI_BIRTH_MAX_DAY_GAP = 1;

    /**
     * @param Tree           $tree The tree the statistics are computed for
     * @param StatisticsData $data Core accessor (averageChildrenPerFamily, familiesWithTheMostChildren, countFamiliesWithNoChildren)
     */
    public function __construct(
        private Tree $tree,
        private StatisticsData $data,
    ) {
    }

    /**
     * Average number of children per family across the whole tree.
     */
    public function averageChildrenPerFamily(): float
    {
        return $this->data->averageChildrenPerFamily();
    }

    /**
     * Histogram of children-per-family. Keyed by stringified child count, "10+"
     * for the overflow.
     *
     * @return array<array-key, int>
     */
    public function childrenPerFamilyDistribution(): array
    {
        $rows = TreeScope::table($this->tree, 'families')
            ->select(['f_numchil AS n'])
            ->get();

        $counts = [];

        for ($n = 0; $n <= self::CHILDREN_HISTOGRAM_MAX; ++$n) {
            $counts[] = 0;
        }

        foreach ($rows as $row) {
            $n = RowCast::int($row, 'n');

            if ($n < 0) {
                $n = 0;
            }

            $bucket          = min($n, self::CHILDREN_HISTOGRAM_MAX);
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }

        $labels = [];

        for ($n = 0; $n < self::CHILDREN_HISTOGRAM_MAX; ++$n) {
            $labels[] = 'c' . $n;
        }

        $labels[] = 'c' . self::CHILDREN_HISTOGRAM_MAX . '+';

        $result = array_combine($labels, $counts);

        // Strip the 'c' prefix that PHPStan needs to keep the keys
        // recognised as strings — callers see the natural "0",
        // "1", …, "10+" labels.
        $stripped = [];

        foreach ($result as $key => $value) {
            $stripped[substr($key, 1)] = $value;
        }

        return $stripped;
    }

    /**
     * Reads the raw `f_numchil` per family aggregated to one row per family at
     * its earliest MARR year, then groups by century. Backs the
     * average-children-per-family line ({@see averageFamilySizeByCentury()});
     * BCE marriages fold into negative centuries through {@see
     * CenturyName::fromYear()}.
     *
     * @return array<int, list<int>>
     */
    private function familySizesPerCenturyRaw(): array
    {
        $perCentury = [];

        foreach ($this->familiesByEarliestMarriageYear() as $entry) {
            $century                = CenturyName::fromYear($entry['year']);
            $perCentury[$century][] = $entry['n'];
        }

        return $perCentury;
    }

    /**
     * Load one row per dated family carrying its earliest MARR year and its
     * `f_numchil`. Multi-MARR families collapse to a single entry at the
     * earliest valid year so downstream bucketers see each family once.
     *
     * @return list<array{year: int, n: int}>
     */
    private function familiesByEarliestMarriageYear(): array
    {
        $rows = TreeScope::table($this->tree, 'families')
            ->leftJoin('dates AS marr', static function (JoinClause $join): void {
                DateJoin::on($join, 'marr', 'f_file', 'f_id', 'MARR');
            })
            ->select([
                'f_id',
                'f_numchil AS n',
                'marr.d_type AS type',
                'marr.d_year AS year',
                'marr.d_julianday1 AS jd',
            ])
            ->get();

        /** @var array<string, array{n: int, jd: int|null, type: string, year: int}> $perFamily */
        $perFamily = [];

        foreach ($rows as $row) {
            $familyId = RowCast::string($row, 'f_id');

            if ($familyId === '') {
                continue;
            }

            if (!isset($perFamily[$familyId])) {
                $childCount           = RowCast::int($row, 'n');
                $perFamily[$familyId] = [
                    'n'    => max($childCount, 0),
                    'jd'   => null,
                    'type' => '',
                    'year' => 0,
                ];
            }

            // Track the chronologically earliest MARR per family by its
            // calendar-neutral julian day, not the native d_year (a Hebrew 5784
            // is not comparable to a Gregorian 1900). The leftJoin's no-MARR
            // rows carry jd 0 and never compete; a matched row always has jd > 0
            // (DateJoin filters `d_julianday1 <> 0`). Smaller julian day = earlier,
            // across the BCE/CE boundary too. The earliest row's own type + year
            // are kept coherently for the later Gregorian conversion.
            $jd = RowCast::int($row, 'jd');

            if ($jd > 0) {
                $current = $perFamily[$familyId]['jd'];

                if (($current === null) || ($jd < $current)) {
                    $perFamily[$familyId]['jd']   = $jd;
                    $perFamily[$familyId]['type'] = RowCast::string($row, 'type');
                    $perFamily[$familyId]['year'] = RowCast::int($row, 'year');
                }
            }
        }

        $entries = [];

        foreach ($perFamily as $family) {
            $jd = $family['jd'];

            if ($jd === null) {
                continue;
            }

            $entries[] = [
                'n'    => $family['n'],
                'year' => GregorianDate::year($family['type'], $family['year'], $jd),
            ];
        }

        return $entries;
    }

    /**
     * Family-size composition as a StackedBar payload — one bar per decade
     * (1900s, 1910s, …), segments stack 1/2/3/4+ children. Drops the "0
     * children" group so the bar height tracks the recorded children. Decade
     * label uses the `${start}s` convention to dodge German locale's
     * thousand-separator formatting ("2000s" rather than "2.000s").
     */
    public function familySizeStackedByDecade(): StackedBarPayload
    {
        $perDecade = [];

        foreach ($this->familiesByEarliestMarriageYear() as $entry) {
            // `intdiv` groups a BCE year by magnitude toward zero (−55 → −50),
            // so a negative key is the matching BCE decade; DecadeName::for()
            // labels it "50s BCE". The chart is sparse (only populated decades
            // become bars), so a BCE-straddling tree never explodes the axis.
            $periodStart               = intdiv($entry['year'], 10) * 10;
            $perDecade[$periodStart][] = $entry['n'];
        }

        if ($perDecade === []) {
            return new StackedBarPayload(categories: [], tooltipLabels: [], series: []);
        }

        ksort($perDecade);

        $categories       = [];
        $tooltipLabels    = [];
        $perDecadeBuckets = [];

        foreach ($perDecade as $decade => $childCounts) {
            $categories[]    = DecadeName::for($decade);
            $tooltipLabels[] = DecadeName::longLabel($decade);

            $b1 = 0;
            $b2 = 0;
            $b3 = 0;
            $b4 = 0;

            foreach ($childCounts as $count) {
                if ($count <= 0) {
                    continue;
                }

                if ($count === 1) {
                    ++$b1;
                } elseif ($count === 2) {
                    ++$b2;
                } elseif ($count === 3) {
                    ++$b3;
                } else {
                    ++$b4;
                }
            }

            $perDecadeBuckets[] = [$b1, $b2, $b3, $b4];
        }

        $bucketDefs = [
            [
                'name'  => I18N::plural('%s child', '%s children', 1, I18N::number(1)),
                'class' => 'family-size-1',
                'index' => 0,
            ],
            [
                'name'  => I18N::plural('%s child', '%s children', 2, I18N::number(2)),
                'class' => 'family-size-2',
                'index' => 1,
            ],
            [
                'name'  => I18N::plural('%s child', '%s children', 3, I18N::number(3)),
                'class' => 'family-size-3',
                'index' => 2,
            ],
            [
                'name'  => I18N::translate('%s or more children', I18N::number(4)),
                'class' => 'family-size-max',
                'index' => 3,
            ],
        ];

        $series = [];

        foreach ($bucketDefs as $def) {
            $data = [];

            foreach ($perDecadeBuckets as $buckets) {
                $data[] = $buckets[$def['index']];
            }

            $series[] = new StackedBarSeries(
                name: $def['name'],
                data: $data,
                class: $def['class'],
            );
        }

        return new StackedBarPayload(
            categories: $categories,
            tooltipLabels: $tooltipLabels,
            series: $series,
        );
    }

    /**
     * Average children per family by century — single LineChart series tracking
     * the central tendency over time. Computed as `total_children /
     * family_count` per century from the same MARR-anchored aggregation as the
     * stacked share charts. Centuries backed by fewer than
     * {@see self::MIN_COHORT_FAMILY_SIZE} dated families are dropped so a thin
     * cohort cannot spike the line — the same five-sample cohort floor the
     * child-mortality and tree-health per-century timelines apply.
     */
    public function averageFamilySizeByCentury(): LineChartPayload
    {
        $perCentury = $this->familySizesPerCenturyRaw();

        if ($perCentury === []) {
            return new LineChartPayload(categories: [], series: []);
        }

        ksort($perCentury);

        $categories    = [];
        $values        = [];
        $tooltips      = [];
        $tooltipLabels = [];

        foreach ($perCentury as $century => $childCounts) {
            $familyCount = count($childCounts);

            // Drop centuries below the cohort floor — a handful of families
            // yields a spiky mean that would carry the same visual weight as a
            // century backed by thousands.
            if ($familyCount < self::MIN_COHORT_FAMILY_SIZE) {
                continue;
            }

            $longName  = CenturyName::longLabel($century);
            $totalKids = 0;

            foreach ($childCounts as $count) {
                $totalKids += $count;
            }

            $average      = $totalKids / $familyCount;
            $categories[] = CenturyName::compactLabel($century);
            $values[]     = $average;
            $tooltips[]   = I18N::translate(
                '%1$s children per family (n = %2$s)',
                I18N::number($average, 2),
                I18N::number($familyCount),
            );
            $tooltipLabels[] = $longName;
        }

        if ($categories === []) {
            return new LineChartPayload(categories: [], series: []);
        }

        return new LineChartPayload(
            categories: $categories,
            series: [
                new LineChartSeries(
                    name: I18N::translate('Children per family'),
                    values: $values,
                    tooltips: $tooltips,
                    tooltipLabels: $tooltipLabels,
                ),
            ],
        );
    }

    /**
     * Multiple-birth rate per century, one series per multiplicity that
     * actually occurs in the tree (twins, triplets, quadruplets, quintuplet+).
     * Each series carries the per-century share of children that landed in a
     * set of that size, so the chart-lib `multiSeriesArea: true` consumer draws
     * a stacked-style area fill where the twin band dwarfs the triplet /
     * quadruplet bands by an order of magnitude — the demographic signal worth
     * surfacing.
     *
     * Detection: children of the same FAM whose BIRT julian-days sit within
     * {@see MULTI_BIRTH_MAX_DAY_GAP} of each other form a multiple-birth set.
     * That subsumes exact same-day twin / triplet sets and the cross-midnight
     * case (e.g. 31 DEC / 1 JAN): a single mother cannot place two separate
     * pregnancies a calendar day apart, so the FAM membership plus date
     * proximity is the signal by itself.
     * Centuries below {@see MIN_COHORT_MULTIPLE_BIRTH} dated births are dropped
     * to keep the curve from spiking on small denominators.
     */
    public function multipleBirthRateByCentury(): LineChartPayload
    {
        $rows = TreeScope::table($this->tree, 'link')
            ->where('l_type', '=', 'FAMC')
            ->join('dates AS birth', static function (JoinClause $join): void {
                DateJoin::on($join, 'birth', 'l_file', 'l_from', 'BIRT', DateJoin::JD_NOT_EQUAL_ZERO, true);
            })
            ->select([
                'l_from AS child_id',
                'l_to AS family_id',
                'birth.d_type AS birth_type',
                'birth.d_year AS birth_year',
                'birth.d_julianday1 AS birth_jd',
            ])
            ->get();

        /** @var array<string, int> $yearByChild */
        $yearByChild = [];

        /** @var array<string, array<string, int>> $perFamily Family id → child id → lower-bound birth julian day */
        $perFamily = [];

        foreach ($rows as $row) {
            $childId = RowCast::string($row, 'child_id');
            $famId   = RowCast::string($row, 'family_id');
            $birthJd = RowCast::int($row, 'birth_jd');

            if ($childId === '') {
                continue;
            }

            if ($famId === '') {
                continue;
            }

            // The julian day is the date-presence guard (positive for every real
            // date, BCE included; 0 is the date-less sentinel).
            if ($birthJd <= 0) {
                continue;
            }

            // Collapse to one BIRT row per child: a child dated in two calendars
            // (a same-day Gregorian/Julian transcription) or with a day-precise
            // `BET`/`FROM` range writes more than one row, and without this the
            // cluster walk below would read those rows as separate siblings and
            // fabricate a multiple birth out of a singleton. Keep the lower-bound
            // (minimum julian day) row so each child clusters once and its bucket
            // year is read from that same row.
            if (isset($perFamily[$famId][$childId]) && ($birthJd >= $perFamily[$famId][$childId])) {
                continue;
            }

            // Bucket by the GREGORIAN birth year: native d_year for
            // Gregorian/Julian, the julian day converted otherwise. Only the
            // degenerate unparseable year 0 is then dropped — BCE (negative)
            // years fold into negative centuries through CenturyName::fromYear().
            $year = GregorianDate::year(
                RowCast::string($row, 'birth_type'),
                RowCast::int($row, 'birth_year'),
                $birthJd,
            );

            if ($year === 0) {
                continue;
            }

            $yearByChild[$childId]       = $year;
            $perFamily[$famId][$childId] = $birthJd;
        }

        /** @var array<int, int> $totalsByCentury */
        $totalsByCentury = [];

        foreach ($yearByChild as $year) {
            $century                   = CenturyName::fromYear($year);
            $totalsByCentury[$century] = ($totalsByCentury[$century] ?? 0) + 1;
        }

        /** @var array<int, array<int, int>> $multiplicityCountsByCentury */
        $multiplicityCountsByCentury = [];

        /** @var array<int, array<int, int>> $multiplicitySetsByCentury */
        $multiplicitySetsByCentury = [];

        /** @var list<list<string>> $clusters */
        $clusters = [];

        foreach ($perFamily as $childJds) {
            // One entry per child (the per-child collapse above), reshaped into
            // the {id, jd} pairs the walk sorts on.
            $children = [];

            foreach ($childJds as $id => $jd) {
                $children[] = ['id' => $id, 'jd' => $jd];
            }

            // Order this FAM's children by birth julian-day, then a
            // single forward walk groups every sibling sitting within
            // MULTI_BIRTH_MAX_DAY_GAP of the cluster's earliest birth
            // (the anchor). Anchoring on the earliest — rather than
            // chaining off the previous child — keeps each set inside
            // a one-day span: a cross-midnight set (two before
            // midnight, one after → jd, jd, jd+1) still merges, while
            // a jd, jd+1, jd+2 run does not chain past the window into
            // a spurious triplet.
            usort($children, static fn (array $a, array $b): int => $a['jd'] <=> $b['jd']);

            $cluster  = [];
            $anchorJd = null;

            foreach ($children as $child) {
                if (($anchorJd !== null) && (($child['jd'] - $anchorJd) > self::MULTI_BIRTH_MAX_DAY_GAP)) {
                    if (count($cluster) >= 2) {
                        $clusters[] = $cluster;
                    }

                    $cluster  = [];
                    $anchorJd = null;
                }

                if ($anchorJd === null) {
                    $anchorJd = $child['jd'];
                }

                $cluster[] = $child['id'];
            }

            if (count($cluster) >= 2) {
                $clusters[] = $cluster;
            }
        }

        foreach ($clusters as $setChildren) {
            $size            = count($setChildren);
            $multiplicityKey = $size >= self::MULTIPLE_BIRTH_CAP ? self::MULTIPLE_BIRTH_CAP : $size;
            $primaryChild    = $setChildren[0] ?? '';
            $primaryYear     = $yearByChild[$primaryChild] ?? 0;

            // The `?? 0` fallback (unreachable in practice) plus the degenerate
            // year 0 stay out; BCE (negative) years fold into negative centuries.
            if ($primaryYear === 0) {
                continue;
            }

            $primaryCentury = CenturyName::fromYear($primaryYear);
            $multiplicityCountsByCentury[$primaryCentury][$multiplicityKey]
                = ($multiplicityCountsByCentury[$primaryCentury][$multiplicityKey] ?? 0) + $size;
            $multiplicitySetsByCentury[$primaryCentury][$multiplicityKey]
                = ($multiplicitySetsByCentury[$primaryCentury][$multiplicityKey] ?? 0) + 1;
        }

        if ($totalsByCentury === []) {
            return new LineChartPayload(categories: [], series: []);
        }

        ksort($totalsByCentury);

        // Only emit series for multiplicities that actually occur.
        $multiplicitiesPresent = [];

        foreach ($multiplicityCountsByCentury as $byMultiplicity) {
            foreach (array_keys($byMultiplicity) as $multiplicity) {
                $multiplicitiesPresent[$multiplicity] = true;
            }
        }

        if ($multiplicitiesPresent === []) {
            return new LineChartPayload(categories: [], series: []);
        }

        ksort($multiplicitiesPresent);
        $multiplicities = array_keys($multiplicitiesPresent);

        $categories             = [];
        $qualifyingCenturyOrder = [];

        foreach ($totalsByCentury as $century => $total) {
            if ($total < self::MIN_COHORT_MULTIPLE_BIRTH) {
                continue;
            }

            $qualifyingCenturyOrder[] = $century;
            $categories[]             = CenturyName::compactLabel($century);
        }

        if ($categories === []) {
            return new LineChartPayload(categories: [], series: []);
        }

        $series = [];

        foreach ($multiplicities as $multiplicity) {
            $values        = [];
            $tooltips      = [];
            $tooltipLabels = [];

            foreach ($qualifyingCenturyOrder as $century) {
                $total    = $totalsByCentury[$century] ?? 0;
                $count    = $multiplicityCountsByCentury[$century][$multiplicity] ?? 0;
                $setCount = $multiplicitySetsByCentury[$century][$multiplicity] ?? 0;
                $rate     = round(($count / $total) * 100, 2);

                $values[]        = $rate;
                $tooltipLabels[] = CenturyName::longLabel($century);
                $tooltips[]      = $this->multipleBirthTooltip($multiplicity, $count, $setCount, $total, $rate);
            }

            $series[] = new LineChartSeries(
                name: $this->multiplicitySeriesName($multiplicity),
                values: $values,
                tooltips: $tooltips,
                tooltipLabels: $tooltipLabels,
                class: $this->multiplicitySeriesClass($multiplicity),
            );
        }

        return new LineChartPayload(
            categories: $categories,
            series: $series,
        );
    }

    /**
     * Display name for a per-multiplicity LineChart series. Caps at
     * "Quintuplets and above" so sextuplets and beyond collapse onto a single
     * readable label.
     */
    private function multiplicitySeriesName(int $multiplicity): string
    {
        if ($multiplicity >= self::MULTIPLE_BIRTH_CAP) {
            return I18N::translate('Quintuplets and above');
        }

        return match ($multiplicity) {
            2       => I18N::translate('Twins'),
            3       => I18N::translate('Triplets'),
            4       => I18N::translate('Quadruplets'),
            default => I18N::translate('Multiple births'),
        };
    }

    /**
     * Stable CSS class hook so the host stylesheet can pin each multiplicity
     * series to a fixed colour token.
     */
    private function multiplicitySeriesClass(int $multiplicity): string
    {
        if ($multiplicity >= self::MULTIPLE_BIRTH_CAP) {
            return 'multiple-birth-quintuplet-plus';
        }

        return match ($multiplicity) {
            2       => 'multiple-birth-twin',
            3       => 'multiple-birth-triplet',
            4       => 'multiple-birth-quadruplet',
            default => 'multiple-birth-other',
        };
    }

    /**
     * Compose a per-point tooltip body: "N children of M (X.XX %) — Y twin /
     * triplet / quadruplet sets". Multiplicity drives the narrative noun so the
     * reader sees what kind of set the count represents. `$setCount` is the
     * actual number of sets collected (not derived from `$count /
     * $multiplicity`, which would drift in the cap bucket when
     * heterogeneous-size sets pool together).
     */
    private function multipleBirthTooltip(int $multiplicity, int $count, int $setCount, int $total, float $rate): string
    {
        $head = I18N::translate(
            '%1$s of %2$s children (%3$s %%)',
            I18N::number($count),
            I18N::number($total),
            I18N::number($rate, 2),
        );

        if ($count === 0) {
            return $head;
        }

        if ($multiplicity >= self::MULTIPLE_BIRTH_CAP) {
            return $head . ' — ' . I18N::plural(
                '%s quintuplet+ set',
                '%s quintuplet+ sets',
                $setCount,
                I18N::number($setCount),
            );
        }

        $setProse = match ($multiplicity) {
            2       => I18N::plural('%s twin set', '%s twin sets', $setCount, I18N::number($setCount)),
            3       => I18N::plural('%s triplet set', '%s triplet sets', $setCount, I18N::number($setCount)),
            4       => I18N::plural('%s quadruplet set', '%s quadruplet sets', $setCount, I18N::number($setCount)),
            default => '',
        };

        if ($setProse === '') {
            return $head;
        }

        return $head . ' — ' . $setProse;
    }

    /**
     * Distribution of gaps (in years) between consecutive siblings across every
     * family. Within each family the children are sorted by BIRT julian-day;
     * consecutive pairs contribute one positive gap each. Families with < 2
     * dated children contribute nothing.
     *
     * Children whose BIRT is year-only or carries a `BEF` / `AFT` / `ABT` /
     * year-imprecise `BET..AND` / `FROM..TO` modifier are skipped: webtrees
     * still synthesises a default julian-day for those rows (usually 01.01.YYYY)
     * with `d_day = 0`, so two year-only siblings of the same year would collide
     * at JD = same → phantom 0-year bucket entry. Filtering on `d_day > 0 AND
     * d_mon > 0` cuts those out. A DAY-precise range (`BET 30 DEC 1850 AND 31
     * DEC 1850`), however, writes two rows that BOTH carry a non-zero day and
     * therefore survive that gate; the per-child collapse below keeps only that
     * child's lower-bound row, so its two rows cannot form a phantom self-gap or
     * double-count the gaps to real siblings. Mixed families (some full-date,
     * some not) still contribute the surviving pairs — those overshoot the real
     * consecutive distance, which is the documented trade-off.
     *
     * @return array<string, int>
     */
    public function siblingAgeGapDistribution(): array
    {
        $rows = TreeScope::table($this->tree, 'link')
            ->where('l_type', '=', 'FAMC')
            ->join('dates AS birth', static function (JoinClause $join): void {
                DateJoin::on($join, 'birth', 'l_file', 'l_from', 'BIRT', DateJoin::JD_NOT_EQUAL_ZERO, true);
            })
            ->select(['l_to AS family_id', 'l_from AS child_id', 'birth.d_julianday1 AS birth_jd'])
            ->orderBy('l_to')
            ->orderBy('birth.d_julianday1')
            ->get();

        /** @var array<string, array<string, int>> $perFamily Family id → child id → lower-bound birth julian day */
        $perFamily = [];

        foreach ($rows as $row) {
            $famId   = RowCast::string($row, 'family_id');
            $childId = RowCast::string($row, 'child_id');
            $birthJd = RowCast::int($row, 'birth_jd');

            if ($famId === '') {
                continue;
            }

            if ($childId === '') {
                continue;
            }

            if ($birthJd <= 0) {
                continue;
            }

            // Collapse to one BIRT row per child: a day-precise `BET`/`FROM`
            // range (both bounds carry a non-zero day, so the full-date gate
            // keeps them) or a cross-calendar dual-dating writes more than one
            // row. Without this the gap walk reads one child's rows as a phantom
            // self-gap and double-counts the gaps to real siblings. Keep the
            // lower-bound (minimum julian day) row.
            if (isset($perFamily[$famId][$childId]) && ($birthJd >= $perFamily[$famId][$childId])) {
                continue;
            }

            $perFamily[$famId][$childId] = $birthJd;
        }

        $buckets = $this->initSiblingBuckets();

        foreach ($perFamily as $jdsByChild) {
            if (count($jdsByChild) < 2) {
                continue;
            }

            $jds = array_values($jdsByChild);
            sort($jds);
            $counter = count($jds);

            for ($i = 1; $i < $counter; ++$i) {
                $gap = CalendarSpan::wholeYears($jds[$i - 1] ?? 0, $jds[$i] ?? 0);

                if ($gap < 0) {
                    continue;
                }

                $label           = $this->siblingBucketLabel($gap);
                $buckets[$label] = ($buckets[$label] ?? 0) + 1;
            }
        }

        return $buckets;
    }

    /**
     * Childless-families donut data: {with, without} counts.
     *
     * @return list<array{label: string, value: int, class: string}>
     */
    public function childlessFamiliesDistribution(): array
    {
        $total = TreeScope::table($this->tree, 'families')
            ->count();
        $withoutKids = $this->data->countFamiliesWithNoChildren();
        $withKids    = $total - $withoutKids;

        return [
            ['label' => I18N::translate('With children'), 'value' => $withKids, 'class' => 'with-children'],
            ['label' => I18N::translate('Without children'), 'value' => $withoutKids, 'class' => 'without-children'],
        ];
    }

    /**
     * First-children by GEDCOM month abbreviation, prefilled with all twelve
     * months at zero so the view layer can render a continuous month axis even
     * on sparse trees. Matches the empty-contract sibling repositories use for
     * `*byMonth` aggregations.
     *
     * Reimplements webtrees core's first-child query with two corrections that
     * the pass-through could not make: it anchors each family's earliest child
     * on the `BIRT` fact only (core's subquery picks the minimum julian day
     * across *every* dated fact on the child, so an erroneous earlier non-birth
     * fact would mis-attribute the family's first-child month), and it collapses
     * the family to a single row before the month tally (core joins back on
     * `MIN(d_julianday1) = d_julianday1`, so two children born on the same
     * julian day — twins — both satisfy the equality and count the family
     * twice). A boundary-straddling `BET..AND` first birth still counts once in
     * its lower-bound month, exactly as before.
     *
     * @return array<string, int>
     */
    public function firstChildrenByMonth(): array
    {
        $codes   = MonthName::codes();
        $buckets = array_fill_keys($codes, 0);

        foreach ($this->firstChildBirthMonthByFamily() as $row) {
            // Bucket by the GREGORIAN month: native d_mon for Gregorian/Julian,
            // the lower-bound julian day converted otherwise, so a non-Gregorian
            // first birth lands in the month it actually fell in. Mapping the
            // numeric month through the canonical lookup (never the GEDCOM string
            // column) keeps the order chronological, not lexicographic.
            [, $month] = GregorianDate::fromEventRow(
                RowCast::string($row, 'birth_type'),
                RowCast::int($row, 'birth_year'),
                RowCast::int($row, 'birth_mon'),
                RowCast::int($row, 'birth_day'),
                RowCast::int($row, 'birth_jd'),
            );

            $abbreviation = $codes[$month] ?? null;

            if ($abbreviation !== null) {
                $buckets[$abbreviation] = ($buckets[$abbreviation] ?? 0) + 1;
            }
        }

        return $buckets;
    }

    /**
     * One row per family carrying the representative `BIRT` date fields
     * (`birth_type`, `birth_year`, `birth_mon`, `birth_day`, `birth_jd`) of that
     * family's earliest-born child, from which the consumer derives the Gregorian
     * month. Mirrors webtrees core's first-child query — children are reached
     * through their family's `CHIL` links (`l_from` = family, `l_to` = child) —
     * with two corrections: the earliest child is anchored on the `BIRT` fact
     * only, and the join-back to that single minimum julian day is collapsed by
     * family so same-julian-day twins contribute one row, not two. The `BIRT`
     * predicate is repeated on the outer join on purpose — without it a non-birth
     * row sharing the representative julian day would join back and re-introduce
     * the very anchor defect this query exists to remove. The full numeric date
     * fields are exposed (never the GEDCOM string `d_month`) so the consumer's
     * {@see GregorianDate} conversion stays chronological rather than
     * lexicographic — converting a non-Gregorian first birth to the Gregorian
     * month it actually fell in. The rare exact same-julian-day cross-calendar
     * tie (one physical day dual-dated in two calendars whose native months
     * differ) keeps the documented per-column-`MIN()` limitation: its month may
     * be read from a different tied row than its type — a low-severity bucket
     * shift not worth the per-row re-aggregation a SQL tie-break would cost.
     *
     * @return Collection<int, object>
     */
    private function firstChildBirthMonthByFamily(): Collection
    {
        // A BIRT fact with a known month; a year-only birth (`d_mon = 0`) is
        // dropped on both the subquery and the join-back, exactly as in core's
        // first-child query, so a family anchors on its earliest month-dated
        // child rather than an undated earlier one.
        $birthJoin = static function (JoinClause $join): void {
            DateJoin::on($join, 'birth', 'chil.l_file', 'chil.l_to', 'BIRT', DateJoin::JD_NOT_EQUAL_ZERO);
            $join->where('birth.d_mon', '>', 0);
        };

        $earliestBirth = TreeScope::table($this->tree, 'link', 'chil')
            ->where('chil.l_type', '=', 'CHIL')
            ->join('dates AS birth', $birthJoin)
            ->groupBy('chil.l_from')
            ->select([
                'chil.l_from AS family_id',
                DateAggregate::min('birth', 'd_julianday1', 'min_birth_jd'),
            ]);

        return TreeScope::table($this->tree, 'link', 'chil')
            ->where('chil.l_type', '=', 'CHIL')
            ->join('dates AS birth', $birthJoin)
            ->joinSub($earliestBirth, 'first', static function (JoinClause $join): void {
                $join
                    ->on('first.family_id', '=', 'chil.l_from')
                    ->on('first.min_birth_jd', '=', 'birth.d_julianday1');
            })
            ->groupBy('chil.l_from')
            ->select([
                DateAggregate::min('birth', 'd_type', 'birth_type'),
                DateAggregate::min('birth', 'd_year', 'birth_year'),
                DateAggregate::min('birth', 'd_mon', 'birth_mon'),
                DateAggregate::min('birth', 'd_day', 'birth_day'),
                DateAggregate::min('birth', 'd_julianday1', 'birth_jd'),
            ])
            ->get();
    }

    /**
     * @return array<string, int>
     */
    private function initSiblingBuckets(): array
    {
        $buckets = [];

        for ($years = 0; $years < self::SIBLING_GAP_MAX; ++$years) {
            $buckets[$years . 'y'] = 0;
        }

        $buckets[self::SIBLING_GAP_MAX . 'y+'] = 0;

        return $buckets;
    }

    private function siblingBucketLabel(int $gap): string
    {
        if ($gap >= self::SIBLING_GAP_MAX) {
            return self::SIBLING_GAP_MAX . 'y+';
        }

        return $gap . 'y';
    }
}
