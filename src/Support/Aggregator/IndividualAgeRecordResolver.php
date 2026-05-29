<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Aggregator;

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Record\IndividualAgeRecord;

/**
 * Resolves a `{xref, ageYears}` candidate picked by a Hall-of-Fame
 * record-holder query into the typed {@see IndividualAgeRecord} DTO. Returns
 * null when either input is missing or the xref can no longer be materialised
 * into a live `Individual` (e.g. the row pointed at a deleted record).
 *
 * Kept in the Support layer so the DTO itself stays free of service-location
 * and remains a pure value carrier. Parenthood, Marriage, and other
 * repositories that walk a tree-scoped pair-iterator and pick the youngest /
 * oldest candidate share a single resolver instead of inlining the same
 * Registry lookup + instanceof guard at every record method.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class IndividualAgeRecordResolver
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Materialise the candidate xref against the given tree and wrap it into
     * the DTO together with the picked age. Either `$xref` being null, `$years`
     * being null, or the xref no longer resolving to a live Individual
     * collapses to null.
     */
    public static function resolve(Tree $tree, ?string $xref, ?int $years): ?IndividualAgeRecord
    {
        if (($xref === null) || ($years === null)) {
            return null;
        }

        $individual = Registry::individualFactory()->make($xref, $tree);

        if (!$individual instanceof Individual) {
            return null;
        }

        return new IndividualAgeRecord(individual: $individual, ageYears: $years);
    }
}
