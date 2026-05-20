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

use function array_combine;
use function array_keys;
use function array_map;
use function array_values;
use function intdiv;
use function is_numeric;
use function ksort;
use function round;

/**
 * Divorce-related aggregations for the Family tab. Built on the
 * same join chain core uses for marriage stats but anchored on
 * `1 DIV` events. `1 DIVF` (Divorce Filed) is intentionally
 * excluded — same anchoring rule the marital classifier uses.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class DivorceRepository
{
    private const int AGE_AT_DIVORCE_BUCKET = 5;

    private const int AGE_AT_DIVORCE_MAX = 80;

    /**
     * @param Tree           $tree The tree the statistics are computed for
     * @param StatisticsData $data Core accessor (countEventsByCentury / countEventsByMonth)
     */
    public function __construct(
        private Tree $tree,
        private StatisticsData $data,
    ) {
    }

    /**
     * Divorces grouped by century — pass-through over core's
     * already-public accessor.
     *
     * @return array<string, int>
     */
    public function divorcesByCentury(): array
    {
        $rows   = $this->data->countEventsByCentury('DIV');
        $labels = array_map(strval(...), array_keys($rows));
        $values = array_map(intval(...), array_values($rows));

        return array_combine($labels, $values);
    }

    /**
     * Divorces grouped by GEDCOM month abbreviation — pass-through
     * over core's already-public accessor.
     *
     * @return array<string, int>
     */
    public function divorcesByMonth(): array
    {
        return $this->data->countEventsByMonth('DIV', 0, 0);
    }

    /**
     * Age-at-divorce histogram for one sex (5-year bands up to 80+).
     *
     * @param string $sex 'M' for husband, 'F' for wife
     *
     * @return array<string, int>
     */
    public function ageAtDivorceDistribution(string $sex): array
    {
        $spouseColumn = $sex === 'M' ? 'f_husb' : 'f_wife';

        $rows = DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->join('dates AS divr', static function (JoinClause $join): void {
                $join
                    ->on('divr.d_file', '=', 'f_file')
                    ->on('divr.d_gid', '=', 'f_id')
                    ->where('divr.d_fact', '=', 'DIV')
                    ->whereIn('divr.d_type', ['@#DGREGORIAN@', '@#DJULIAN@']);
            })
            ->join('dates AS birth', static function (JoinClause $join) use ($spouseColumn): void {
                $join
                    ->on('birth.d_file', '=', 'f_file')
                    ->on('birth.d_gid', '=', $spouseColumn)
                    ->where('birth.d_fact', '=', 'BIRT')
                    ->whereIn('birth.d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
                    ->where('birth.d_julianday1', '<>', 0);
            })
            ->select([
                'divr.d_julianday1 AS div_jd',
                'birth.d_julianday1 AS birth_jd',
            ])
            ->get();

        $buckets = $this->initBuckets(0, self::AGE_AT_DIVORCE_MAX, self::AGE_AT_DIVORCE_BUCKET);

        foreach ($rows as $row) {
            $divJd   = is_numeric($row->div_jd ?? null) ? (int) $row->div_jd : 0;
            $birthJd = is_numeric($row->birth_jd ?? null) ? (int) $row->birth_jd : 0;

            if ($divJd <= 0) {
                continue;
            }

            if ($birthJd <= 0) {
                continue;
            }

            if ($divJd <= $birthJd) {
                continue;
            }

            $years = intdiv($divJd - $birthJd, 365);
            $label = $this->bucketLabel($years);

            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Divorce rate per marriage cohort. Cohort = decade of MARR
     * event; rate = `divorced / total` within that decade. Output
     * is keyed by decade label ("1900s", "1910s", …); the value is
     * a fraction 0.0–1.0 rounded to 4 decimals.
     *
     * Cohorts with fewer than 3 marriages are filtered out — at
     * that size the rate is dominated by noise.
     *
     * @return array<string, float>
     */
    public function divorceRateByMarriageCohort(): array
    {
        $rows = DB::table('families')
            ->where('f_file', '=', $this->tree->id())
            ->join('dates AS marr', static function (JoinClause $join): void {
                $join
                    ->on('marr.d_file', '=', 'f_file')
                    ->on('marr.d_gid', '=', 'f_id')
                    ->where('marr.d_fact', '=', 'MARR')
                    ->whereIn('marr.d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
                    ->where('marr.d_year', '<>', 0);
            })
            ->leftJoin('dates AS divr', static function (JoinClause $join): void {
                $join
                    ->on('divr.d_file', '=', 'f_file')
                    ->on('divr.d_gid', '=', 'f_id')
                    ->where('divr.d_fact', '=', 'DIV');
            })
            ->select(['marr.d_year AS marr_year', 'divr.d_year AS div_year'])
            ->get();

        $perCohort = [];

        foreach ($rows as $row) {
            $marrYear = is_numeric($row->marr_year ?? null) ? (int) $row->marr_year : 0;

            if ($marrYear === 0) {
                continue;
            }

            $cohort = (intdiv($marrYear, 10) * 10) . 's';

            if (!isset($perCohort[$cohort])) {
                $perCohort[$cohort] = ['total' => 0, 'divorced' => 0];
            }

            ++$perCohort[$cohort]['total'];

            if (($row->div_year ?? null) !== null) {
                ++$perCohort[$cohort]['divorced'];
            }
        }

        ksort($perCohort);

        $rates = [];

        foreach ($perCohort as $cohort => $tally) {
            if ($tally['total'] < 3) {
                continue;
            }

            $rates[$cohort] = round($tally['divorced'] / $tally['total'], 4);
        }

        return $rates;
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
     * Resolve an integer value to the matching bucket label.
     */
    private function bucketLabel(int $value): string
    {
        if ($value >= self::AGE_AT_DIVORCE_MAX) {
            return self::AGE_AT_DIVORCE_MAX . '+';
        }

        $lower = intdiv($value, self::AGE_AT_DIVORCE_BUCKET) * self::AGE_AT_DIVORCE_BUCKET;

        return $lower . '–' . ($lower + self::AGE_AT_DIVORCE_BUCKET - 1);
    }
}
