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
use MagicSunday\Webtrees\Statistic\Support\GedcomScanner;

use function array_keys;
use function array_slice;
use function arsort;
use function explode;
use function intdiv;
use function is_string;
use function preg_match;

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
     *
     * @return array{decades: list<int>, names: list<string>, series: array<string, array<int, int>>}
     */
    public function countByDecade(int $topN): array
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
            return ['decades' => [], 'names' => [], 'series' => []];
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

        ksort($byDecade);
        $decades = array_keys($byDecade);

        // Materialise dense rows so the renderer always sees every name
        // for every decade (missing entries default to zero).
        $series = [];

        foreach ($topNames as $name) {
            $series[$name] = [];

            foreach ($decades as $decade) {
                $series[$name][$decade] = $byDecade[$decade][$name] ?? 0;
            }
        }

        return [
            'decades' => $decades,
            'names'   => $topNames,
            'series'  => $series,
        ];
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
            $givn   = is_string($row->givn) ? $row->givn : '';
            $gedcom = is_string($row->gedcom) ? $row->gedcom : '';
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
     * Split a given-name string on whitespace into countable tokens,
     * dropping initials and short particles using the same regex
     * webtrees core's `StatisticsData::commonGivenNames()` applies.
     *
     * @return list<string>
     */
    private function splitTokens(string $givn): array
    {
        $tokens = [];

        foreach (explode(' ', $givn) as $token) {
            if ($token === '') {
                continue;
            }

            if (preg_match(self::PARTICLE_REGEX, $token) === 1) {
                continue;
            }

            $tokens[] = $token;
        }

        return $tokens;
    }
}
