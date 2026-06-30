<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Sankey;

use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use MagicSunday\Webtrees\Statistic\Model\Sankey\SankeySample;
use MagicSunday\Webtrees\Statistic\Support\Gedcom\RecordName;

/**
 * Resolves a Sankey hover sample through the webtrees record factory so the
 * surfaced name passes the same privacy layer as the rest of the UI. Both
 * Sankey aggregators (birth → death migration, parent → child occupation
 * inheritance) used to read the raw `1 NAME` GEDCOM line, which bypassed
 * `Individual::canShow()` and `fullName()` entirely and could surface a name
 * webtrees would otherwise privatise (an explicit `1 RESN` restriction or a
 * tree-level privacy rule). Routing both aggregators through this helper keeps
 * the factory lookup, the visibility gate, and the plain-text reduction in one
 * place.
 *
 * The caller skips a `null` result and lets the next deterministic contributor
 * fill the sample slot: the flow weight is counted independently of the
 * samples, so dropping a private sample does not distort the aggregate.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class SankeySampleResolver
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Resolve the individual behind a flow contribution into a privacy-safe
     * sample. Returns `null` when the record cannot be made or the current user
     * is not allowed to see it, so the caller drops the sample and promotes the
     * next deterministic contributor in its place.
     *
     * The already-loaded row GEDCOM is forwarded to the factory so the lookup
     * reuses the body the caller scanned instead of issuing a second per-record
     * query — the factory only falls back to a database fetch when no GEDCOM is
     * supplied.
     *
     * @param Tree   $tree   The tree the individual belongs to
     * @param string $xref   The contributing individual's XREF
     * @param string $gedcom The individual's raw GEDCOM, already loaded by the caller
     */
    public static function resolve(Tree $tree, string $xref, string $gedcom): ?SankeySample
    {
        $individual = Registry::individualFactory()->make($xref, $tree, $gedcom);

        if (!$individual instanceof Individual) {
            return null;
        }

        if (!$individual->canShow()) {
            return null;
        }

        return new SankeySample(
            name: RecordName::plain($individual->fullName()),
            xref: $xref,
        );
    }
}
