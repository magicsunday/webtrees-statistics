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
 * Top-N aggregation over the religion / confession affiliations recorded on
 * individuals. The GEDCOM 5.5.1 + FamilySearch 7.0 spec stores religious
 * affiliation in TWO places:
 *
 *   1. `1 RELI <value>` — top-level individual attribute (the
 *      person's declared religion).
 *   2. `2 RELI <value>` — sub-tag under any religious event
 *      (BAPM / CHR / CHRA / CONF / FCOM / BARM / BASM / BLES /
 *      ORDN), naming the affiliation under which the event took
 *      place. In many real-world trees this is where the bulk of
 *      the data lives (e.g. trees with church-book imports rarely
 *      carry a separate `1 RELI` line, only the event-bound one).
 *
 * Both sources contribute to the same aggregation. Multi-occurrence per INDI is
 * preserved.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class ReligionRepository extends AbstractGedcomTagTopNRepository
{
    /**
     * Harvests BOTH top-level `1 RELI` lines and event-bound `2 RELI` sub-tags
     * from the INDI record. The two sources are merged into one flat list so
     * the case-folded frequency rollup treats them as the same fact.
     *
     * @param string $gedcom The raw INDI GEDCOM record to scan
     *
     * @return list<string>
     */
    protected function extract(string $gedcom): array
    {
        return [
            ...GedcomScanner::extractAllTagValues($gedcom, 'RELI'),
            ...GedcomScanner::extractAllSubTagValues($gedcom, 'RELI'),
        ];
    }
}
