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
use MagicSunday\Webtrees\Statistic\Support\Database\BirthDeathPairsQuery;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;
use MagicSunday\Webtrees\Statistic\Support\Locale\CenturyName;

use function ksort;
use function round;

/**
 * Child-mortality metrics for the LifeSpan tab — pairs every
 * individual's BIRT and DEAT julian-day, computes the WHO/UN
 * under-5-mortality rate per cohort, and produces both a tree-wide
 * summary and a per-birth-century breakdown so the dramatic
 * historical decline (often 30–40 % in the 1700s, < 2 % in modern
 * cohorts) becomes visible.
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
     * WHO/UN standard "under-5 mortality" threshold expressed in
     * julian days. Comparable across historical periods because the
     * cut-off is age-based rather than date-based.
     */
    private const int UNDER_FIVE_THRESHOLD_DAYS = 5 * 365;

    /**
     * Below this cohort size a single dead child swings the
     * displayed mortality rate by more than 10 percentage points —
     * we drop the bucket entirely rather than show a noisy spike.
     */
    private const int MIN_COHORT_SIZE = 5;

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
        return $this->computeRate($this->fetchBirthDeathPairs());
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
            $summary = $this->computeRate($pairs);

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
     * Compute the child-mortality summary for a list of BIRT + DEAT
     * julian-day pairs. Pairs whose death julian-day precedes the
     * birth (recording error) are dropped so they cannot inflate
     * the rate; the caller has already filtered out individuals
     * without both anchors. Returns null when no valid pair survives
     * the inner filter so the view can render a "no data" placeholder
     * rather than a misleading "0 %".
     *
     * @param iterable<array{birthJd: int, deathJd: int}> $pairs Iterable of valid BIRT + DEAT julian-day pairs
     */
    private function computeRate(iterable $pairs): ?ChildMortalitySummary
    {
        $total = 0;
        $died  = 0;

        foreach ($pairs as $pair) {
            $birthJd = $pair['birthJd'];
            $deathJd = $pair['deathJd'];

            if ($birthJd <= 0) {
                continue;
            }

            if ($deathJd <= 0) {
                continue;
            }

            if ($deathJd < $birthJd) {
                continue;
            }

            ++$total;

            if (($deathJd - $birthJd) < self::UNDER_FIVE_THRESHOLD_DAYS) {
                ++$died;
            }
        }

        if ($total === 0) {
            return null;
        }

        return new ChildMortalitySummary(
            total: $total,
            died: $died,
            rate: round(($died / $total) * 100, 1),
        );
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
}
