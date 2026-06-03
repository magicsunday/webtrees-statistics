<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Model\Metric;

use JsonSerializable;

use function round;

/**
 * Record-type inventory for the Tree-health tab: how many core records
 * (individuals, families) the tree holds versus how many enrichment records
 * (sources, media objects, notes, shared notes, repositories, shared
 * locations). `enrichmentDensity()` expresses the enrichment total per 100
 * individuals — the signal that tells a bare person/family tree from a
 * well-sourced one.
 *
 * Counts are i18n-free; the view maps each field to a translated label at the
 * call site. Serialises to a flat `{type => count}` map plus
 * `enrichmentDensity`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class RecordInventory implements JsonSerializable
{
    /**
     * @param int $individuals  Core: INDI records
     * @param int $families     Core: FAM records
     * @param int $sources      Enrichment: SOUR records
     * @param int $media        Enrichment: OBJE records
     * @param int $notes        Enrichment: top-level NOTE records
     * @param int $sharedNotes  Enrichment: SNOTE (shared note) records
     * @param int $repositories Enrichment: REPO records
     * @param int $locations    Enrichment: _LOC (shared location) records
     */
    public function __construct(
        public int $individuals,
        public int $families,
        public int $sources,
        public int $media,
        public int $notes,
        public int $sharedNotes,
        public int $repositories,
        public int $locations,
    ) {
    }

    /**
     * Total enrichment records (everything that is not a core person/family
     * record). Internal to the density calculation.
     */
    private function enrichmentTotal(): int
    {
        return $this->sources
            + $this->media
            + $this->notes
            + $this->sharedNotes
            + $this->repositories
            + $this->locations;
    }

    /**
     * Enrichment records per 100 individuals, rounded to a whole number.
     * Returns 0 when the tree has no individuals.
     */
    public function enrichmentDensity(): int
    {
        if ($this->individuals <= 0) {
            return 0;
        }

        return (int) round(($this->enrichmentTotal() / $this->individuals) * 100);
    }

    /**
     * @return array{individuals: int, families: int, sources: int, media: int, notes: int, sharedNotes: int, repositories: int, locations: int, enrichmentDensity: int}
     */
    public function jsonSerialize(): array
    {
        return [
            'individuals'       => $this->individuals,
            'families'          => $this->families,
            'sources'           => $this->sources,
            'media'             => $this->media,
            'notes'             => $this->notes,
            'sharedNotes'       => $this->sharedNotes,
            'repositories'      => $this->repositories,
            'locations'         => $this->locations,
            'enrichmentDensity' => $this->enrichmentDensity(),
        ];
    }
}
