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
use MagicSunday\Webtrees\Statistic\Support\Calc\CalendarSpan;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Database\ChildLinkJoin;
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
     * family produced his first child; a ranged parent BIRT — two `dates`
     * rows — is collapsed onto its lower-bound julian day in PHP so the parent
     * surfaces once rather than once per bound. Ages outside the plausibility
     * band are dropped at source. The earliest child is resolved row-coherently
     * (its `d_type`, `d_year` and `d_julianday1` taken together from the
     * minimum-julian-day row), so a parent whose children are dated in different
     * calendars still converts the bucket year from the correct child.
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
                ChildLinkJoin::famc($join);
            })
            ->join('dates AS child_birth', static function (JoinClause $join): void {
                DateJoin::on($join, 'child_birth', 'famc.l_file', 'famc.l_from', 'BIRT', DateJoin::JD_GREATER_THAN_ZERO);
            })
            // Drop year-less children at source; the PHP collapse below trusts a
            // non-zero d_year on every surviving row.
            ->where('child_birth.d_year', '<>', 0)
            ->select([
                'fam.' . $parentColumn . ' AS parent_xref',
                'parent_birth.d_julianday1 AS parent_birth_jd',
                'child_birth.d_julianday1 AS child_jd',
                'child_birth.d_type AS child_type',
                'child_birth.d_year AS child_year',
            ])
            ->get();

        // Collapse to one row per parent in PHP rather than via independent SQL
        // MIN()s. The earliest child must contribute its OWN d_type + d_year +
        // d_julianday1 COHERENTLY: column-wise minima could draw the calendar
        // from one child and the year from a different one when a parent's
        // children are dated in different calendars, mis-converting the bucket
        // ({@see GregorianDate} picks its branch from d_type but returns the
        // native d_year for Gregorian/Julian).
        /** @var array<string, array{xref: string, parentJd: int, childJd: int, childType: string, childYear: int}> $earliest */
        $earliest = [];

        foreach ($rows as $row) {
            $xref     = RowCast::string($row, 'parent_xref');
            $parentJd = RowCast::int($row, 'parent_birth_jd');
            $childJd  = RowCast::int($row, 'child_jd');

            if ($xref === '') {
                continue;
            }

            if ($parentJd <= 0) {
                continue;
            }

            if ($childJd <= 0) {
                continue;
            }

            if (!isset($earliest[$xref])) {
                $earliest[$xref] = [
                    'xref'      => $xref,
                    'parentJd'  => $parentJd,
                    'childJd'   => $childJd,
                    'childType' => RowCast::string($row, 'child_type'),
                    'childYear' => RowCast::int($row, 'child_year'),
                ];

                continue;
            }

            if ($parentJd < $earliest[$xref]['parentJd']) {
                $earliest[$xref]['parentJd'] = $parentJd;
            }

            // Keep the whole earliest-child row together (type + year + jd).
            if ($childJd < $earliest[$xref]['childJd']) {
                $earliest[$xref]['childJd']   = $childJd;
                $earliest[$xref]['childType'] = RowCast::string($row, 'child_type');
                $earliest[$xref]['childYear'] = RowCast::int($row, 'child_year');
            }
        }

        $out = [];

        foreach ($earliest as $pair) {
            $parentJd = $pair['parentJd'];
            $childJd  = $pair['childJd'];

            if ($childJd <= $parentJd) {
                continue;
            }

            $years = CalendarSpan::wholeYears($parentJd, $childJd);

            if ($years < self::MIN_PLAUSIBLE_AGE) {
                continue;
            }

            if ($years > self::MAX_PLAUSIBLE_AGE) {
                continue;
            }

            // The earliest child's bucket year, from the one coherent row above:
            // native d_year for Gregorian/Julian, the child's julian day
            // converted otherwise. The SQL `d_year <> 0` already dropped the
            // unparseable year 0; a BCE year stays negative for DecadeName.
            $childYear = GregorianDate::year($pair['childType'], $pair['childYear'], $childJd);

            if ($childYear === 0) {
                continue;
            }

            $out[] = ['xref' => $pair['xref'], 'years' => $years, 'childBirthYear' => $childYear];
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
     * the suppressed series carries a `null` for that decade (rendered as a gap
     * by the line widget, never a misleading age 0), while the other sex keeps
     * its trend line continuous. Pure aggregate
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

            // Value + tooltip share the per-sex cohort-floor decision: a sex
            // that meets the floor carries its rounded mean, a sex below it
            // carries a null value (a gap, never age 0) and a "no data" tooltip.
            if ($perSex['M']['n'] >= self::MIN_DECADE_COHORT_SIZE) {
                $fatherAverage    = round($perSex['M']['sum'] / $perSex['M']['n'], 1);
                $fatherValues[]   = $fatherAverage;
                $fatherTooltips[] = I18N::translate(
                    '%1$s years (n = %2$s)',
                    I18N::number($fatherAverage, 1),
                    I18N::number($perSex['M']['n']),
                );
            } else {
                $fatherValues[]   = null;
                $fatherTooltips[] = I18N::translate('no data (n < %s)', I18N::number(self::MIN_DECADE_COHORT_SIZE));
            }

            if ($perSex['F']['n'] >= self::MIN_DECADE_COHORT_SIZE) {
                $motherAverage    = round($perSex['F']['sum'] / $perSex['F']['n'], 1);
                $motherValues[]   = $motherAverage;
                $motherTooltips[] = I18N::translate(
                    '%1$s years (n = %2$s)',
                    I18N::number($motherAverage, 1),
                    I18N::number($perSex['F']['n']),
                );
            } else {
                $motherValues[]   = null;
                $motherTooltips[] = I18N::translate('no data (n < %s)', I18N::number(self::MIN_DECADE_COHORT_SIZE));
            }

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
