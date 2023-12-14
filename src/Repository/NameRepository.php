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
use Fisharebest\Webtrees\Tree;
use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Collection;

/**
 * A repository providing methods for name-related statistics.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
class NameRepository
{
    /**
     * @var Tree
     */
    private Tree $tree;

    /**
     * Constructor.
     *
     * @param Tree $tree
     */
    public function __construct(Tree $tree)
    {
        $this->tree = $tree;
    }

    /**
     * Returns a collection of all surnames.
     *
     * @param int $treeId The ID of the tree to query
     *
     * @return Collection
     *
     * @see \Fisharebest\Webtrees\Module\TopSurnamesModule::getBlock
     */
    private function findAllSurnames(int $treeId): Collection
    {
        return DB::table('name')
            ->where('n_file', '=', $treeId)
            ->where('n_type', '<>', '_MARNM')
            ->where('n_surn', '<>', '')
            ->where('n_surn', '<>', Individual::NOMEN_NESCIO)
            ->select([
                $this->binaryColumn('n_surn', 'n_surn'),
                $this->binaryColumn('n_surname', 'n_surname'),
                new Expression('COUNT(*) AS total'),
            ])
            ->groupBy([
                $this->binaryColumn('n_surn'),
                $this->binaryColumn('n_surname'),
            ])
            ->get();
    }

    /**
     * Returns a collection of all given names.
     *
     * @param int    $treeId The ID of the tree to query
     * @param string $sex    The sex
     *
     * @return array
     *
     * @see \Fisharebest\Webtrees\Statistics\Repository\IndividualRepository::commonGivenQuery
     */
    private function findAllGivenNamesBySex(int $treeId, string $sex): array
    {
        $records = DB::table('name')
            ->join('individuals', static function (JoinClause $join): void {
                $join
                    ->on('i_file', '=', 'n_file')
                    ->on('i_id', '=', 'n_id');
            })
            ->where('n_file', '=', $treeId)
            ->where('n_type', '<>', '_MARNM')
            ->where('n_givn', '<>', Individual::PRAENOMEN_NESCIO)
            ->where(new Expression('LENGTH(n_givn)'), '>', 1)
            ->where('i_sex', '=', $sex)
            ->groupBy(['n_givn'])
            ->pluck(new Expression('COUNT(distinct n_id) AS count'), 'n_givn');

        $nameList = [];

        foreach ($records as $n_givn => $count) {
            // Split "John Thomas" into "John" and "Thomas" and count against both totals
            foreach (explode(' ', (string) $n_givn) as $given) {
                // Exclude initials and particles.
                if (preg_match('/^([A-Z]|[a-z]{1,3})$/', $given) !== 1) {
                    if (array_key_exists($given, $nameList)) {
                        $nameList[$given] += (int) $count;
                    } else {
                        $nameList[$given] = (int) $count;
                    }
                }
            }
        }

        return $nameList;
    }

    /**
     * This module assumes the database will use binary collation on the name columns.
     * Until we convert MySQL databases to use utf8_bin, we need to do this at run-time.
     *
     * @param string      $column
     * @param string|null $alias
     *
     * @return Expression
     */
    private function binaryColumn(string $column, ?string $alias = null): Expression
    {
        if (DB::connection()->getDriverName() === 'mysql') {
            $sql = 'CAST(' . $column . ' AS binary)';
        } else {
            $sql = $column;
        }

        if ($alias !== null) {
            $sql .= ' AS ' . $alias;
        }

        return new Expression($sql);
    }

    /**
     * Flattens the list of surnames and their variants into a list containing only the surname variant
     * with the most entries.
     *
     * @param array $surnames
     *
     * @return array
     */
    private function flattenSurnameVariantsList(array $surnames): array
    {
        $flattenSurnames = [];

        // Flatten the surname variants
        foreach ($surnames as $surnameVariants) {
            $maxNameCount = 0;
            $totalCount   = 0;
            $topSurname   = '';

            foreach ($surnameVariants as $surname => $count) {
                $totalCount += $count;

                // Select the most common surname from all variants
                if ($count > $maxNameCount) {
                    $maxNameCount = $count;
                    $topSurname   = $surname;
                }
            }

            $flattenSurnames[] = [
                'name'  => $topSurname,
                'count' => $totalCount,
            ];
        }

        return $flattenSurnames;
    }

    /**
     * Flattens the list of given names.
     *
     * @param array $givenNames
     *
     * @return array
     */
    private function flattenGivenNameList(array $givenNames): array
    {
        $flattenGivenNames = [];

        // Flatten the surname variants
        foreach ($givenNames as $givenName => $count) {
            $flattenGivenNames[] = [
                'name'  => $givenName,
                'count' => $count,
            ];
        }

        return $flattenGivenNames;
    }

    /**
     * Returns a list of all surnames and their counts.
     *
     * @return array<array<int, int|string>>
     */
    private function getAllSurnames(): array
    {
        $records = $this->findAllSurnames($this->tree->id());

        /** @var array<array<int>> $topSurnames */
        $topSurnames = [];

        foreach ($records as $row) {
            $row->n_surn = $row->n_surn === '' ? $row->n_surname : $row->n_surn;
            $row->n_surn = I18N::strtoupper(I18N::language()->normalize($row->n_surn));

            $topSurnames[$row->n_surn][$row->n_surname] ??= 0;
            $topSurnames[$row->n_surn][$row->n_surname] += (int) $row->total;
        }

        return $topSurnames;
    }

    /**
     * Returns the total number of different surnames (ignoring variants and empty surnames).
     *
     * @return int
     */
    public function getTotalSurnames(): int
    {
        return count($this->getAllSurnames());
    }

    /**
     * Returns a list of top surnames.
     *
     * @param int $limit The number of surnames to return
     *
     * @return array<array<int, int|string>>
     */
    public function getTopSurnames(int $limit = 10): array
    {
        $records = $this->getAllSurnames();
        $flatten = $this->flattenSurnameVariantsList($records);

        // Sort names in descending order
        uasort(
            $flatten,
            static fn (array $x, array $y): int => $y['count'] <=> $x['count']
        );

        // Return only the requested number of elements
        return array_values(
            array_slice(
                $flatten,
                0,
                $limit,
                true
            )
        );
    }

    public function getTotalMaleGivenNames(): int
    {
        return count(
            $this->findAllGivenNamesBySex($this->tree->id(), 'M')
        );
    }

    /**
     * Returns a list of top male given names.
     *
     * @param int $limit The number of names to return
     *
     * @return array<array<int, int|string>>
     */
    public function getTopMaleGivenNames(int $limit = 10): array
    {
        $records = $this->findAllGivenNamesBySex($this->tree->id(), 'M');
        $flatten = $this->flattenGivenNameList($records);

        // Sort names in descending order
        uasort(
            $flatten,
            static fn (array $x, array $y): int => $y['count'] <=> $x['count']
        );

        // Return only the requested number of elements
        return array_values(
            array_slice(
                $flatten,
                0,
                $limit,
                true
            )
        );
    }

    public function getTotalFemaleGivenNames(): int
    {
        return count(
            $this->findAllGivenNamesBySex($this->tree->id(), 'F')
        );
    }

    /**
     * Returns a list of top female given names.
     *
     * @param int $limit The number of names to return
     *
     * @return array<array<int, int|string>>
     */
    public function getTopFemaleGivenNames(int $limit = 10): array
    {
        $records = $this->findAllGivenNamesBySex($this->tree->id(), 'F');
        $flatten = $this->flattenGivenNameList($records);

        // Sort names in descending order
        uasort(
            $flatten,
            static fn (array $x, array $y): int => $y['count'] <=> $x['count']
        );

        // Return only the requested number of elements
        return array_values(
            array_slice(
                $flatten,
                0,
                $limit,
                true
            )
        );
    }
}
