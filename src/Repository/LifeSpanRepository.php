<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;

use function array_fill_keys;
use function array_key_last;
use function array_map;
use function intdiv;
use function is_numeric;
use function max;

/**
 * Life-span aggregations for the LifeSpan tab. Wraps the public
 * accessors core's {@see StatisticsData} exposes (statsAgeQuery,
 * topTenOldestQuery, topTenOldestAliveQuery) into the widget-ready
 * shapes the Templates/LifeSpan.phtml partials consume.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class LifeSpanRepository
{
    /**
     * 10-year buckets up to "100+" — caps the histogram so the
     * long-tail outliers (a documented 110-year-old) don't stretch
     * the x-axis. Anything ≥ 100 collapses onto the 100+ bucket.
     */
    private const int BUCKET_WIDTH = 10;

    private const int MAX_BUCKET = 100;

    /**
     * Living-individual age-band cut-offs. The buckets are designed
     * to read as life-stages (minor / young adult / working age /
     * retired) rather than equal-width decades.
     *
     * @var list<array{label: string, max: int|null}>
     */
    private const array LIVING_AGE_BANDS = [
        ['label' => '0–17', 'max' => 17],
        ['label' => '18–35', 'max' => 35],
        ['label' => '36–65', 'max' => 65],
        ['label' => '65+', 'max' => null],
    ];

    /**
     * @param Tree           $tree The tree the statistics are computed for
     * @param StatisticsData $data Core accessor that already exposes the queries we need
     */
    public function __construct(
        private Tree $tree,
        private StatisticsData $data,
    ) {
    }

    /**
     * Age-at-death distribution bucketed into 10-year bands plus a
     * "100+" overflow. Empty buckets are kept in the output so the
     * histogram renders a continuous x-axis without gaps.
     *
     * @return array<string, int>
     */
    public function ageAtDeathDistribution(): array
    {
        $rows = $this->data->statsAgeQuery('ALL', 0, 0);

        $buckets = [];

        for ($age = 0; $age < self::MAX_BUCKET; $age += self::BUCKET_WIDTH) {
            $buckets[$this->bucketLabel($age)] = 0;
        }

        $buckets[$this->overflowLabel()] = 0;

        foreach ($rows as $row) {
            $days = $row->days;

            if ($days < 0) {
                continue;
            }

            $years = intdiv($days, 365);
            $label = $years >= self::MAX_BUCKET
                ? $this->overflowLabel()
                : $this->bucketLabel(intdiv($years, self::BUCKET_WIDTH) * self::BUCKET_WIDTH);

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Top-N oldest deceased individuals across the tree, formatted
     * as {label: "Given Surname (years)", value: years}.
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return array<string, int>
     */
    public function topOldestDeceased(int $limit): array
    {
        return $this->shapeOldest(
            $this->data->topTenOldestQuery('ALL', $limit),
        );
    }

    /**
     * Top-N oldest living individuals across the tree. Same
     * shape as {@see topOldestDeceased()} — age is the difference
     * between today and the BIRT date.
     *
     * @param int $limit Maximum number of rows to return.
     *
     * @return array<string, int>
     */
    public function topOldestLiving(int $limit): array
    {
        return $this->shapeOldest(
            $this->data->topTenOldestAliveQuery('ALL', $limit),
        );
    }

    /**
     * Living-individual count grouped by life-stage age-band. The
     * `data-widget=donut` partial reads this as
     * `[{label, value, class}]`; the `class` slot is wired through
     * to the SVG slice so the CSS palette can colour them
     * consistently with the existing donut widgets.
     *
     * @return list<array{label: string, value: int, class: string}>
     */
    public function livingByAgeBand(): array
    {
        $rows = DB::table('individuals')
            ->where('i_file', '=', $this->tree->id())
            ->whereNotExists(static function (Builder $query): void {
                $query
                    ->from('dates')
                    ->whereColumn('d_file', '=', 'i_file')
                    ->whereColumn('d_gid', '=', 'i_id')
                    ->where('d_fact', '=', 'DEAT');
            })
            ->join('dates AS birth', static function (JoinClause $join): void {
                $join
                    ->on('birth.d_file', '=', 'i_file')
                    ->on('birth.d_gid', '=', 'i_id')
                    ->where('birth.d_fact', '=', 'BIRT')
                    ->whereIn('birth.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);
            })
            ->select([
                new Expression(
                    'FLOOR((' . $this->julianTodayExpression()
                    . ' - ' . DB::connection()->getTablePrefix() . 'birth.d_julianday1) / 365) AS age',
                ),
            ])
            ->get();

        $bandCounts = array_fill_keys(
            array_map(static fn (array $b): string => $b['label'], self::LIVING_AGE_BANDS),
            0,
        );

        foreach ($rows as $row) {
            $rawAge = is_numeric($row->age) ? (int) $row->age : 0;
            $age    = max(0, $rawAge);
            ++$bandCounts[$this->bandLabel($age)];
        }

        $palette = ['age-band-0', 'age-band-1', 'age-band-2', 'age-band-3'];
        $entries = [];
        $index   = 0;

        foreach ($bandCounts as $label => $count) {
            $entries[] = [
                'label' => $label,
                'value' => $count,
                'class' => $palette[$index] ?? 'age-band-default',
            ];
            ++$index;
        }

        return $entries;
    }

    /**
     * @param iterable<object> $individuals Core query result (Individual collection).
     *
     * @return array<string, int>
     */
    private function shapeOldest(iterable $individuals): array
    {
        $out = [];

        foreach ($individuals as $entry) {
            $individual = $entry->individual ?? null;
            $rawDays    = $entry->days ?? 0;
            $days       = is_numeric($rawDays) ? (int) $rawDays : 0;

            if (!$individual instanceof Individual) {
                continue;
            }

            $years       = intdiv($days, 365);
            $label       = $individual->fullName() . ' (' . $years . ')';
            $out[$label] = $years;
        }

        return $out;
    }

    /**
     * Map a 0-indexed age value to the life-stage label whose
     * `max` is the smallest cap that still contains it.
     */
    private function bandLabel(int $age): string
    {
        foreach (self::LIVING_AGE_BANDS as $band) {
            if ($band['max'] === null || $age <= $band['max']) {
                return $band['label'];
            }
        }

        return self::LIVING_AGE_BANDS[array_key_last(self::LIVING_AGE_BANDS)]['label'];
    }

    /**
     * "0–9", "10–19", … for a given lower-bound age.
     */
    private function bucketLabel(int $lowerAge): string
    {
        return $lowerAge . '–' . ($lowerAge + self::BUCKET_WIDTH - 1);
    }

    /**
     * Overflow bucket label for ages ≥ MAX_BUCKET.
     */
    private function overflowLabel(): string
    {
        return self::MAX_BUCKET . '+';
    }

    /**
     * SQL expression that yields today's Julian-day-number for the
     * current calendar date. Lives in a helper so the driver
     * difference (SQLite vs MySQL/MariaDB) stays in one place.
     */
    private function julianTodayExpression(): string
    {
        $driver = DB::connection()->getDriverName();

        if ($driver === 'sqlite') {
            return "CAST(julianday('now') AS INTEGER)";
        }

        return 'TO_DAYS(CURDATE()) + 1721060';
    }
}
