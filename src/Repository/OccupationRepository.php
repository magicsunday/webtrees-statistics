<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Repository;

use Closure;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;
use MagicSunday\Webtrees\Statistic\Normalization\Contract\OccupationNormalizerInterface;
use MagicSunday\Webtrees\Statistic\Normalization\OccupationFolding;
use MagicSunday\Webtrees\Statistic\Support\Database\TreeScope;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\GedcomScanner;

use function array_keys;
use function is_string;
use function mb_strtolower;

/**
 * Top-N aggregation over the `1 OCCU` (occupation) facts attached to
 * individuals. Multiple OCCU lines per INDI all contribute.
 *
 * Unlike the other Top-N counters, the occupation values are folded through an
 * {@see OccupationNormalizerInterface} before counting: when a standardization provider
 * is installed, spelling and language variants of one trade collapse under a
 * single grouping key instead of fragmenting into separate bars. The identity
 * default resolves nothing, so without a provider the aggregation folds by case
 * alone — byte-identical to the plain Top-N behaviour.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class OccupationRepository extends AbstractGedcomTagTopNRepository
{
    /**
     * @param Tree                          $tree       The tree the statistics are computed for
     * @param OccupationNormalizerInterface $normalizer Resolves raw `1 OCCU` values to standardized trades so variants merge; the identity default leaves the aggregation unchanged
     */
    public function __construct(
        Tree $tree,
        private readonly OccupationNormalizerInterface $normalizer,
    ) {
        parent::__construct($tree);
    }

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

    /**
     * Resolve every distinct occupation once through the normalizer, then fold
     * each counted value under its grouping key and display label. Batching over
     * the whole tree here means a standardization provider initialises its data
     * a single time rather than once per counted line.
     *
     * @param Collection<int, object> $gedcoms The individual GEDCOM rows about to be counted
     *
     * @return Closure(string): array{0: string, 1: string}
     */
    protected function foldValue(Collection $gedcoms): Closure
    {
        $distinctRaw = [];

        foreach ($gedcoms as $row) {
            $gedcom = (isset($row->gedcom) && is_string($row->gedcom)) ? $row->gedcom : '';

            foreach ($this->extract($gedcom) as $value) {
                $distinctRaw[$value] = true;
            }
        }

        $folds = OccupationFolding::map(array_keys($distinctRaw), $this->normalizer, TreeScope::languageTag($this->tree()));

        return static fn (string $value): array => $folds[$value] ?? [mb_strtolower($value), $value];
    }
}
