<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Family;

/**
 * One calendar year in which at least one family lost several children. Carries
 * the year, the total number of those siblings summed across every qualifying
 * family that year, and how many families contributed — so a year driven by a
 * single large family reads differently from one where several families lost
 * children at once. A plain aggregate value object; it names no individual and
 * no family, so it exposes nothing webtrees' privacy filtering would withhold.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class SiblingDeathCluster
{
    /**
     * @param int $year     The calendar year of the cluster
     * @param int $siblings Total qualifying siblings that year, summed across all contributing families
     * @param int $families Number of families that lost at least the threshold of children this year
     */
    public function __construct(
        public int $year,
        public int $siblings,
        public int $families,
    ) {
    }
}
