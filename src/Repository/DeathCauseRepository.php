<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;

/**
 * Top-N aggregation over the `2 CAUS` sub-tag within each individual's `1 DEAT`
 * block — the GEDCOM cause-of-death field. Each INDI contributes at most one
 * value (only one death per person). The extract closure projects the optional
 * single value into a list so it slots into the same harvester shape the base
 * class expects.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class DeathCauseRepository extends AbstractGedcomTagTopNRepository
{
    /**
     * Harvests the optional `2 CAUS` sub-tag under the `1 DEAT` event of the
     * INDI record. Records without DEAT or without a CAUS sub-tag contribute
     * nothing (empty list); the projection to a single-element list keeps the
     * harvester shape uniform with the multi-tag siblings.
     *
     * @param string $gedcom The raw INDI GEDCOM record to scan
     *
     * @return list<string>
     */
    protected function extract(string $gedcom): array
    {
        $cause = GedcomScanner::extractEventSubValue($gedcom, 'DEAT', 'CAUS');

        return $cause === null ? [] : [$cause];
    }
}
