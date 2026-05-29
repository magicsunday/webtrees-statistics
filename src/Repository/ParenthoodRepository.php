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
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Enum\AgePairExtremum;
use MagicSunday\Webtrees\Statistic\Enum\Sex;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartPayload;
use MagicSunday\Webtrees\Statistic\Model\LineChart\LineChartSeries;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualAgeRecord;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\IndividualAgeRecordResolver;
use MagicSunday\Webtrees\Statistic\Support\Calc\AgeBuckets;
use MagicSunday\Webtrees\Statistic\Support\Database\DateAggregate;
use MagicSunday\Webtrees\Statistic\Support\Database\DateJoin;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\DecadeName;

use function intdiv;
use function ksort;
use function round;

/**
 * Age-at-first-child distributions for the Family tab. For every family the
 * repository pairs the parent's BIRT julian-day with the earliest dated child's
 * BIRT julian-day, converts the delta to full years, and bucketises into the
 * standard {@see AgeBuckets} 5-year layout — separately for fathers and mothers
 * so the histogram can render side-by-side and the generation-by-sex difference
 * (typically a few years' offset) becomes visible.
 *
 * Families without dated parent or without any dated child are silently
 * excluded — without both anchors there is no age to compute. Implausible
 * values are also dropped: ages below {@see MIN_PLAUSIBLE_AGE} (data-entry
 * error: parent BIRT after child BIRT) and above {@see MAX_PLAUSIBLE_AGE}
 * (records where a stepparent or adoptive parent's BIRT predates the link's
 * intended semantics) would distort the histogram tail.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ParenthoodRepository
{
    /**
     * Below this age the row is almost certainly a data-entry error (BIRT dates
     * swapped, adoptive-parent semantics in a direct CHIL link, etc.) so we
     * drop it rather than skew the histogram's lower tail.
     */
    private const int MIN_PLAUSIBLE_AGE = 12;

    /**
     * Above this age the row almost certainly describes a stepparent / adoptive
     * relationship or a stale BIRT that was never corrected. Anything beyond
     * falls into the overflow bucket regardless of value.
     */
    private const int MAX_PLAUSIBLE_AGE = 65;

    /**
     * Histogram axis: bands of {@see self::BUCKET_WIDTH} years spanning
     * `[BUCKET_MIN, BUCKET_MAX)` plus a `BUCKET_MAX+` overflow band.
     */
    private const int BUCKET_MIN = 10;

    private const int BUCKET_MAX = 60;

    private const int BUCKET_WIDTH = 5;

    /**
     * Per-decade × sex sample floor for {@see ageAtFirstChildMeanByDecade}.
     * Decade cohorts below this size are suppressed independently per sex so a
     * single outlier cannot dominate the line and the resulting trend remains
     * statistically defensible. Set to match {@see
     * LifeSpanRepository::MIN_COHORT_SIZE}; the smaller decade bucket tolerates
     * the same floor because the trend reader compares neighbouring decades and
     * the per-sex independent drop keeps the surviving series readable when a
     * single decade × sex falls just short.
     */
    private const int MIN_DECADE_COHORT_SIZE = 5;

    /**
     * Per-instance cache for `ageAtFirstChildPairs`, keyed by sex. The Overview
     * tab triggers four calls (young/old × M/F) and the Family tab additionally
     * consumes the per-decade aggregate; memoising per sex collapses them into
     * two SELECTs total instead of one per consumer.
     *
     * @var array<string, array<int, array{xref: string, years: int, childBirthYear: int}>>
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
     * Distribution of parent age at the first dated child, bucketed into 5-year
     * bands. Passes 'M' (HUSB / father) or 'F' (WIFE / mother) to switch the
     * side of the family being aggregated.
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
     * Single youngest parent at first child: minimum positive age at first
     * dated child across the tree, restricted to one parent sex. Plausibility
     * band {@see MIN_PLAUSIBLE_AGE} .. {@see MAX_PLAUSIBLE_AGE} is applied via
     * the underlying pair iterator so a 5-year-old "father" cannot win the
     * slot.
     *
     * @param string $sex 'M' for fathers, 'F' for mothers
     */
    public function youngestParentAtFirstChildRecord(string $sex): ?IndividualAgeRecord
    {
        $best = AgePairExtremum::Lowest->pick($this->ageAtFirstChildPairs($sex));

        return IndividualAgeRecordResolver::resolve($this->tree, $best['xref'] ?? null, $best['years'] ?? null);
    }

    /**
     * Single oldest parent at first child — mirror of {@see
     * youngestParentAtFirstChildRecord()}.
     *
     * @param string $sex 'M' for fathers, 'F' for mothers
     */
    public function oldestParentAtFirstChildRecord(string $sex): ?IndividualAgeRecord
    {
        $best = AgePairExtremum::Highest->pick($this->ageAtFirstChildPairs($sex));

        return IndividualAgeRecordResolver::resolve($this->tree, $best['xref'] ?? null, $best['years'] ?? null);
    }

    /**
     * Iterate every parent (one sex) and yield their age at their earliest
     * dated child across all FAMS they appear in plus the child's birth year
     * (the trend X-anchor for the per-decade aggregate). Groups by the parent
     * xref so a man married three times yields one row referencing whichever
     * family produced his first child. Ages outside the plausibility band are
     * dropped at source. `MIN(d_year)` is monotone-equivalent to the year-of
     * `MIN(d_julianday1)` within the same parent's children, so the two MIN
     * aggregates always describe the same birth event.
     *
     * @param string $sex 'M' for fathers, 'F' for mothers
     *
     * @return array<int, array{xref: string, years: int, childBirthYear: int}>
     */
    private function ageAtFirstChildPairs(string $sex): array
    {
        if (isset($this->ageAtFirstChildPairsCache[$sex])) {
            return $this->ageAtFirstChildPairsCache[$sex];
        }

        $parentColumn = Sex::from($sex)->spouseColumn();

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
            // Enforce d_year populated alongside d_julianday1 so the two
            // MIN aggregates below describe the same row. Without this
            // guard a child whose import path wrote a positive julian-day
            // but a zero d_year would let MIN(d_year) collapse to 0 while
            // MIN(d_julianday1) returned a real JD from a different
            // sibling, and the downstream `if ($childYear <= 0)` filter
            // would drop the whole parent row.
            ->where('child_birth.d_year', '<>', 0)
            ->groupBy('fam.' . $parentColumn, 'parent_birth.d_julianday1')
            ->select([
                'fam.' . $parentColumn . ' AS parent_xref',
                'parent_birth.d_julianday1 AS parent_birth_jd',
                DateAggregate::min('child_birth', 'd_julianday1', 'first_child_jd'),
                DateAggregate::min('child_birth', 'd_year', 'first_child_year'),
            ])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $xref      = RowCast::string($row, 'parent_xref');
            $parentJd  = RowCast::int($row, 'parent_birth_jd');
            $childJd   = RowCast::int($row, 'first_child_jd');
            $childYear = RowCast::int($row, 'first_child_year');

            if ($xref === '') {
                continue;
            }

            if ($parentJd <= 0) {
                continue;
            }

            if ($childJd <= $parentJd) {
                continue;
            }

            if ($childYear <= 0) {
                continue;
            }

            $years = intdiv($childJd - $parentJd, 365);

            if ($years < self::MIN_PLAUSIBLE_AGE) {
                continue;
            }

            if ($years > self::MAX_PLAUSIBLE_AGE) {
                continue;
            }

            $out[] = ['xref' => $xref, 'years' => $years, 'childBirthYear' => $childYear];
        }

        $this->ageAtFirstChildPairsCache[$sex] = $out;

        return $out;
    }

    /**
     * Mean parental age at first child grouped by the decade of the child's
     * birth, with one series per parent sex. The X axis is the decade-start
     * year (1850, 1860, …); each series carries the per-decade mean parental
     * age in full years. Decade × sex cohorts below {@see
     * MIN_DECADE_COHORT_SIZE} samples are suppressed independently per sex —
     * empty decades therefore surface as a zero value on the suppressed series
     * only, while the other sex keeps its trend line continuous. Pure aggregate
     * over the same pair iterator the histogram and the Hall-of-Fame records
     * read from, so the new view stays in lockstep with the existing parenthood
     * numbers.
     */
    public function ageAtFirstChildMeanByDecade(): LineChartPayload
    {
        /** @var array<int, array{M: array{sum: int, n: int}, F: array{sum: int, n: int}}> $decades */
        $decades = [];

        foreach (['M', 'F'] as $sex) {
            foreach ($this->ageAtFirstChildPairs($sex) as $pair) {
                $decade = intdiv($pair['childBirthYear'], 10) * 10;
                $decades[$decade] ??= ['M' => ['sum' => 0, 'n' => 0], 'F' => ['sum' => 0, 'n' => 0]];
                $decades[$decade][$sex]['sum'] += $pair['years'];
                ++$decades[$decade][$sex]['n'];
            }
        }

        if ($decades === []) {
            return new LineChartPayload(categories: [], series: []);
        }

        ksort($decades);

        $categories          = [];
        $fatherValues        = [];
        $motherValues        = [];
        $fatherTooltips      = [];
        $motherTooltips      = [];
        $fatherTooltipLabels = [];
        $motherTooltipLabels = [];

        foreach ($decades as $decade => $perSex) {
            // Drop decades where neither sex meets the cohort floor —
            // they carry no statistically defensible mean and would
            // pad the X-axis with empty leading / trailing entries.
            if (
                ($perSex['M']['n'] < self::MIN_DECADE_COHORT_SIZE)
                && ($perSex['F']['n'] < self::MIN_DECADE_COHORT_SIZE)
            ) {
                continue;
            }

            $categories[] = DecadeName::for($decade);
            $longLabel    = DecadeName::longLabel($decade);

            $fatherAverage = ($perSex['M']['n'] >= self::MIN_DECADE_COHORT_SIZE)
                ? round($perSex['M']['sum'] / $perSex['M']['n'], 1)
                : 0;
            $motherAverage = ($perSex['F']['n'] >= self::MIN_DECADE_COHORT_SIZE)
                ? round($perSex['F']['sum'] / $perSex['F']['n'], 1)
                : 0;
            $fatherValues[] = $fatherAverage;
            $motherValues[] = $motherAverage;

            $fatherTooltips[] = ($perSex['M']['n'] >= self::MIN_DECADE_COHORT_SIZE)
                ? I18N::translate(
                    '%1$s years (n = %2$s)',
                    I18N::number($fatherAverage, 1),
                    I18N::number($perSex['M']['n']),
                )
                : I18N::translate('no data (n < %s)', I18N::number(self::MIN_DECADE_COHORT_SIZE));
            $motherTooltips[] = ($perSex['F']['n'] >= self::MIN_DECADE_COHORT_SIZE)
                ? I18N::translate(
                    '%1$s years (n = %2$s)',
                    I18N::number($motherAverage, 1),
                    I18N::number($perSex['F']['n']),
                )
                : I18N::translate('no data (n < %s)', I18N::number(self::MIN_DECADE_COHORT_SIZE));

            $fatherTooltipLabels[] = $longLabel;
            $motherTooltipLabels[] = $longLabel;
        }

        // Every decade was dropped by the cohort floor — return a
        // bare empty payload rather than two name-only series so
        // the view's `EmptyStatePlaceholder` short-circuits instead
        // of rendering an empty legend strip.
        if ($categories === []) {
            return new LineChartPayload(categories: [], series: []);
        }

        return new LineChartPayload(
            categories: $categories,
            series: [
                new LineChartSeries(
                    name: I18N::translate('Fathers'),
                    values: $fatherValues,
                    tooltips: $fatherTooltips,
                    tooltipLabels: $fatherTooltipLabels,
                    class: 'male',
                ),
                new LineChartSeries(
                    name: I18N::translate('Mothers'),
                    values: $motherValues,
                    tooltips: $motherTooltips,
                    tooltipLabels: $motherTooltipLabels,
                    class: 'female',
                ),
            ],
        );
    }
}
