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
 * Top-N aggregation over the `1 OCCU` (occupation) facts attached to
 * individuals. Multiple OCCU lines per INDI all contribute.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class OccupationRepository extends AbstractGedcomTagTopNRepository
{
    /**
     * Harvests every top-level `1 OCCU` line from the INDI record. An
     * individual carrying two recorded occupations contributes two entries to
     * the frequency rollup.
     *
     * @param string $gedcom The raw INDI GEDCOM record to scan
     *
     * @return list<string>
     */
    protected function extract(string $gedcom): array
    {
        return GedcomScanner::extractAllTagValues($gedcom, 'OCCU');
    }
}
