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
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Model\StreamGraph\GivenNameTrendsPayload;
use MagicSunday\Webtrees\Statistic\Support\Aggregator\TopNAggregator;
use MagicSunday\Webtrees\Statistic\Support\Calc\GregorianDate;
use MagicSunday\Webtrees\Statistic\Support\Database\DedupedEventDates;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GivenNameNormalizer;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function array_keys;
use function date;
use function intdiv;
use function max;
use function min;
use function range;
use function usort;

/**
 * Per-decade frequency of the top-N given names across the tree. Backs the
 * Names tab's stream graph: each individual contributes one count for every
 * token of their primary given name to the decade derived from their birth
 * year.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class GivenNameTrendsRepository
{
    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Compute the per-decade count of the top-N given names. Returns `{decades,
     * names, series}` so the stream-graph renderer can lay out axes and band
     * labels without re-aggregating.
     *
     * @param int $topN Maximum number of distinct given names to keep
     */
    public function countByDecade(int $topN): GivenNameTrendsPayload
    {
        $rows = $this->loadIndividualNamesAndYears();

        // Single tokenisation pass — ICU normalisation is not free, so decode
        // each individual's fold keys once: accumulate per-key totals plus the
        // raw spellings backing each key (for the dominant-spelling label), and
        // remember each row's decade + keys so the bucketing pass below reuses
        // them instead of re-tokenising.
        /** @var array<string, int> $perKeyTotal */
        $perKeyTotal = [];

        /** @var array<string, array<string, int>> $rawByKey */
        $rawByKey = [];

        /** @var list<array{decade: int, keys: list<string>}> $decoded */
        $decoded = [];

        foreach ($rows as $entry) {
            $keys = [];

            foreach (GivenNameNormalizer::tokens($entry['givn']) as $token) {
                $key                    = GivenNameNormalizer::foldKey($token);
                $keys[]                 = $key;
                $perKeyTotal[$key]      = ($perKeyTotal[$key] ?? 0) + 1;
                $rawByKey[$key][$token] = ($rawByKey[$key][$token] ?? 0) + 1;
            }

            $decoded[] = ['decade' => intdiv($entry['year'], 10) * 10, 'keys' => $keys];
        }

        // Order by count descending, then by fold key ascending, for an
        // engine-independent top-N. The shared {@see TopNAggregator::rankKeys()}
        // owns this tie-break: relying on the DB row order instead would diverge
        // (n_givn collates differently across SQLite and MySQL).
        $topKeys = TopNAggregator::rankKeys($perKeyTotal, $topN);

        if ($topKeys === []) {
            return new GivenNameTrendsPayload(decades: [], names: [], series: []);
        }

        // Resolve each surviving fold key to its display label (dominant raw
        // spelling) and build the membership set for the decade pass.
        /** @var array<string, string> $labelByKey */
        $labelByKey = [];

        $topKeySet = [];

        foreach ($topKeys as $key) {
            $labelByKey[$key] = GivenNameNormalizer::dominantForm($rawByKey[$key] ?? []);
            $topKeySet[$key]  = true;
        }

        // Bucket the decoded rows by decade, keyed by fold key (reusing the
        // first pass's keys), so a variant's birth counts under the same band.
        $byDecade = [];

        foreach ($decoded as $row) {
            foreach ($row['keys'] as $key) {
                if (!isset($topKeySet[$key])) {
                    continue;
                }

                $byDecade[$row['decade']][$key] = ($byDecade[$row['decade']][$key] ?? 0) + 1;
            }
        }

        // Decade range starts at the first decade where any top-N name
        // appears (no leading zero pad from outlier dates centuries
        // before the bulk of the data) and extends to the most recent
        // birth in the whole population (so modern decades with births
        // outside the top-N show up as the natural fade-out of classic
        // names).
        $decades = $this->buildDecadeRange($rows, $byDecade);

        // Materialise dense rows under the display label so the renderer always
        // sees every name for every decade (missing entries default to zero).
        $names  = [];
        $series = [];

        foreach ($topKeys as $key) {
            $name          = $labelByKey[$key] ?? '';
            $names[]       = $name;
            $series[$name] = [];

            foreach ($decades as $decade) {
                $series[$name][$decade] = $byDecade[$decade][$key] ?? 0;
            }
        }

        return new GivenNameTrendsPayload(
            decades: $decades,
            names: $names,
            series: $series,
        );
    }

    /**
     * For each of the top-N most frequent given names: the most recent birth
     * year a token of that name was recorded on, its total occurrence count, and
     * whether that year still falls inside the active window. Spelling variants
     * fold under their dominant form exactly as in {@see countByDecade()}, and
     * the selection is the same top-N-by-frequency the decade series uses.
     *
     * Rows are ordered by last year descending — still-given names lead, long
     * vanished ones trail — with the display name as a deterministic final
     * tie-break. The active flag is a visual highlight only; it drives neither
     * the selection nor the ordering.
     *
     * @param int      $topN              Maximum number of distinct given names to keep
     * @param int|null $referenceYear     Reference year ("now") for the active-window test; defaults to the current year
     * @param int      $activeWithinYears A name counts as still active when its last year is no more than this many years before the reference year
     *
     * @return list<array{name: string, lastYear: int, total: int, isActive: bool}>
     */
    public function lastYearByName(int $topN, ?int $referenceYear = null, int $activeWithinYears = 25): array
    {
        $rows = $this->loadIndividualNamesAndYears();

        // Fold spelling variants onto one key (like countByDecade) so "José" and
        // "Jose" share a row; track per-key totals, the latest birth year, and
        // the raw spellings backing the dominant-spelling display label.
        /** @var array<string, int> $perKeyTotal */
        $perKeyTotal = [];

        /** @var array<string, int> $perKeyLastYear */
        $perKeyLastYear = [];

        /** @var array<string, array<string, int>> $rawByKey */
        $rawByKey = [];

        foreach ($rows as $entry) {
            foreach (GivenNameNormalizer::tokens($entry['givn']) as $token) {
                $key                    = GivenNameNormalizer::foldKey($token);
                $perKeyTotal[$key]      = ($perKeyTotal[$key] ?? 0) + 1;
                $perKeyLastYear[$key]   = max($perKeyLastYear[$key] ?? $entry['year'], $entry['year']);
                $rawByKey[$key][$token] = ($rawByKey[$key][$token] ?? 0) + 1;
            }
        }

        // Top-N by frequency with the shared engine-independent tie-break (count
        // descending, then fold key ascending) — the same selection the decade
        // series uses, so the two cards agree on which names are "the top names".
        $topKeys = TopNAggregator::rankKeys($perKeyTotal, $topN);

        $threshold = ($referenceYear ?? (int) date('Y')) - $activeWithinYears;

        $result = [];

        foreach ($topKeys as $key) {
            $lastYear = $perKeyLastYear[$key] ?? 0;

            $result[] = [
                'name'     => GivenNameNormalizer::dominantForm($rawByKey[$key] ?? []),
                'lastYear' => $lastYear,
                'total'    => $perKeyTotal[$key] ?? 0,
                'isActive' => ($lastYear >= $threshold),
            ];
        }

        // Order by most recent birth year descending, with the display name as a
        // stable final tie-break. The active flag only highlights; it does not
        // reorder.
        usort($result, static function (array $a, array $b): int {
            $byYearDescending = $b['lastYear'] <=> $a['lastYear'];

            if ($byYearDescending !== 0) {
                return $byYearDescending;
            }

            return $a['name'] <=> $b['name'];
        });

        return $result;
    }

    /**
     * Build the dense decade range for the chart's x-axis. Starts at the first
     * decade where any top-N name actually has a birth (no pre-history pad from
     * outlier early dates) and ends at the most recent dated birth in the whole
     * population so the right side shows the natural fade-out of classic names.
     *
     * @param list<array{givn: string, year: int}> $rows     Loaded individuals with names + years
     * @param array<int, array<string, int>>       $byDecade Top-N counts already bucketed per decade
     *
     * @return list<int>
     */
    private function buildDecadeRange(array $rows, array $byDecade): array
    {
        if ($byDecade === [] || $rows === []) {
            return [];
        }

        $topDecades  = array_keys($byDecade);
        $startDecade = min($topDecades);

        $years = [];

        foreach ($rows as $entry) {
            $years[] = $entry['year'];
        }

        $endDecade = intdiv(max($years), 10) * 10;

        if ($endDecade < $startDecade) {
            $endDecade = max($topDecades);
        }

        return range($startDecade, $endDecade, 10);
    }

    /**
     * Load every individual's primary given name plus its Gregorian birth year.
     *
     * The birth year comes from the `dates` table via {@see DedupedEventDates}
     * (one deduplicated lower-bound row per individual) rather than a raw-GEDCOM
     * scan, so a non-Gregorian/Julian birth (French Republican, Hebrew, …) is
     * {@see GregorianDate}-converted to the decade it actually occurred in
     * instead of being read in its native calendar's year (An XII → decade 10).
     * Only individuals carrying both a primary given name and a dated birth
     * contribute; the join drops the rest.
     *
     * @return list<array{givn: string, year: int}>
     */
    private function loadIndividualNamesAndYears(): array
    {
        $treeId = $this->tree->id();

        $rows = DedupedEventDates::query($this->tree, 'BIRT')
            ->join('name', static function (JoinClause $join) use ($treeId): void {
                $join
                    ->on('name.n_id', '=', 'event_dates.d_gid')
                    ->where('name.n_file', '=', $treeId)
                    ->where('name.n_num', '=', 0)
                    ->where('name.n_type', '<>', '_MARNM');
            })
            ->whereNotNull('name.n_givn')
            ->where('name.n_givn', '<>', '')
            ->select([
                'name.n_givn AS givn',
                'event_dates.d_type AS d_type',
                'event_dates.d_year AS d_year',
                'event_dates.d_julianday1 AS d_julianday1',
            ])
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $givn = RowCast::string($row, 'givn');

            // Skip the "no given name" placeholder (`@P.N.`); it is not a real
            // name and would otherwise rank as a band of its own in both the
            // decade series and the last-year aggregate.
            if ($givn === '') {
                continue;
            }

            if ($givn === Individual::PRAENOMEN_NESCIO) {
                continue;
            }

            $out[] = [
                'givn' => $givn,
                'year' => GregorianDate::year(
                    RowCast::string($row, 'd_type'),
                    RowCast::int($row, 'd_year'),
                    RowCast::int($row, 'd_julianday1'),
                ),
            ];
        }

        return $out;
    }
}
