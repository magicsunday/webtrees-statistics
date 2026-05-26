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
use MagicSunday\Webtrees\Statistic\Model\Dto\Record\IndividualAgeRecord;
use MagicSunday\Webtrees\Statistic\Sex;
use MagicSunday\Webtrees\Statistic\Support\Calc\AgeBuckets;
use MagicSunday\Webtrees\Statistic\Support\Calc\AgePairExtremum;
use MagicSunday\Webtrees\Statistic\Support\Calc\IndividualAgeRecordResolver;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function intdiv;

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
final class ParenthoodRepository
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
     * Per-instance cache for `ageAtFirstChildPairs`, keyed by sex.
     * The Overview tab triggers four calls (young/old × M/F);
     * memoising per sex collapses them into two SELECTs instead
     * of four.
     *
     * @var array<string, array<int, array{xref: string, years: int}>>
     */
    private array $ageAtFirstChildPairsCache = [];

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private readonly Tree $tree,
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
        $buckets = AgeBuckets::init(self::BUCKET_MIN, self::BUCKET_MAX, self::BUCKET_WIDTH);

        foreach ($this->ageAtFirstChildPairs($sex) as $pair) {
            $label           = AgeBuckets::label($pair['years'], self::BUCKET_MAX, self::BUCKET_WIDTH);
            $buckets[$label] = ($buckets[$label] ?? 0) + 1;
        }

        return $buckets;
    }

    /**
     * Single youngest parent at first child: minimum positive age
     * at first dated child across the tree, restricted to one
     * parent sex. Plausibility band {@see MIN_PLAUSIBLE_AGE} ..
     * {@see MAX_PLAUSIBLE_AGE} is applied via the underlying pair
     * iterator so a 5-year-old "father" cannot win the slot.
     *
     * @param string $sex 'M' for fathers, 'F' for mothers
     */
    public function youngestParentAtFirstChildRecord(string $sex): ?IndividualAgeRecord
    {
        $best = AgePairExtremum::Lowest->pick($this->ageAtFirstChildPairs($sex));

        return IndividualAgeRecordResolver::resolve($this->tree, $best['xref'] ?? null, $best['years'] ?? null);
    }

    /**
     * Single oldest parent at first child — mirror of
     * {@see youngestParentAtFirstChildRecord()}.
     *
     * @param string $sex 'M' for fathers, 'F' for mothers
     */
    public function oldestParentAtFirstChildRecord(string $sex): ?IndividualAgeRecord
    {
        $best = AgePairExtremum::Highest->pick($this->ageAtFirstChildPairs($sex));

        return IndividualAgeRecordResolver::resolve($this->tree, $best['xref'] ?? null, $best['years'] ?? null);
    }

    /**
     * Iterate every parent (one sex) and yield their age at their
     * earliest dated child across all FAMS they appear in. Groups
     * by the parent xref so a man married three times yields one
     * row referencing whichever family produced his first child.
     * Ages outside the plausibility band are dropped at source.
     *
     * @param string $sex 'M' for fathers, 'F' for mothers
     *
     * @return array<int, array{xref: string, years: int}>
     */
    private function ageAtFirstChildPairs(string $sex): array
    {
        if (isset($this->ageAtFirstChildPairsCache[$sex])) {
            return $this->ageAtFirstChildPairsCache[$sex];
        }

        $parentColumn = Sex::from($sex)->spouseColumn();
        $tablePrefix  = DB::connection()->getTablePrefix();

        $rows = TreeScope::table($this->tree, 'families', 'fam')
            ->join('dates AS parent_birth', static function (JoinClause $join) use ($parentColumn): void {
                DateJoin::on($join, 'parent_birth', 'fam.f_file', 'fam.' . $parentColumn, 'BIRT', DateJoin::JD_GREATER_THAN_ZERO);
            })
            ->join('link AS famc', static function (JoinClause $join): void {
                $join
                    ->on('famc.l_file', '=', 'fam.f_file')
                    ->on('famc.l_to', '=', 'fam.f_id')
                    ->where('famc.l_type', '=', 'FAMC');
            })
            ->join('dates AS child_birth', static function (JoinClause $join): void {
                DateJoin::on($join, 'child_birth', 'famc.l_file', 'famc.l_from', 'BIRT', DateJoin::JD_GREATER_THAN_ZERO);
            })
            ->groupBy('fam.' . $parentColumn, 'parent_birth.d_julianday1')
            ->select([
                'fam.' . $parentColumn . ' AS parent_xref',
                'parent_birth.d_julianday1 AS parent_birth_jd',
                new Expression('MIN(' . $tablePrefix . 'child_birth.d_julianday1) AS first_child_jd'),
            ])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $xref     = RowCast::string($row, 'parent_xref');
            $parentJd = RowCast::int($row, 'parent_birth_jd');
            $childJd  = RowCast::int($row, 'first_child_jd');

            if ($xref === '') {
                continue;
            }

            if ($parentJd <= 0) {
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

            $out[] = ['xref' => $xref, 'years' => $years];
        }

        $this->ageAtFirstChildPairsCache[$sex] = $out;

        return $out;
    }
}
