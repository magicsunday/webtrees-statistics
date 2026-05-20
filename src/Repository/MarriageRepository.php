<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\JoinClause;

use function abs;
use function array_combine;
use function array_keys;
use function array_map;
use function array_values;
use function intdiv;
use function is_numeric;

/**
 * Marriage-related aggregations for the Family tab. Combines core's
 * {@see StatisticsData::statsMarrAgeQuery()} (age at marriage per
 * sex) with local queries for duration distribution and couple
 * age-gap distribution.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class MarriageRepository
{
    /**
     * Age-at-marriage histogram uses 5-year bands. Smaller than the
     * lifespan histogram because the relevant span (15–60) is also
     * narrower.
     */
    private const int AGE_AT_MARRIAGE_BUCKET = 5;

    private const int AGE_AT_MARRIAGE_MAX = 60;

    /**
     * Marriage-duration histogram uses 10-year bands up to 60+.
     */
    private const int DURATION_BUCKET = 10;

    private const int DURATION_MAX = 60;

    /**
     * Couple-age-gap histogram is centred on zero and 5-year wide.
     * Negative buckets mean wife older than husband.
     */
    private const int AGE_GAP_BUCKET = 5;

    private const int AGE_GAP_LIMIT_HI = 30;

    /**
     * @param Tree           $tree The tree the statistics are computed for
     * @param StatisticsData $data Core accessor that exposes statsMarrAgeQuery + countEventsByCentury
     */
    public function __construct(
        private Tree $tree,
        private StatisticsData $data,
    ) {
    }

    /**
     * Age-at-marriage distribution for one sex, bucketed into
     * 5-year bands plus a 60+ overflow.
     *
     * @param string $sex 'M' for husbands, 'F' for wives
     *
     * @return array<string, int>
     */
    public function ageAtMarriageDistribution(string $sex): array
    {
        $rows    = $this->data->statsMarrAgeQuery($sex, 0, 0);
        $buckets = $this->initBuckets(0, self::AGE_AT_MARRIAGE_MAX, self::AGE_AT_MARRIAGE_BUCKET);

        foreach ($rows as $row) {
            $days = $row->age ?? 0;

            if ($days <= 0) {
                continue;
            }

            $years = intdiv($days, 365);
            $label = $this->bucketLabel($years, self::AGE_AT_MARRIAGE_MAX, self::AGE_AT_MARRIAGE_BUCKET);

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Marriage-duration distribution: years between MARR and the
     * earlier of the two spouses' DEAT (or DIV). Bucketed into
     * 10-year bands up to 60+.
     *
     * @return array<string, int>
     */
    public function durationDistribution(): array
    {
        $rows = DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->join('dates AS marr', static function (JoinClause $join): void {
                $join
                    ->on('marr.d_file', '=', 'f_file')
                    ->on('marr.d_gid', '=', 'f_id')
                    ->where('marr.d_fact', '=', 'MARR')
                    ->whereIn('marr.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);
            })
            ->leftJoin('dates AS divr', static function (JoinClause $join): void {
                $join
                    ->on('divr.d_file', '=', 'f_file')
                    ->on('divr.d_gid', '=', 'f_id')
                    ->where('divr.d_fact', '=', 'DIV')
                    ->whereIn('divr.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);
            })
            ->leftJoin('dates AS husb_d', static function (JoinClause $join): void {
                $join
                    ->on('husb_d.d_file', '=', 'f_file')
                    ->on('husb_d.d_gid', '=', 'f_husb')
                    ->where('husb_d.d_fact', '=', 'DEAT')
                    ->whereIn('husb_d.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);
            })
            ->leftJoin('dates AS wife_d', static function (JoinClause $join): void {
                $join
                    ->on('wife_d.d_file', '=', 'f_file')
                    ->on('wife_d.d_gid', '=', 'f_wife')
                    ->where('wife_d.d_fact', '=', 'DEAT')
                    ->whereIn('wife_d.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);
            })
            ->select([
                'marr.d_julianday1 AS marr_jd',
                'divr.d_julianday1 AS div_jd',
                'husb_d.d_julianday1 AS husb_jd',
                'wife_d.d_julianday1 AS wife_jd',
            ])
            ->get();

        $buckets = $this->initBuckets(0, self::DURATION_MAX, self::DURATION_BUCKET);

        foreach ($rows as $row) {
            $marrJd = is_numeric($row->marr_jd ?? null) ? (int) $row->marr_jd : 0;

            if ($marrJd <= 0) {
                continue;
            }

            $endJd = $this->earliestPositive([
                $row->div_jd ?? null,
                $row->husb_jd ?? null,
                $row->wife_jd ?? null,
            ]);

            if ($endJd === null) {
                continue;
            }

            if ($endJd <= $marrJd) {
                continue;
            }

            $years = intdiv($endJd - $marrJd, 365);
            $label = $this->bucketLabel($years, self::DURATION_MAX, self::DURATION_BUCKET);

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Couple age-gap distribution. Husband's birth year minus
     * wife's birth year, bucketed into symmetric 5-year bands
     * centred on zero. Negative buckets read "wife older than
     * husband".
     *
     * @return array<string, int>
     */
    public function ageGapDistribution(): array
    {
        $rows = DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->join('dates AS hb', static function (JoinClause $join): void {
                $join
                    ->on('hb.d_file', '=', 'f_file')
                    ->on('hb.d_gid', '=', 'f_husb')
                    ->where('hb.d_fact', '=', 'BIRT')
                    ->whereIn('hb.d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
                    ->where('hb.d_julianday1', '<>', 0);
            })
            ->join('dates AS wb', static function (JoinClause $join): void {
                $join
                    ->on('wb.d_file', '=', 'f_file')
                    ->on('wb.d_gid', '=', 'f_wife')
                    ->where('wb.d_fact', '=', 'BIRT')
                    ->whereIn('wb.d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
                    ->where('wb.d_julianday1', '<>', 0);
            })
            ->select([
                'hb.d_julianday1 AS hb_jd',
                'wb.d_julianday1 AS wb_jd',
            ])
            ->get();

        $buckets                                                      = [];
        $buckets[$this->signedOverflowLabel(-self::AGE_GAP_LIMIT_HI)] = 0;

        for ($edge = -self::AGE_GAP_LIMIT_HI + self::AGE_GAP_BUCKET; $edge < self::AGE_GAP_LIMIT_HI; $edge += self::AGE_GAP_BUCKET) {
            $buckets[$this->signedBucketLabel($edge)] = 0;
        }

        $buckets[$this->signedOverflowLabel(self::AGE_GAP_LIMIT_HI)] = 0;

        foreach ($rows as $row) {
            $hbJd = is_numeric($row->hb_jd ?? null) ? (int) $row->hb_jd : 0;
            $wbJd = is_numeric($row->wb_jd ?? null) ? (int) $row->wb_jd : 0;

            if ($hbJd <= 0) {
                continue;
            }

            if ($wbJd <= 0) {
                continue;
            }

            $gapYears = intdiv($hbJd - $wbJd, 365);

            if (abs($gapYears) >= self::AGE_GAP_LIMIT_HI) {
                $label = $this->signedOverflowLabel($gapYears >= 0 ? self::AGE_GAP_LIMIT_HI : -self::AGE_GAP_LIMIT_HI);
            } else {
                $edge  = $this->signedBucketLowerEdge($gapYears);
                $label = $this->signedBucketLabel($edge + self::AGE_GAP_BUCKET);
            }

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Weddings grouped by GEDCOM month abbreviation, leaning on
     * core's already-public {@see StatisticsData::countFirstMarriagesByMonth()}.
     * Core hands back the `{JAN: int, FEB: int, …}` map directly,
     * so the repository is just a thin pass-through.
     *
     * @return array<string, int>
     */
    public function weddingsByMonth(): array
    {
        return $this->data->countFirstMarriagesByMonth($this->tree, 0, 0);
    }

    /**
     * Weddings grouped by century, leaning on core's already-public
     * accessor. Output shape is `[centuryLabel => count]`.
     *
     * @return array<string, int>
     */
    public function weddingsByCentury(): array
    {
        $rows   = $this->data->countEventsByCentury('MARR');
        $labels = array_map(strval(...), array_keys($rows));
        $values = array_map(intval(...), array_values($rows));

        return array_combine($labels, $values);
    }

    /**
     * Initialise an integer-keyed bucket map [0, max) plus a "max+"
     * overflow.
     *
     * @return array<string, int>
     */
    private function initBuckets(int $minInclusive, int $maxExclusive, int $width): array
    {
        $buckets = [];

        for ($lower = $minInclusive; $lower < $maxExclusive; $lower += $width) {
            $buckets[$lower . '–' . ($lower + $width - 1)] = 0;
        }

        $buckets[$maxExclusive . '+'] = 0;

        return $buckets;
    }

    /**
     * Resolve a value to the matching bucket label in
     * {@see initBuckets()} layout.
     */
    private function bucketLabel(int $value, int $maxExclusive, int $width): string
    {
        if ($value >= $maxExclusive) {
            return $maxExclusive . '+';
        }

        $lower = intdiv($value, $width) * $width;

        return $lower . '–' . ($lower + $width - 1);
    }

    /**
     * Label for a symmetric signed bucket, e.g. "0–4", "−5 to −1",
     * "5–9".
     */
    private function signedBucketLabel(int $upperExclusive): string
    {
        $lower = $upperExclusive - self::AGE_GAP_BUCKET;

        if ($lower >= 0) {
            return $lower . '–' . ($upperExclusive - 1);
        }

        return $lower . ' to ' . ($upperExclusive - 1);
    }

    /**
     * Label for the overflow buckets at either end of the gap
     * distribution.
     */
    private function signedOverflowLabel(int $edge): string
    {
        return $edge >= 0 ? $edge . '+' : '<' . $edge;
    }

    /**
     * Lower edge of the bucket containing the given signed value.
     */
    private function signedBucketLowerEdge(int $value): int
    {
        if ($value >= 0) {
            return intdiv($value, self::AGE_GAP_BUCKET) * self::AGE_GAP_BUCKET;
        }

        return -((intdiv(-$value - 1, self::AGE_GAP_BUCKET) + 1) * self::AGE_GAP_BUCKET);
    }

    /**
     * Earliest positive Julian-day number from a list of nullable
     * candidates, or null when none are positive.
     *
     * @param list<mixed> $candidates
     */
    private function earliestPositive(array $candidates): ?int
    {
        $earliest = null;

        foreach ($candidates as $candidate) {
            if (!is_numeric($candidate)) {
                continue;
            }

            $value = (int) $candidate;

            if ($value <= 0) {
                continue;
            }

            if ($earliest === null || $value < $earliest) {
                $earliest = $value;
            }
        }

        return $earliest;
    }
}
