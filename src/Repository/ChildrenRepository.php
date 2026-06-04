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
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarPayload;
use MagicSunday\Webtrees\Statistic\Model\StackedBar\StackedBarSeries;
use MagicSunday\Webtrees\Statistic\Support\Calc\CalendarSpan;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;
use MagicSunday\Webtrees\Statistic\Support\Locale\DecadeName;

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
 * countFamiliesWithNoChildren, countFirstChildrenByMonth) with local queries
 * for the sibling-age-gap and family-size distributions. Entity rankings and
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
     * @param StatisticsData $data Core accessor (averageChildrenPerFamily, familiesWithTheMostChildren, countFamiliesWithNoChildren, countFirstChildrenByMonth)
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

            ++$counts[min($n, self::CHILDREN_HISTOGRAM_MAX)];
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
     * its earliest MARR year, then groups by century. Shared backing for the
     * century-bucketed cards (stacked share, average line).
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
            ->select(['f_id', 'f_numchil AS n', 'marr.d_year AS year'])
            ->get();

        /** @var array<string, array{n: int, year: int}> $perFamily */
        $perFamily = [];

        foreach ($rows as $row) {
            $familyId = RowCast::string($row, 'f_id');

            if ($familyId === '') {
                continue;
            }

            $year = RowCast::int($row, 'year');

            if (!isset($perFamily[$familyId])) {
                $childCount           = RowCast::int($row, 'n');
                $perFamily[$familyId] = [
                    'n'    => max($childCount, 0),
                    'year' => max($year, 0),
                ];

                continue;
            }

            if (($year > 0) && (($perFamily[$familyId]['year'] === 0) || ($year < $perFamily[$familyId]['year']))) {
                $perFamily[$familyId]['year'] = $year;
            }
        }

        $entries = [];

        foreach ($perFamily as $family) {
            if ($family['year'] <= 0) {
                continue;
            }

            $entries[] = $family;
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
     * stacked share charts.
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
            $short       = CenturyName::for($century);
            $longName    = CenturyName::longLabel($short);
            $familyCount = count($childCounts);
            $totalKids   = 0;

            foreach ($childCounts as $count) {
                $totalKids += $count;
            }

            $average      = $familyCount > 0 ? $totalKids / $familyCount : 0.0;
            $categories[] = CenturyName::compactLabel($short);
            $values[]     = $average;
            $tooltips[]   = I18N::translate(
                '%1$s children per family (n = %2$s)',
                I18N::number($average, 2),
                I18N::number($familyCount),
            );
            $tooltipLabels[] = $longName;
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
                'birth.d_year AS birth_year',
                'birth.d_julianday1 AS birth_jd',
            ])
            ->get();

        /** @var array<string, int> $yearByChild */
        $yearByChild = [];

        /** @var array<string, list<array{id: string, jd: int}>> $perFamily */
        $perFamily = [];

        foreach ($rows as $row) {
            $childId = RowCast::string($row, 'child_id');
            $famId   = RowCast::string($row, 'family_id');
            $year    = RowCast::int($row, 'birth_year');
            $birthJd = RowCast::int($row, 'birth_jd');

            if ($childId === '') {
                continue;
            }

            if ($famId === '') {
                continue;
            }

            if ($year <= 0) {
                continue;
            }

            if ($birthJd <= 0) {
                continue;
            }

            $yearByChild[$childId] = $year;
            $perFamily[$famId][]   = ['id' => $childId, 'jd' => $birthJd];
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

        foreach ($perFamily as $children) {
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
            $primaryChild    = $setChildren[0];
            $primaryYear     = $yearByChild[$primaryChild] ?? 0;

            if ($primaryYear <= 0) {
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
            $categories[]             = CenturyName::compactLabel(CenturyName::for($century));
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
                $total    = $totalsByCentury[$century];
                $count    = $multiplicityCountsByCentury[$century][$multiplicity] ?? 0;
                $setCount = $multiplicitySetsByCentury[$century][$multiplicity] ?? 0;
                $rate     = round(($count / $total) * 100, 2);

                $values[]        = $rate;
                $tooltipLabels[] = CenturyName::longLabel(CenturyName::for($century));
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
     * `BET..AND` / `FROM..TO` modifier are skipped: webtrees still synthesises
     * a default julian-day for those rows (usually 01.01.YYYY) so two year-only
     * siblings of the same year would collide at JD = same → phantom 0-year
     * bucket entry, and a `BET..AND` child shows up as two rows in the JOIN →
     * JD-sorted run produces a phantom self-gap with the same `i_id` on both
     * sides. Filtering on `d_day > 0 AND d_mon > 0` cuts both pathologies in
     * one stroke. Mixed families (some full-date, some not) still contribute
     * the surviving pairs — those overshoot the real consecutive distance,
     * which is the documented trade-off.
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
            ->select(['l_to AS family_id', 'birth.d_julianday1 AS birth_jd'])
            ->orderBy('l_to')
            ->orderBy('birth.d_julianday1')
            ->get();

        $perFamily = [];

        foreach ($rows as $row) {
            $famId   = RowCast::string($row, 'family_id');
            $birthJd = RowCast::int($row, 'birth_jd');

            if ($famId === '') {
                continue;
            }

            if ($birthJd <= 0) {
                continue;
            }

            $perFamily[$famId][] = $birthJd;
        }

        $buckets = $this->initSiblingBuckets();

        foreach ($perFamily as $jds) {
            if (count($jds) < 2) {
                continue;
            }

            sort($jds);
            $counter = count($jds);

            for ($i = 1; $i < $counter; ++$i) {
                $gap = CalendarSpan::wholeYears($jds[$i - 1], $jds[$i]);

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
     * First-children by GEDCOM month abbreviation — pass-through over core's
     * already-public accessor, prefilled with all twelve months at zero so the
     * view layer can render a continuous month axis even on sparse trees.
     * Matches the empty-contract sibling repositories use for `*byMonth`
     * aggregations.
     *
     * @return array<string, int>
     */
    public function firstChildrenByMonth(): array
    {
        $buckets = array_fill_keys(
            ['JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC'],
            0,
        );

        foreach ($this->data->countFirstChildrenByMonth(0, 0) as $month => $count) {
            $buckets[$month] = $count;
        }

        return $buckets;
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
