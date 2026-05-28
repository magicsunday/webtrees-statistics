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
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\StatisticsData;
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;

use function array_intersect;
use function array_keys;
use function array_map;
use function ksort;
use function mb_strtolower;
use function preg_split;
use function round;
use function trim;

use const PHP_INT_MAX;
use const PREG_SPLIT_NO_EMPTY;

/**
 * Total counts for surnames and given names that stay in lockstep with
 * webtrees core's Top-N aggregation. The given-name path still defers
 * to {@see StatisticsData::commonGivenNames()} because the tokenisation
 * (multi-name splits, initial filter) lives in core. The surname path
 * resolves to a single COUNT(DISTINCT) query — calling
 * {@see StatisticsData::commonSurnames()} with PHP_INT_MAX would fire
 * one extra COUNT per distinct surname, turning a single count into an
 * N+1 query storm.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class NameRepository
{
    /**
     * Per-century sample floor for {@see fatherSonNamePassdownByCentury}.
     * Centuries with fewer father-son pairs are suppressed because a
     * single match would swing the rate by more than 10 percentage
     * points and read as noise rather than signal.
     */
    private const int MIN_COHORT_SIZE = 10;

    /**
     * @param Tree           $tree The tree whose names to aggregate
     * @param StatisticsData $data Core data accessor used for the underlying name aggregation
     */
    public function __construct(
        private Tree $tree,
        private StatisticsData $data,
    ) {
    }

    /**
     * Number of distinct surnames in the tree, mirroring the filter
     * stack of {@see StatisticsData::commonSurnames()}: exclude
     * `_MARNM` entries plus empty / `NOMEN_NESCIO` values, then
     * count distinct surname tokens whose occurrence ≥ `$threshold`.
     *
     * Splits into two query shapes so the result stays valid under
     * MySQL's `ONLY_FULL_GROUP_BY` mode (default on MySQL 5.7+ and
     * MariaDB 10.5+), where `SELECT *` combined with `GROUP BY n_surn`
     * is rejected because the non-aggregated columns are not
     * functionally dependent on the grouping key.
     *
     * @param int $threshold Lower bound on the occurrences a surname must have
     */
    public function countDistinctSurnames(int $threshold = 1): int
    {
        $query = DB::table('name')
            ->where('n_file', '=', $this->tree->id())
            ->where('n_type', '<>', '_MARNM')
            ->whereNotIn('n_surn', ['', Individual::NOMEN_NESCIO]);

        // Fast path — no occurrence filter, so a plain DISTINCT count
        // is enough and avoids the per-surname GROUP BY scan.
        if ($threshold <= 1) {
            return $query->distinct()->count('n_surn');
        }

        // Threshold > 1: keep only surnames whose occurrence reaches
        // the floor, then count the surviving groups. Selecting just
        // the grouping column keeps the statement ONLY_FULL_GROUP_BY
        // compliant on every supported engine.
        return $query
            ->select(['n_surn'])
            ->groupBy('n_surn')
            ->having(new Expression('COUNT(n_surn)'), '>=', $threshold)
            ->get()
            ->count();
    }

    /**
     * Number of distinct given names for a sex, computed from the same
     * aggregation that feeds the Top-N given-name list.
     *
     * @param string $sex       GEDCOM sex token: 'M', 'F', 'X' or 'ALL'
     * @param int    $threshold Lower bound on the occurrences a given name must have
     *
     * @return int
     */
    public function countDistinctGivenNames(string $sex, int $threshold = 1): int
    {
        return $this->data->commonGivenNames($sex, $threshold, PHP_INT_MAX)->count();
    }

    /**
     * Same-sex given-name passdown rate per child's birth century.
     * Builds two series on the same X axis: father → son and
     * mother → daughter. For every same-sex parent-child pair where
     * both individuals carry an indexed primary NAME record and the
     * child carries a dated BIRT, the widget collects every
     * given-name token from each `n_givn` column (case-folded) and
     * counts the pair as a match when at least one token appears on
     * both sides. Order and position do not matter — a father named
     * "Johann Friedrich" matches a son named "Wilhelm Friedrich"
     * because "Friedrich" appears in both names. That mirrors the
     * historical naming-tradition pattern in German-speaking
     * regions, where the leading token was often a fixed baptismal
     * name ("Johann", "Maria") while the actual everyday name lived
     * in a later token; a strict first-token comparison would
     * systematically miss the passdown signal it tries to measure.
     *
     * Per-century cohorts with fewer than {@see MIN_COHORT_SIZE}
     * parent-child pairs are suppressed independently per series
     * (value 0 + "no data" tooltip). A century survives on the X
     * axis as long as at least one of the two series passes the
     * floor, so the union of the two cohorts defines the visible
     * span and one sparse series cannot hide the other.
     *
     * Two SQL passes (one per parent / child sex pair). The walk is
     * symmetric between the two: father / son uses HUSB + SEX=M,
     * mother / daughter uses WIFE + SEX=F. All token work runs in
     * PHP so collation quirks in the different storage engines do
     * not skew the result.
     */
    public function sameSexNamePassdownByCentury(): LineChartPayload
    {
        $fatherSon      = $this->passdownPairsByCentury('f_husb', 'M');
        $motherDaughter = $this->passdownPairsByCentury('f_wife', 'F');

        $allCenturies = $fatherSon + $motherDaughter;

        if ($allCenturies === []) {
            return new LineChartPayload(categories: [], series: []);
        }

        ksort($allCenturies);

        $categories            = [];
        $sonValues             = [];
        $sonTooltips           = [];
        $sonTooltipLabels      = [];
        $daughterValues        = [];
        $daughterTooltips      = [];
        $daughterTooltipLabels = [];

        foreach (array_keys($allCenturies) as $century) {
            $label     = CenturyName::for($century);
            $longLabel = CenturyName::longLabel($label);

            $categories[] = $label;

            [$sonValues[], $sonTooltips[]]           = $this->seriesRow($fatherSon[$century] ?? null, 'son');
            $sonTooltipLabels[]                      = $longLabel;
            [$daughterValues[], $daughterTooltips[]] = $this->seriesRow($motherDaughter[$century] ?? null, 'daughter');
            $daughterTooltipLabels[]                 = $longLabel;
        }

        return new LineChartPayload(
            categories: $categories,
            series: [
                new LineChartSeries(
                    name: I18N::translate('Father → son'),
                    values: $sonValues,
                    tooltips: $sonTooltips,
                    tooltipLabels: $sonTooltipLabels,
                    class: 'male',
                ),
                new LineChartSeries(
                    name: I18N::translate('Mother → daughter'),
                    values: $daughterValues,
                    tooltips: $daughterTooltips,
                    tooltipLabels: $daughterTooltipLabels,
                    class: 'female',
                ),
            ],
        );
    }

    /**
     * Run the per-century passdown aggregation for a single
     * parent / child sex pair. `$parentColumn` is the `families`
     * column holding the parent xref (`f_husb` for fathers,
     * `f_wife` for mothers); `$childSex` is the GEDCOM SEX token
     * the CHIL is filtered to (`M` for sons, `F` for daughters).
     *
     * @return array<int, array{matches: int, total: int}>
     */
    private function passdownPairsByCentury(string $parentColumn, string $childSex): array
    {
        $treeId = $this->tree->id();

        $rows = TreeScope::table($this->tree, 'families', 'fam')
            ->join('link AS famc', static function (JoinClause $join): void {
                $join
                    ->on('famc.l_file', '=', 'fam.f_file')
                    ->on('famc.l_to', '=', 'fam.f_id')
                    ->where('famc.l_type', '=', 'FAMC');
            })
            ->join('individuals AS child', static function (JoinClause $join) use ($childSex): void {
                $join
                    ->on('child.i_file', '=', 'famc.l_file')
                    ->on('child.i_id', '=', 'famc.l_from')
                    ->where('child.i_sex', '=', $childSex);
            })
            ->join('dates AS child_birth', static function (JoinClause $join): void {
                DateJoin::on($join, 'child_birth', 'famc.l_file', 'famc.l_from', 'BIRT', DateJoin::JD_GREATER_THAN_ZERO);
            })
            ->join('name AS parent_name', static function (JoinClause $join) use ($treeId, $parentColumn): void {
                $join
                    ->on('parent_name.n_file', '=', 'fam.f_file')
                    ->on('parent_name.n_id', '=', 'fam.' . $parentColumn)
                    ->where('parent_name.n_type', '=', 'NAME')
                    ->where('parent_name.n_file', '=', $treeId);
            })
            ->join('name AS child_name', static function (JoinClause $join) use ($treeId): void {
                $join
                    ->on('child_name.n_file', '=', 'famc.l_file')
                    ->on('child_name.n_id', '=', 'famc.l_from')
                    ->where('child_name.n_type', '=', 'NAME')
                    ->where('child_name.n_file', '=', $treeId);
            })
            ->whereNotNull('fam.' . $parentColumn)
            ->where('fam.' . $parentColumn, '<>', '')
            ->where('child_birth.d_year', '<>', 0)
            ->select([
                'child_birth.d_year AS birth_year',
                'parent_name.n_givn AS parent_givn',
                'child_name.n_givn AS child_givn',
            ])
            ->get();

        /** @var array<int, array{matches: int, total: int}> $byCentury */
        $byCentury = [];

        foreach ($rows as $row) {
            $year = RowCast::int($row, 'birth_year');

            if ($year <= 0) {
                continue;
            }

            $parentTokens = $this->givenNameTokens(RowCast::string($row, 'parent_givn'));
            $childTokens  = $this->givenNameTokens(RowCast::string($row, 'child_givn'));

            if ($parentTokens === []) {
                continue;
            }

            if ($childTokens === []) {
                continue;
            }

            $century = CenturyName::fromYear($year);
            $byCentury[$century] ??= ['matches' => 0, 'total' => 0];
            ++$byCentury[$century]['total'];

            if (array_intersect($parentTokens, $childTokens) !== []) {
                ++$byCentury[$century]['matches'];
            }
        }

        return $byCentury;
    }

    /**
     * Render one century's `{matches, total}` cell into the
     * `(value, tooltip)` pair the LineChart series consumes. A
     * null counts argument means the century did not collect any
     * pair for this sex pairing, which also produces the "no
     * data" placeholder so the other series can still own the
     * X-axis slot.
     *
     * @param array{matches: int, total: int}|null $counts
     * @param string                               $kind   'son' or 'daughter' — picks the tooltip wording
     *
     * @return array{0: int|float, 1: string}
     */
    private function seriesRow(?array $counts, string $kind): array
    {
        if ($counts === null) {
            return [0, I18N::translate('no data (n < %s)', I18N::number(self::MIN_COHORT_SIZE))];
        }

        if ($counts['total'] < self::MIN_COHORT_SIZE) {
            return [0, I18N::translate('no data (n < %s)', I18N::number(self::MIN_COHORT_SIZE))];
        }

        $percentage = round(($counts['matches'] / $counts['total']) * 100, 1);

        $tooltip = ($kind === 'son')
            ? I18N::translate(
                '%1$s%% — %2$s of %3$s sons share at least one given name with the father',
                I18N::number($percentage, 1),
                I18N::number($counts['matches']),
                I18N::number($counts['total']),
            )
            : I18N::translate(
                '%1$s%% — %2$s of %3$s daughters share at least one given name with the mother',
                I18N::number($percentage, 1),
                I18N::number($counts['matches']),
                I18N::number($counts['total']),
            );

        return [$percentage, $tooltip];
    }

    /**
     * Split a `n_givn` column into the case-folded set of given-name
     * tokens, dropping empty pieces. Used to detect set-overlap
     * between a father's and a son's given names regardless of
     * order or position. Slashes and other GEDCOM markers do not
     * appear in `n_givn` (those live on `n_surn` / `n_full`), so a
     * simple whitespace split is sufficient.
     *
     * @return list<string>
     */
    private function givenNameTokens(string $givn): array
    {
        $trimmed = trim($givn);

        if ($trimmed === '') {
            return [];
        }

        $tokens = preg_split('/\s+/', $trimmed, -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        return array_map(mb_strtolower(...), $tokens);
    }
}
