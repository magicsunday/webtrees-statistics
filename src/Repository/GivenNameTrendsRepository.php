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
use Illuminate\Database\Query\JoinClause;
use MagicSunday\Webtrees\Statistic\Model\Dto\StreamGraph\GivenNameTrendsPayload;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function array_keys;
use function array_slice;
use function arsort;
use function intdiv;
use function max;
use function min;
use function preg_match;
use function preg_split;
use function range;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Per-decade frequency of the top-N given names across the tree.
 * Backs the Names tab's stream graph: each individual contributes one
 * count for every token of their primary given name to the decade
 * derived from their birth year.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class GivenNameTrendsRepository
{
    /**
     * Regex used to strip given-name particles and initials (matches
     * webtrees core's commonGivenNames tokenisation: single capital
     * letter, or one-to-three lowercase letters).
     */
    private const string PARTICLE_REGEX = '/^([A-Z]|[a-z]{1,3})$/';

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Compute the per-decade count of the top-N given names. Returns
     * `{decades, names, series}` so the stream-graph renderer can lay
     * out axes and band labels without re-aggregating.
     *
     * @param int $topN Maximum number of distinct given names to keep
     */
    public function countByDecade(int $topN): GivenNameTrendsPayload
    {
        $rows = $this->loadIndividualNamesAndYears();

        // First pass — collect total counts per name to identify the top-N.
        $perNameTotal = [];

        foreach ($rows as $entry) {
            foreach ($this->splitTokens($entry['givn']) as $token) {
                $perNameTotal[$token] = ($perNameTotal[$token] ?? 0) + 1;
            }
        }

        arsort($perNameTotal);
        $topNames = array_slice(array_keys($perNameTotal), 0, $topN);

        if ($topNames === []) {
            return new GivenNameTrendsPayload(decades: [], names: [], series: []);
        }

        // Second pass — bucket the same individuals by decade for the top names.
        $byDecade   = [];
        $topNameSet = [];

        foreach ($topNames as $name) {
            $topNameSet[$name] = true;
        }

        foreach ($rows as $entry) {
            $decade = intdiv($entry['year'], 10) * 10;

            foreach ($this->splitTokens($entry['givn']) as $token) {
                if (!isset($topNameSet[$token])) {
                    continue;
                }

                $byDecade[$decade][$token] = ($byDecade[$decade][$token] ?? 0) + 1;
            }
        }

        // Decade range starts at the first decade where any top-N name
        // appears (no leading zero pad from outlier dates centuries
        // before the bulk of the data) and extends to the most recent
        // birth in the whole population (so modern decades with births
        // outside the top-N show up as the natural fade-out of classic
        // names).
        $decades = $this->buildDecadeRange($rows, $byDecade);

        // Materialise dense rows so the renderer always sees every name
        // for every decade (missing entries default to zero).
        $series = [];

        foreach ($topNames as $name) {
            $series[$name] = [];

            foreach ($decades as $decade) {
                $series[$name][$decade] = $byDecade[$decade][$name] ?? 0;
            }
        }

        return new GivenNameTrendsPayload(
            decades: $decades,
            names: $topNames,
            series: $series,
        );
    }

    /**
     * Build the dense decade range for the chart's x-axis. Starts at
     * the first decade where any top-N name actually has a birth (no
     * pre-history pad from outlier early dates) and ends at the most
     * recent dated birth in the whole population so the right side
     * shows the natural fade-out of classic names.
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
     * Load every individual's primary given name plus a parseable birth
     * year. Skips rows where either piece is missing.
     *
     * @return list<array{givn: string, year: int}>
     */
    private function loadIndividualNamesAndYears(): array
    {
        $rows = DB::table('individuals')
            ->join('name', static function (JoinClause $join): void {
                $join
                    ->on('name.n_file', '=', 'individuals.i_file')
                    ->on('name.n_id', '=', 'individuals.i_id')
                    ->where('name.n_num', '=', 0)
                    ->where('name.n_type', '<>', '_MARNM');
            })
            ->where('individuals.i_file', '=', $this->tree->id())
            ->where(static function (Builder $query): void {
                $query
                    ->whereNotNull('name.n_givn')
                    ->where('name.n_givn', '<>', '');
            })
            ->select(
                'name.n_givn AS givn',
                'individuals.i_gedcom AS gedcom',
            )
            ->get();

        $out = [];

        foreach ($rows as $row) {
            $givn   = RowCast::string($row, 'givn');
            $gedcom = RowCast::string($row, 'gedcom');
            $year   = GedcomScanner::extractEventYear($gedcom, 'BIRT');

            if ($givn === '') {
                continue;
            }

            if ($year === null) {
                continue;
            }

            $out[] = ['givn' => $givn, 'year' => $year];
        }

        return $out;
    }

    /**
     * Split a given-name string on Unicode whitespace into countable
     * tokens, dropping initials and short particles using the same
     * regex webtrees core's `StatisticsData::commonGivenNames()`
     * applies. `preg_split('/\s+/u', …)` recognises tabs and NBSP as
     * separators too, so a hand-edited name with stray whitespace
     * still tokenises cleanly.
     *
     * @return list<string>
     */
    private function splitTokens(string $givn): array
    {
        $rawTokens = preg_split('/\s+/u', $givn, -1, PREG_SPLIT_NO_EMPTY);

        if ($rawTokens === false) {
            return [];
        }

        $tokens = [];

        foreach ($rawTokens as $token) {
            if (preg_match(self::PARTICLE_REGEX, $token) === 1) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }
}
