<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

use function array_combine;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function html_entity_decode;
use function intdiv;
use function is_numeric;
use function is_string;
use function sort;
use function strip_tags;
use function substr;

use const ENT_HTML5;
use const ENT_QUOTES;

/**
 * Children-related aggregations for the Family tab. Combines core's
 * public accessors (averageChildrenPerFamily, statsChildrenQuery,
 * familiesWithTheMostChildren, countFamiliesWithNoChildren,
 * countFirstChildrenByMonth) with a local query for the
 * sibling-age-gap distribution.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ChildrenRepository
{
    /**
     * Children-per-family histogram is integer-bucketed: 0 child,
     * 1 child, 2 children, …, 9 children, and a "10+" overflow
     * for the heroic outliers.
     */
    private const int CHILDREN_HISTOGRAM_MAX = 10;

    /**
     * Sibling age gap is bucketed into 1-year bands up to 10 years
     * plus a "10+" overflow. Birth-spacing peaks usually sit in
     * the 1–3 year range so 1-year resolution is what surfaces
     * the typical curve.
     */
    private const int SIBLING_GAP_MAX = 10;

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
     * Histogram of children-per-family. Keyed by stringified child
     * count, "10+" for the overflow.
     *
     * @return array<array-key, int>
     */
    public function childrenPerFamilyHistogram(): array
    {
        $rows = DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->select(['f_numchil AS n'])
            ->get();

        $counts = [];

        for ($n = 0; $n <= self::CHILDREN_HISTOGRAM_MAX; ++$n) {
            $counts[] = 0;
        }

        foreach ($rows as $row) {
            $n = is_numeric($row->n ?? null) ? (int) $row->n : 0;

            if ($n < 0) {
                $n = 0;
            }

            $index = $n >= self::CHILDREN_HISTOGRAM_MAX ? self::CHILDREN_HISTOGRAM_MAX : $n;
            ++$counts[$index];
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
     * Distribution of gaps (in years) between consecutive siblings
     * across every family. Within each family the children are
     * sorted by BIRT julian-day; consecutive pairs contribute one
     * positive gap each. Families with < 2 dated children
     * contribute nothing.
     *
     * @return array<string, int>
     */
    public function siblingAgeGapDistribution(): array
    {
        $rows = DB::table('link')
            ->where('l_file', '=', $this->tree->id())
            ->where('l_type', '=', 'FAMC')
            ->join('dates AS birth', static function (JoinClause $join): void {
                $join
                    ->on('birth.d_file', '=', 'l_file')
                    ->on('birth.d_gid', '=', 'l_from')
                    ->where('birth.d_fact', '=', 'BIRT')
                    ->whereIn('birth.d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
                    ->where('birth.d_julianday1', '<>', 0);
            })
            ->select(['l_to AS family_id', 'birth.d_julianday1 AS birth_jd'])
            ->orderBy('l_to')
            ->orderBy('birth.d_julianday1')
            ->get();

        $perFamily = [];

        foreach ($rows as $row) {
            $rawFamId = $row->family_id ?? null;
            $famId    = is_string($rawFamId) ? $rawFamId : '';
            $birthJd  = is_numeric($row->birth_jd ?? null) ? (int) $row->birth_jd : 0;

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
                $gap = intdiv($jds[$i] - $jds[$i - 1], 365);

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
     * Top-N largest families ranked by f_numchil, returned in the
     * widget-ready {label, value} shape.
     *
     * @param int $limit Maximum number of rows.
     *
     * @return array<string, int>
     */
    public function topLargestFamilies(int $limit): array
    {
        $out = [];

        foreach ($this->data->familiesWithTheMostChildren($limit) as $entry) {
            $family   = $entry->family ?? null;
            $children = $entry->children ?? 0;

            if (!$family instanceof Family) {
                continue;
            }

            $plainName       = html_entity_decode(strip_tags($family->fullName()), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $out[$plainName] = $children;
        }

        return $out;
    }

    /**
     * Single individual with the highest aggregated child count
     * across every family they participated in. Different from
     * {@see largestFamilyRecord()} because a man married three
     * times with 5+4+3 children wins here (12 children total) but
     * not there (largest single family was 5). Counts each FAM's
     * `f_numchil` exactly once per spouse, so the same child does
     * not contribute to both parents' totals across remarriages.
     *
     * @return array{individual: Individual, count: int}|null
     */
    public function mostChildrenPerPersonRecord(): ?array
    {
        // Use the raw prefixed table name (wt_families) inside
        // `Expression` and `orderByRaw` — Eloquent only auto-prefixes
        // table aliases in `from` / `join`, not strings inside raw
        // SQL fragments, so the SUM here must reference the actual
        // physical table.
        $familiesTable = DB::connection()->getTablePrefix() . 'families';

        $row = DB::table('link')
            ->where('l_file', '=', $this->tree->id())
            ->where('l_type', '=', 'FAMS')
            ->join('families', static function (JoinClause $join): void {
                $join
                    ->on('families.f_file', '=', 'link.l_file')
                    ->on('families.f_id', '=', 'link.l_to');
            })
            ->where('families.f_numchil', '>', 0)
            ->groupBy('link.l_from')
            ->orderByRaw('SUM(' . $familiesTable . '.f_numchil) DESC')
            ->select([
                'link.l_from AS xref',
                new Expression('SUM(' . $familiesTable . '.f_numchil) AS total_children'),
            ])
            ->first();

        if ($row === null) {
            return null;
        }

        $xref  = is_string($row->xref ?? null) ? $row->xref : '';
        $total = is_numeric($row->total_children ?? null) ? (int) $row->total_children : 0;

        if (($xref === '') || ($total <= 0)) {
            return null;
        }

        $individual = Registry::individualFactory()->make($xref, $this->tree);

        if (!$individual instanceof Individual) {
            return null;
        }

        return ['individual' => $individual, 'count' => $total];
    }

    /**
     * Single largest-family record holder: the family with the
     * highest `f_numchil` count. Returns null when the tree has
     * no family with at least one child.
     *
     * @return array{family: Family, count: int}|null
     */
    public function largestFamilyRecord(): ?array
    {
        foreach ($this->data->familiesWithTheMostChildren(1) as $entry) {
            $family   = $entry->family ?? null;
            $children = $entry->children ?? 0;

            if (!$family instanceof Family) {
                continue;
            }

            if ($children <= 0) {
                continue;
            }

            return ['family' => $family, 'count' => $children];
        }

        return null;
    }

    /**
     * Childless-families donut data: {with, without} counts.
     *
     * @return list<array{label: string, value: int, class: string}>
     */
    public function childlessFamiliesBreakdown(): array
    {
        $total = DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->count();
        $withoutKids = $this->data->countFamiliesWithNoChildren();
        $withKids    = $total - $withoutKids;

        return [
            ['label' => 'With children', 'value' => $withKids, 'class' => 'with-children'],
            ['label' => 'Without children', 'value' => $withoutKids, 'class' => 'without-children'],
        ];
    }

    /**
     * First-children by GEDCOM month abbreviation — pass-through
     * over core's already-public accessor.
     *
     * @return array<string, int>
     */
    public function firstChildrenByMonth(): array
    {
        $rows = $this->data->countFirstChildrenByMonth(0, 0);

        if ($rows === []) {
            return [];
        }

        $labels = array_map(strval(...), array_keys($rows));
        $values = array_map(intval(...), array_values($rows));

        /** @var array<string, int> $combined */
        $combined = array_combine($labels, $values);

        return $combined;
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
