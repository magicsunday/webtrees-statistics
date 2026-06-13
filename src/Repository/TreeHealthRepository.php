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
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Model\Metric\RateCount;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;

use function array_sum;
use function count;
use function ksort;

/**
 * Tree-wide data-quality metrics: source-citation coverage, missing-event gap
 * rates, and the average parent-to-child birth-year delta. Counters lean on the
 * shared {@see GedcomScanner} so PHP-side scans and SQL-side NOT LIKE filters
 * stay in lockstep with the same anchoring rules.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class TreeHealthRepository
{
    /**
     * Minimum per-century sample below which the sourced percentage is dropped
     * from the breakdown. Five recorded births is the smallest cohort at which
     * a single sourced ancestor doesn't skew the bar by ≥ 20 percentage points.
     */
    private const int MIN_CENTURY_SAMPLE = 5;

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Fraction of individuals with at least one SOUR citation, expressed as
     * `{value, total}` so consumers can derive both the percentage and the
     * absolute counts from the same DTO.
     */
    public function sourceCitationCoverage(): RateCount
    {
        $total = $this->countIndividuals();

        if ($total === 0) {
            return new RateCount(value: 0, total: 0);
        }

        $withSources = TreeScope::table($this->tree, 'individuals')
            ->whereExists(static function (Builder $query): void {
                $query
                    ->select(new Expression('1'))
                    ->from('link')
                    ->whereColumn('link.l_file', 'individuals.i_file')
                    ->whereColumn('link.l_from', 'individuals.i_id')
                    ->where('link.l_type', '=', 'SOUR');
            })
            ->count('i_id');

        return new RateCount(value: $withSources, total: $total);
    }

    /**
     * Source-citation coverage broken down by birth century. Surfaces which
     * historical eras carry their share of source-backed documentation and
     * which rely on family lore. Per-century companion to {@see
     * sourceCitationCoverage()}.
     *
     * Two queries feed the breakdown: one collects the earliest gregorian /
     * julian birth year per individual ({@see MIN()} + `GROUP BY d_gid` so
     * multi-row pairs persisted by webtrees' minimum/maximum date storage do
     * not double-count), the other collects the set of individual ids that have
     * at least one SOUR citation. The view-side intersection happens in PHP
     * rather than as an EXISTS subquery so the planner can hit each table's
     * indices independently. The SOUR-link query joins to `individuals` so
     * polymorphic `l_from` values pointing at families / media / notes are
     * filtered out — the breakdown is about individuals, not
     * records-with-citations.
     *
     * BCE births bucket alongside CE ones: {@see CenturyName::fromYear()} folds
     * negative years toward negative infinity, so an ancient cohort lands in a
     * negative century the view layer labels as "%s BCE" rather than merging
     * into the 1st-century-CE bar. Only the degenerate `d_year = 0` (an
     * unparseable year) is excluded.
     *
     * Centuries with fewer than {@see self::MIN_CENTURY_SAMPLE} dated births
     * are dropped: a single sourced ancestor would otherwise pin the bar to 0 %
     * or 100 % on a cohort too small to read as data quality.
     *
     * @return list<array{century: int, total: int, sourced: int, percentage: float}>
     */
    public function sourceCitationCoverageByCentury(): array
    {
        $birthRows = TreeScope::table($this->tree, 'dates')
            ->where('d_fact', '=', 'BIRT')
            ->where('d_year', '<>', 0)
            ->where('d_julianday1', '<>', 0)
            ->select([
                'd_gid',
                new Expression('MIN(d_type) AS d_type'),
                new Expression('MIN(d_year) AS year'),
                new Expression('MIN(d_julianday1) AS jd'),
            ])
            ->groupBy('d_gid')
            ->get();

        // SOUR links anchored at individuals only — join filters out
        // l_from values that reference families, media, notes, or
        // repositories so the breakdown stays in lockstep with
        // sourceCitationCoverage()'s individuals-only definition.
        $sourceLinks = TreeScope::table($this->tree, 'individuals')
            ->join('link', static function (JoinClause $join): void {
                $join
                    ->on('link.l_file', '=', 'individuals.i_file')
                    ->on('link.l_from', '=', 'individuals.i_id')
                    ->where('link.l_type', '=', 'SOUR');
            })
            ->select(['individuals.i_id AS individual'])
            ->distinct()
            ->get();

        $sourcedIndividualSet = [];

        foreach ($sourceLinks as $linkRow) {
            $individualId                        = RowCast::string($linkRow, 'individual');
            $sourcedIndividualSet[$individualId] = true;
        }

        $perCentury = [];

        foreach ($birthRows as $birthRow) {
            // The century key is the GREGORIAN birth year: native d_year for
            // Gregorian/Julian, the lower-bound julian day converted otherwise.
            $birthYear = GregorianDate::year(
                RowCast::string($birthRow, 'd_type'),
                RowCast::int($birthRow, 'year'),
                RowCast::int($birthRow, 'jd'),
            );

            $individualId = RowCast::string($birthRow, 'd_gid');
            $century      = CenturyName::fromYear($birthYear);

            if (!isset($perCentury[$century])) {
                $perCentury[$century] = ['total' => 0, 'sourced' => 0];
            }

            ++$perCentury[$century]['total'];

            if (isset($sourcedIndividualSet[$individualId])) {
                ++$perCentury[$century]['sourced'];
            }
        }

        ksort($perCentury);
        $out = [];

        foreach ($perCentury as $century => $counts) {
            if ($counts['total'] < self::MIN_CENTURY_SAMPLE) {
                continue;
            }

            $out[] = [
                'century'    => $century,
                'total'      => $counts['total'],
                'sourced'    => $counts['sourced'],
                'percentage' => ($counts['sourced'] / $counts['total']) * 100,
            ];
        }

        return $out;
    }

    /**
     * Per-event missing-data rates for BIRT, DEAT, and MARR. Each event yields
     * two ProgressList rows — one for the event itself, one for its `2 PLAC`
     * sub-line. Event-presence is computed in SQL via anchored NOT LIKE
     * filters; PLAC-within-event requires PHP-side scanning so the place check
     * is scoped to the right event block.
     *
     * Marriage gaps use a different denominator: only individuals who are
     * actually spouses in at least one family contribute. Counting every minor
     * and never-married person as "missing MARR" would dilute the data-quality
     * signal we care about.
     *
     * @return array<string, array{event: string, kind: string, value: int, total: int}>
     */
    public function missingEventGaps(): array
    {
        // Six per-event/per-place `NOT LIKE` counts used to each
        // trigger a full-table scan against the `individuals` table.
        // Pull every i_gedcom blob ONCE and aggregate the six
        // BIRT/DEAT counts in PHP, then do the same for MARR via
        // families+link in a single pluck.
        //
        // Aggregated counts on the individual side:
        //   birthMissing   : no `\n1 BIRT`
        //   birthNoPlace   : BIRT present but no `\n2 PLAC` under it
        //   deathMissing   : no `\n1 DEAT`
        //   deathNoPlace   : DEAT present but no `\n2 PLAC` under it
        // Aggregated counts on the spouse side (per individual):
        //   spouseTotal              : has at least one FAMS link
        //   spousesMissingMarrEvent  : every FAMS family lacks MARR
        //   spousesMissingMarrPlace  : every MARR-bearing family lacks `\n2 PLAC`
        $birthMissing = 0;
        $birthNoPlace = 0;
        $deathMissing = 0;
        $deathNoPlace = 0;
        $total        = 0;

        $individualBlobs = TreeScope::table($this->tree, 'individuals')
            ->select(['i_id', 'i_gedcom'])
            ->get();

        foreach ($individualBlobs as $row) {
            ++$total;
            $blob = RowCast::string($row, 'i_gedcom');

            $hasBirth = GedcomScanner::hasTagAnchored($blob, 'BIRT');

            if (!$hasBirth) {
                ++$birthMissing;
            } elseif (!GedcomScanner::hasEventPlace($blob, 'BIRT')) {
                ++$birthNoPlace;
            }

            $hasDeath = GedcomScanner::hasTagAnchored($blob, 'DEAT');

            if (!$hasDeath) {
                ++$deathMissing;
            } elseif (!GedcomScanner::hasEventPlace($blob, 'DEAT')) {
                ++$deathNoPlace;
            }
        }

        // Family side — pluck spouse links + family gedcoms once,
        // then walk the FAMS chain per individual in PHP.
        $famsLinks = DB::table('link')
            ->where('l_file', '=', $this->tree->id())
            ->where('l_type', '=', 'FAMS')
            ->select(['l_from AS individual', 'l_to AS family'])
            ->get();

        $familyGedcoms = TreeScope::table($this->tree, 'families')
            ->select(['f_id', 'f_gedcom'])
            ->get()
            ->keyBy('f_id');

        // individual → list of family-ids
        $spouseFamilies = [];

        foreach ($famsLinks as $link) {
            $individualId = RowCast::string($link, 'individual');
            $familyId     = RowCast::string($link, 'family');

            if ($individualId === '') {
                continue;
            }

            if ($familyId === '') {
                continue;
            }

            $spouseFamilies[$individualId][] = $familyId;
        }

        $spouseTotal             = count($spouseFamilies);
        $spousesMissingMarrEvent = 0;
        $spousesMissingMarrPlace = 0;

        foreach ($spouseFamilies as $familyIds) {
            $anyHasMarr      = false;
            $anyMarrHasPlace = false;

            foreach ($familyIds as $familyId) {
                $family = $familyGedcoms[$familyId] ?? null;

                if ($family === null) {
                    continue;
                }

                $famGedcom = RowCast::string($family, 'f_gedcom');

                if (GedcomScanner::hasTagAnchored($famGedcom, 'MARR')) {
                    $anyHasMarr = true;

                    if (GedcomScanner::hasEventPlace($famGedcom, 'MARR')) {
                        $anyMarrHasPlace = true;
                    }
                }
            }

            if (!$anyHasMarr) {
                ++$spousesMissingMarrEvent;

                // "MARR place" is only meaningful when MARR exists.
                // Spouses without MARR at all aren't counted as
                // "missing place" — the event-missing row already
                // covers them.
                continue;
            }

            if (!$anyMarrHasPlace) {
                ++$spousesMissingMarrPlace;
            }
        }

        return [
            'BIRT_event' => ['event' => 'BIRT', 'kind' => 'event', 'value' => $birthMissing, 'total' => $total],
            'BIRT_place' => ['event' => 'BIRT', 'kind' => 'place', 'value' => $birthMissing + $birthNoPlace, 'total' => $total],
            'MARR_event' => ['event' => 'MARR', 'kind' => 'event', 'value' => $spousesMissingMarrEvent, 'total' => $spouseTotal],
            'MARR_place' => ['event' => 'MARR', 'kind' => 'place', 'value' => $spousesMissingMarrPlace, 'total' => $spouseTotal],
            'DEAT_event' => ['event' => 'DEAT', 'kind' => 'event', 'value' => $deathMissing, 'total' => $total],
            'DEAT_place' => ['event' => 'DEAT', 'kind' => 'place', 'value' => $deathMissing + $deathNoPlace, 'total' => $total],
        ];
    }

    /**
     * Mean parent-to-child birth-year delta across every parent-child pair
     * where both ends carry a parseable `1 BIRT / 2 DATE` line. Returns null
     * when the tree has fewer than one usable pair.
     */
    public function averageGenerationLength(): ?float
    {
        $rows = DB::table('link AS parent_link')
            ->select(
                'parent.i_gedcom AS parent_gedcom',
                'child.i_gedcom AS child_gedcom',
            )
            ->join('families', static function (JoinClause $join): void {
                $join
                    ->on('families.f_id', '=', 'parent_link.l_from')
                    ->on('families.f_file', '=', 'parent_link.l_file');
            })
            ->join('link AS child_link', static function (JoinClause $join): void {
                $join
                    ->on('child_link.l_from', '=', 'families.f_id')
                    ->on('child_link.l_file', '=', 'families.f_file')
                    ->where('child_link.l_type', '=', 'CHIL');
            })
            ->join('individuals AS parent', static function (JoinClause $join): void {
                $join
                    ->on('parent.i_id', '=', 'parent_link.l_to')
                    ->on('parent.i_file', '=', 'parent_link.l_file');
            })
            ->join('individuals AS child', static function (JoinClause $join): void {
                $join
                    ->on('child.i_id', '=', 'child_link.l_to')
                    ->on('child.i_file', '=', 'child_link.l_file');
            })
            ->where('parent_link.l_file', '=', $this->tree->id())
            ->whereIn('parent_link.l_type', ['HUSB', 'WIFE'])
            ->get();

        $deltas = [];

        foreach ($rows as $row) {
            $parentBlob = RowCast::string($row, 'parent_gedcom');
            $childBlob  = RowCast::string($row, 'child_gedcom');

            $parentYear = GedcomScanner::extractEventYear($parentBlob, 'BIRT');
            $childYear  = GedcomScanner::extractEventYear($childBlob, 'BIRT');

            if (($parentYear !== null) && ($childYear !== null) && ($childYear > $parentYear)) {
                $deltas[] = $childYear - $parentYear;
            }
        }

        if ($deltas === []) {
            return null;
        }

        return array_sum($deltas) / count($deltas);
    }

    /**
     * Total individual count for the tree, scoped to the same connection the
     * other repository methods use so the percentages always reconcile.
     */
    private function countIndividuals(): int
    {
        return TreeScope::table($this->tree, 'individuals')
            ->count('i_id');
    }
}
