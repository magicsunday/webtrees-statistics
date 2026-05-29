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
use MagicSunday\Webtrees\Statistic\Model\Metric\PlaceDispersionSummary;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RowCast;

use function array_unique;
use function count;
use function preg_match_all;
use function round;
use function trim;

/**
 * Geographic-dispersion metric for the Places tab — how many distinct PLAC
 * values each individual carries across all their level-1 events (BIRT, DEAT,
 * BAPM, BURI, RESI, …). High dispersion = the person was recorded at many
 * locations across their life, indicating mobility or thorough documentation.
 * Low dispersion (typically 1) = a single PLAC like a birth-only stub record.
 *
 * Returns both the tree-wide average AND the per-individual count distribution
 * so the viewer can disambiguate "high avg because of a few wandering
 * ancestors" from "high avg because everyone moved around".
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class PlaceDispersionRepository
{
    /**
     * Cap for the distribution histogram — individuals with more than this many
     * distinct places collapse into the overflow. Five is a generous ceiling
     * that covers all but the most thoroughly-documented individuals; beyond
     * that the buckets stretch the axis without adding visual information.
     */
    private const int DISTRIBUTION_MAX = 5;

    /**
     * @param Tree $tree The tree the statistics are computed for
     */
    public function __construct(
        private Tree $tree,
    ) {
    }

    /**
     * Compute the dispersion summary: tree-wide average of distinct PLAC counts
     * per individual, the count of individuals with at least one PLAC sample,
     * and the bucketed distribution of those counts (`1`, `2`, `3`, `4`, `5+`).
     *
     * Individuals with no PLAC sub-tag in their record are silently excluded —
     * they would skew the average toward zero without meaningfully
     * participating in the "how many places does each documented person carry"
     * question.
     */
    public function dispersionSummary(): PlaceDispersionSummary
    {
        $rows = TreeScope::individualGedcoms($this->tree);

        $distribution = $this->initDistribution();
        $totalPlaces  = 0;
        $sampled      = 0;

        foreach ($rows as $row) {
            $gedcom = RowCast::string($row, 'gedcom');

            $distinct = $this->distinctPlaceCount($gedcom);

            if ($distinct === 0) {
                continue;
            }

            ++$sampled;
            $totalPlaces += $distinct;

            $bucket                = $distinct >= self::DISTRIBUTION_MAX ? self::DISTRIBUTION_MAX . '+' : (string) $distinct;
            $distribution[$bucket] = ($distribution[$bucket] ?? 0) + 1;
        }

        $average = $sampled === 0 ? 0.0 : round($totalPlaces / $sampled, 2);

        return new PlaceDispersionSummary(
            average: $average,
            sampled: $sampled,
            distribution: $distribution,
        );
    }

    /**
     * Count distinct `2 PLAC <value>` sub-tag values across the record. Values
     * are trimmed and compared case-sensitively so `Berlin, Germany` and
     * `Berlin, germany` count as two — a choice that biases toward "more
     * places" but stays simple and predictable; ISO-folding the place names is
     * a separate concern handled by IsoCountryMap.
     */
    private function distinctPlaceCount(string $gedcom): int
    {
        if (preg_match_all('/\n2 PLAC +([^\n]+)/', $gedcom, $matches) === 0) {
            return 0;
        }

        $values = [];

        foreach ($matches[1] as $raw) {
            $value = trim($raw);

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return count(array_unique($values));
    }

    /**
     * Pre-seed every distribution bucket so the histogram renders a continuous
     * 1..5+ x-axis even on trees where some buckets carry zero contributions.
     *
     * @return array<array-key, int>
     */
    private function initDistribution(): array
    {
        $buckets = [];

        for ($i = 1; $i < self::DISTRIBUTION_MAX; ++$i) {
            $buckets[(string) $i] = 0;
        }

        $buckets[self::DISTRIBUTION_MAX . '+'] = 0;

        return $buckets;
    }
}
