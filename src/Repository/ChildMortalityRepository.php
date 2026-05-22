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
use MagicSunday\Webtrees\Statistic\Model\Dto\Metric\ChildMortalitySummary;
use MagicSunday\Webtrees\Statistic\Support\CenturyName;
use MagicSunday\Webtrees\Statistic\Support\ChildMortalityRate;
use MagicSunday\Webtrees\Statistic\Support\RowCast;

use function ksort;

/**
 * Child-mortality metrics for the LifeSpan tab — pairs every
 * individual's BIRT and DEAT julian-day, hands them to
 * {@see ChildMortalityRate::compute()}, and produces both a
 * tree-wide summary and a per-birth-century breakdown so the
 * dramatic historical decline (often 30–40 % in the 1700s, < 2 %
 * in modern cohorts) becomes visible.
 *
 * Individuals with only BIRT or only DEAT are silently excluded —
 * we can't determine survival without both anchors, and including
 * BIRT-only individuals would skew the rate toward zero (default
 * "no death recorded" gets misread as "survived").
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ChildMortalityRepository
{
    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Tree-wide child-mortality summary: count of individuals with
     * both BIRT + DEAT dates, count of those who died before age 5,
     * and the resulting percentage. Returns `null` if no such pair
     * exists in the tree.
     */
    public function summary(): ?ChildMortalitySummary
    {
        return ChildMortalityRate::compute($this->fetchBirthDeathPairs());
    }

    /**
     * Per-birth-century child-mortality breakdown, ordered ascending.
     * Returns the raw counts + mortality rate per century so the
     * view layer can format I18N labels and tooltip prose itself.
     *
     * Centuries with fewer than {@see self::MIN_COHORT_SIZE}
     * children are dropped to keep the line from spiking on a single
     * unlucky family; below that sample size the percentage is
     * statistically meaningless and visually misleading.
     *
     * @return list<array{century: int, total: int, died: int, rate: float}>
     */
    public function byBirthCentury(): array
    {
        $perCentury = [];

        foreach ($this->fetchBirthDeathPairs() as $pair) {
            $birthYear = $pair['birthYear'];

            if ($birthYear === 0) {
                continue;
            }

            $century = CenturyName::fromYear($birthYear);

            if (!isset($perCentury[$century])) {
                $perCentury[$century] = [];
            }

            $perCentury[$century][] = [
                'birthJd' => $pair['birthJd'],
                'deathJd' => $pair['deathJd'],
            ];
        }

        ksort($perCentury);
        $out = [];

        foreach ($perCentury as $century => $pairs) {
            $summary = ChildMortalityRate::compute($pairs);

            if (!$summary instanceof ChildMortalitySummary) {
                continue;
            }

            if ($summary->total < self::MIN_COHORT_SIZE) {
                continue;
            }

            $out[] = [
                'century' => $century,
                'total'   => $summary->total,
                'died'    => $summary->died,
                'rate'    => $summary->rate,
            ];
        }

        return $out;
    }

    /**
     * Pull every individual's BIRT julian-day, DEAT julian-day,
     * and BIRT year from the `dates` table via a self-join. Only
     * julian/gregorian dates participate — Hebrew / French-Rep / etc.
     * dates exist in the table but their `d_julianday1` values are
     * normalised, and mixing them in would dilute the cohort split
     * with calendars the user did not intend to compare against.
     *
     * @return list<array{birthJd: int, deathJd: int, birthYear: int}>
     */
    private function fetchBirthDeathPairs(): array
    {
        $rows = BirthDeathPairsQuery::for($this->tree)
            ->select([
                'birth.d_julianday1 AS birth_jd',
                'birth.d_year AS birth_year',
                'death.d_julianday1 AS death_jd',
            ])
            ->get();

        $pairs = [];

        foreach ($rows as $row) {
            $birthJd   = RowCast::int($row, 'birth_jd');
            $deathJd   = RowCast::int($row, 'death_jd');
            $birthYear = RowCast::int($row, 'birth_year');

            $pairs[] = [
                'birthJd'   => $birthJd,
                'deathJd'   => $deathJd,
                'birthYear' => $birthYear,
            ];
        }

        return $pairs;
    }

    /**
     * Below this cohort size a single dead child swings the
     * displayed mortality rate by more than 10 percentage points —
     * we drop the bucket entirely rather than show a noisy spike.
     */
    private const int MIN_COHORT_SIZE = 5;
}
