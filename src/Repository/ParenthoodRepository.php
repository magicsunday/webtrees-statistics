<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Support\AgeBuckets;

use function intdiv;
use function is_numeric;

/**
 * Age-at-first-child distributions for the Family tab. For every
 * family the repository pairs the parent's BIRT julian-day with the
 * earliest dated child's BIRT julian-day, converts the delta to
 * full years, and bucketises into the standard
 * {@see AgeBuckets} 5-year layout — separately for fathers and
 * mothers so the histogram can render side-by-side and the
 * generation-by-sex difference (typically a few years' offset)
 * becomes visible.
 *
 * Families without dated parent or without any dated child are
 * silently excluded — without both anchors there is no age to
 * compute. Implausible values are also dropped: ages below
 * {@see MIN_PLAUSIBLE_AGE} (data-entry error: parent BIRT after
 * child BIRT) and above {@see MAX_PLAUSIBLE_AGE} (records where a
 * stepparent or adoptive parent's BIRT predates the link's
 * intended semantics) would distort the histogram tail.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ParenthoodRepository
{
    /**
     * Below this age the row is almost certainly a data-entry
     * error (BIRT dates swapped, adoptive-parent semantics in a
     * direct CHIL link, etc.) so we drop it rather than skew the
     * histogram's lower tail.
     */
    private const int MIN_PLAUSIBLE_AGE = 12;

    /**
     * Above this age the row almost certainly describes a
     * stepparent / adoptive relationship or a stale BIRT that was
     * never corrected. Anything beyond falls into the overflow
     * bucket regardless of value.
     */
    private const int MAX_PLAUSIBLE_AGE = 65;

    /**
     * Histogram axis: bands of {@see self::BUCKET_WIDTH} years
     * spanning `[BUCKET_MIN, BUCKET_MAX)` plus a `BUCKET_MAX+`
     * overflow band.
     */
    private const int BUCKET_MIN = 10;

    private const int BUCKET_MAX = 60;

    private const int BUCKET_WIDTH = 5;

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Distribution of parent age at the first dated child, bucketed
     * into 5-year bands. Passes 'M' (HUSB / father) or 'F' (WIFE /
     * mother) to switch the side of the family being aggregated.
     *
     * @param string $sex 'M' for fathers, 'F' for mothers
     *
     * @return array<string, int>
     */
    public function ageAtFirstChildDistribution(string $sex): array
    {
        $parentColumn = ($sex === 'F') ? 'f_wife' : 'f_husb';

        $rows = DB::table('families AS fam')
            ->where('fam.f_file', '=', $this->tree->id())
            ->join('dates AS parent_birth', static function (JoinClause $join) use ($parentColumn): void {
                $join
                    ->on('parent_birth.d_file', '=', 'fam.f_file')
                    ->on('parent_birth.d_gid', '=', 'fam.' . $parentColumn)
                    ->where('parent_birth.d_fact', '=', 'BIRT')
                    ->whereIn('parent_birth.d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
                    ->where('parent_birth.d_julianday1', '>', 0);
            })
            ->join('link AS famc', static function (JoinClause $join): void {
                $join
                    ->on('famc.l_file', '=', 'fam.f_file')
                    ->on('famc.l_to', '=', 'fam.f_id')
                    ->where('famc.l_type', '=', 'FAMC');
            })
            ->join('dates AS child_birth', static function (JoinClause $join): void {
                $join
                    ->on('child_birth.d_file', '=', 'famc.l_file')
                    ->on('child_birth.d_gid', '=', 'famc.l_from')
                    ->where('child_birth.d_fact', '=', 'BIRT')
                    ->whereIn('child_birth.d_type', ['@#DGREGORIAN@', '@#DJULIAN@'])
                    ->where('child_birth.d_julianday1', '>', 0);
            })
            ->groupBy('fam.f_id', 'parent_birth.d_julianday1')
            ->select([
                'parent_birth.d_julianday1 AS parent_birth_jd',
                new Expression('MIN(' . DB::connection()->getTablePrefix() . 'child_birth.d_julianday1) AS first_child_jd'),
            ])
            ->get();

        $buckets = AgeBuckets::init(self::BUCKET_MIN, self::BUCKET_MAX, self::BUCKET_WIDTH);

        foreach ($rows as $row) {
            $parentJd = is_numeric($row->parent_birth_jd ?? null) ? (int) $row->parent_birth_jd : 0;
            $childJd  = is_numeric($row->first_child_jd ?? null) ? (int) $row->first_child_jd : 0;

            if ($parentJd <= 0) {
                continue;
            }

            if ($childJd <= 0) {
                continue;
            }

            if ($childJd <= $parentJd) {
                continue;
            }

            $years = intdiv($childJd - $parentJd, 365);

            if ($years < self::MIN_PLAUSIBLE_AGE) {
                continue;
            }

            if ($years > self::MAX_PLAUSIBLE_AGE) {
                continue;
            }

            $label           = AgeBuckets::label($years, self::BUCKET_MAX, self::BUCKET_WIDTH);
            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }
}
