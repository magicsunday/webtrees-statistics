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
use MagicSunday\Webtrees\Statistic\Model\Dto\Metric\EndogamyRate;
use MagicSunday\Webtrees\Statistic\Support\Endogamy;
use MagicSunday\Webtrees\Statistic\Support\TreeScope;

use function is_string;
use function round;

/**
 * Endogamy / cousin-marriage rate for the Family tab. For every
 * family where both spouses have at least one recorded parentage
 * link, walk each spouse's ancestor set up to
 * {@see self::DEFAULT_DEPTH} generations and intersect. A non-empty
 * intersection means the couple share a common ancestor — the
 * classic signature of cousin marriage and pedigree collapse.
 *
 * The metric is "share of testable couples", not "share of all
 * marriages": couples where one spouse has no FAMC link cannot be
 * tested for endogamy and would distort the rate toward zero if
 * counted as exogamous. They are excluded from both numerator and
 * denominator.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class EndogamyRepository
{
    /**
     * How many ancestor generations to scan per spouse. Four covers
     * parents through great-great-grandparents (up to 30 ancestor
     * slots per side) — deep enough to catch most pedigree-collapse
     * signal without ballooning into O(2^N) per-couple costs.
     */
    public const int DEFAULT_DEPTH = 4;

    /**
     * @param Tree                $tree                The tree the statistics are computed for
     * @param ParentMapRepository $parentMapRepository Shared parent-of map provider (FAMC + FAM scan)
     */
    public function __construct(
        private Tree $tree,
        private ParentMapRepository $parentMapRepository,
    ) {
    }

    /**
     * Endogamy summary across the entire tree: total testable couples
     * (both spouses have at least one parent recorded), count of
     * couples sharing ≥1 common ancestor within `$depth` generations,
     * the percentage, and the depth used so the view can label the
     * caveat ("within four generations"). Returns null when no
     * testable couple exists.
     */
    public function summary(int $depth = self::DEFAULT_DEPTH): ?EndogamyRate
    {
        $parentOf   = $this->parentMapRepository->build();
        $total      = 0;
        $endogamous = 0;

        // ancestor-set memo across the couple loop. An individual
        // recorded as spouse in two marriages (remarriage, second
        // family) would otherwise have their 4-generation BFS run
        // twice; on a 2,000-person tree with second marriages this
        // alone halved the per-couple walk count.
        $ancestorSetCache = [];

        $rows = TreeScope::table($this->tree, 'families')
            ->select(['f_husb', 'f_wife'])
            ->get();

        foreach ($rows as $row) {
            $husb = is_string($row->f_husb ?? null) && ($row->f_husb !== '') ? $row->f_husb : null;
            $wife = is_string($row->f_wife ?? null) && ($row->f_wife !== '') ? $row->f_wife : null;

            if ($husb === null) {
                continue;
            }

            if ($wife === null) {
                continue;
            }

            if (!isset($parentOf[$husb])) {
                continue;
            }

            if (!isset($parentOf[$wife])) {
                continue;
            }

            ++$total;

            $husbAncestors = $ancestorSetCache[$husb] ??= Endogamy::ancestorSet($parentOf, $husb, $depth);
            $wifeAncestors = $ancestorSetCache[$wife] ??= Endogamy::ancestorSet($parentOf, $wife, $depth);

            if (array_intersect_key($husbAncestors, $wifeAncestors) !== []) {
                ++$endogamous;
            }
        }

        if ($total === 0) {
            return null;
        }

        return new EndogamyRate(
            total: $total,
            endogamous: $endogamous,
            rate: round(($endogamous / $total) * 100, 1),
            depth: $depth,
        );
    }
}
