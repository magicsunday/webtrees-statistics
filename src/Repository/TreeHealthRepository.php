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
use MagicSunday\Webtrees\Statistic\Model\Dto\Metric\RateCount;
use MagicSunday\Webtrees\Statistic\Support\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\RowCast;
use MagicSunday\Webtrees\Statistic\Support\TreeScope;

use function array_sum;
use function count;
use function is_string;

/**
 * Tree-wide data-quality metrics: source-citation coverage, missing-event
 * gap rates, and the average parent-to-child birth-year delta. Counters
 * lean on the shared {@see GedcomScanner} so PHP-side scans and SQL-side
 * NOT LIKE filters stay in lockstep with the same anchoring rules.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class TreeHealthRepository
{
    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Fraction of individuals with at least one SOUR citation, expressed
     * as `{value, total}` so the RateList partial can derive both the
     * percentage and the absolute counts.
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
     * Per-event missing-data rates for BIRT, DEAT, and MARR. Each event
     * yields two ProgressList rows — one for the event itself, one for
     * its `2 PLAC` sub-line. Event-presence is computed in SQL via
     * anchored NOT LIKE filters; PLAC-within-event requires PHP-side
     * scanning so the place check is scoped to the right event block.
     *
     * Marriage gaps use a different denominator: only individuals who
     * are actually spouses in at least one family contribute. Counting
     * every minor and never-married person as "missing MARR" would dilute
     * the data-quality signal we care about.
     *
     * @return array<string, array{event: string, kind: string, value: int, total: int}>
     */
    public function missingEventGaps(): array
    {
        $total       = $this->countIndividuals();
        $spouseTotal = $this->countSpouses();

        $birthMissing = $this->countIndividualsMissingEvent('BIRT');
        $deathMissing = $this->countIndividualsMissingEvent('DEAT');

        return [
            'BIRT_event' => [
                'event' => 'BIRT',
                'kind'  => 'event',
                'value' => $birthMissing,
                'total' => $total,
            ],
            'BIRT_place' => [
                'event' => 'BIRT',
                'kind'  => 'place',
                'value' => $this->countIndividualsMissingEventPlace('BIRT', $birthMissing),
                'total' => $total,
            ],
            'MARR_event' => [
                'event' => 'MARR',
                'kind'  => 'event',
                'value' => $this->countSpousesMissingMarriageEvent(),
                'total' => $spouseTotal,
            ],
            'MARR_place' => [
                'event' => 'MARR',
                'kind'  => 'place',
                'value' => $this->countSpousesMissingMarriagePlace(),
                'total' => $spouseTotal,
            ],
            'DEAT_event' => [
                'event' => 'DEAT',
                'kind'  => 'event',
                'value' => $deathMissing,
                'total' => $total,
            ],
            'DEAT_place' => [
                'event' => 'DEAT',
                'kind'  => 'place',
                'value' => $this->countIndividualsMissingEventPlace('DEAT', $deathMissing),
                'total' => $total,
            ],
        ];
    }

    /**
     * Mean parent-to-child birth-year delta across every parent-child
     * pair where both ends carry a parseable `1 BIRT / 2 DATE` line.
     * Returns null when the tree has fewer than one usable pair.
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
     * Total individual count for the tree, scoped to the same connection
     * the other repository methods use so the percentages always reconcile.
     */
    /**
     * Count individuals who are spouses in at least one family (have
     * a `FAMS` link). This is the denominator the marriage-gap rows
     * use — "of those who married, how many lack a recorded MARR" is
     * a meaningful data-quality signal, while including minors and
     * never-married people would dilute it.
     */
    private function countSpouses(): int
    {
        return TreeScope::table($this->tree, 'individuals')
            ->whereExists(static function (Builder $query): void {
                $query
                    ->select(new Expression('1'))
                    ->from('link')
                    ->whereColumn('link.l_file', 'individuals.i_file')
                    ->whereColumn('link.l_from', 'individuals.i_id')
                    ->where('link.l_type', '=', 'FAMS');
            })
            ->count('i_id');
    }

    /**
     * Spouses whose every FAMS family lacks a level-1 MARR event.
     * Walks the per-individual FAMS chain and checks each family's
     * gedcom blob; an individual counts as "missing MARR" only when
     * none of their spouse-families carries the event.
     */
    private function countSpousesMissingMarriageEvent(): int
    {
        $patterns = GedcomScanner::anchoredLikePatterns('MARR');

        return TreeScope::table($this->tree, 'individuals')
            ->whereExists(static function (Builder $outer): void {
                $outer
                    ->select(new Expression('1'))
                    ->from('link')
                    ->whereColumn('link.l_file', 'individuals.i_file')
                    ->whereColumn('link.l_from', 'individuals.i_id')
                    ->where('link.l_type', '=', 'FAMS');
            })
            ->whereNotExists(static function (Builder $outer) use ($patterns): void {
                $outer
                    ->select(new Expression('1'))
                    ->from('link')
                    ->join('families', static function (JoinClause $join): void {
                        $join
                            ->on('families.f_id', '=', 'link.l_to')
                            ->on('families.f_file', '=', 'link.l_file');
                    })
                    ->whereColumn('link.l_file', 'individuals.i_file')
                    ->whereColumn('link.l_from', 'individuals.i_id')
                    ->where('link.l_type', '=', 'FAMS')
                    ->where(static function (Builder $patternQuery) use ($patterns): void {
                        foreach ($patterns as $pattern) {
                            $patternQuery->orWhere('families.f_gedcom', 'LIKE', $pattern);
                        }
                    });
            })
            ->count('i_id');
    }

    /**
     * Spouses whose every FAMS family with MARR lacks the `2 PLAC`
     * sub-line. Builds on the per-event missing pattern but scoped
     * to families that DO have MARR, otherwise the "missing place"
     * would double-count families that lack MARR entirely.
     */
    private function countSpousesMissingMarriagePlace(): int
    {
        $eventPatterns = GedcomScanner::anchoredLikePatterns('MARR');

        $candidates = TreeScope::table($this->tree, 'individuals')
            ->whereExists(static function (Builder $outer) use ($eventPatterns): void {
                $outer
                    ->select(new Expression('1'))
                    ->from('link')
                    ->join('families', static function (JoinClause $join): void {
                        $join
                            ->on('families.f_id', '=', 'link.l_to')
                            ->on('families.f_file', '=', 'link.l_file');
                    })
                    ->whereColumn('link.l_file', 'individuals.i_file')
                    ->whereColumn('link.l_from', 'individuals.i_id')
                    ->where('link.l_type', '=', 'FAMS')
                    ->where(static function (Builder $patternQuery) use ($eventPatterns): void {
                        foreach ($eventPatterns as $pattern) {
                            $patternQuery->orWhere('families.f_gedcom', 'LIKE', $pattern);
                        }
                    });
            })
            ->pluck('i_id');

        $missingPlace = 0;

        foreach ($candidates as $individualId) {
            $hasPlace = DB::table('link')
                ->join('families', static function (JoinClause $join): void {
                    $join
                        ->on('families.f_id', '=', 'link.l_to')
                        ->on('families.f_file', '=', 'link.l_file');
                })
                ->where('link.l_file', '=', $this->tree->id())
                ->where('link.l_from', '=', $individualId)
                ->where('link.l_type', '=', 'FAMS')
                ->pluck('families.f_gedcom')
                ->contains(static fn (string $gedcom): bool => GedcomScanner::hasEventPlace($gedcom, 'MARR'));

            if (!$hasPlace) {
                ++$missingPlace;
            }
        }

        return $missingPlace;
    }

    private function countIndividuals(): int
    {
        return TreeScope::table($this->tree, 'individuals')
            ->count('i_id');
    }

    /**
     * Count individuals whose `i_gedcom` carries no anchored `\n1 <tag>`
     * line. SQL-only — no payload transfer to PHP.
     *
     * @param string $tag Level-1 event tag whose absence to count
     */
    private function countIndividualsMissingEvent(string $tag): int
    {
        $patterns = GedcomScanner::anchoredLikePatterns($tag);

        return TreeScope::table($this->tree, 'individuals')
            ->where(static function (Builder $query) use ($patterns): void {
                foreach ($patterns as $pattern) {
                    $query->where('i_gedcom', 'NOT LIKE', $pattern);
                }
            })
            ->count('i_id');
    }

    /**
     * Count individuals whose `i_gedcom` either lacks the event or has
     * the event but no `\n2 PLAC` sub-line with a non-empty payload.
     * Loads only the i_gedcom blobs whose event-presence requires PHP
     * disambiguation; the event-missing population is already known
     * from {@see countIndividualsMissingEvent()}.
     *
     * @param string $tag               Level-1 event tag whose place to count
     * @param int    $missingEventCount Population missing the event itself; added to the result
     */
    private function countIndividualsMissingEventPlace(string $tag, int $missingEventCount): int
    {
        $patterns = GedcomScanner::anchoredLikePatterns($tag);

        $gedcoms = TreeScope::table($this->tree, 'individuals')
            ->where(static function (Builder $query) use ($patterns): void {
                foreach ($patterns as $index => $pattern) {
                    if ($index === 0) {
                        $query->where('i_gedcom', 'LIKE', $pattern);
                    } else {
                        $query->orWhere('i_gedcom', 'LIKE', $pattern);
                    }
                }
            })
            ->pluck('i_gedcom');

        $missingPlace = 0;

        foreach ($gedcoms as $gedcom) {
            $blob = is_string($gedcom) ? $gedcom : '';

            if (!GedcomScanner::hasEventPlace($blob, $tag)) {
                ++$missingPlace;
            }
        }

        return $missingPlace + $missingEventCount;
    }
}
