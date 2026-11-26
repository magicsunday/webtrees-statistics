<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Gedcom;

use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;

/**
 * Whether the current viewer may see a family record. The host decides it from
 * the restriction of the record, the record privacy defaults of the tree and the
 * visibility of every member of the family.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class VisibleFamily
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Whether the current viewer may see the family of a row.
     *
     * @param Tree   $tree   The tree the family belongs to
     * @param string $xref   The XREF of the family
     * @param string $gedcom The raw `f_gedcom` text of the row
     *
     * @return bool True when the viewer may see the family record
     */
    public static function isShown(Tree $tree, string $xref, string $gedcom): bool
    {
        $family = Registry::familyFactory()->make($xref, $tree, $gedcom);

        return ($family instanceof Family) && $family->canShow();
    }
}
