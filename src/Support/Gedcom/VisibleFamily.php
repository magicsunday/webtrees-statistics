<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Gedcom;

use Fisharebest\Webtrees\Auth;
use Fisharebest\Webtrees\Contracts\UserInterface;
use Fisharebest\Webtrees\Factories\CacheFactory;
use Fisharebest\Webtrees\Family;
use Fisharebest\Webtrees\GedcomRecord;
use Fisharebest\Webtrees\Individual;
use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;

use function preg_match;
use function preg_quote;

/**
 * The privacy decisions about families and about the links between families and
 * people, for the current viewer. A family record is decided by the host. A link
 * that carries a restriction the viewer does not pass is hidden here whatever the
 * tree preference for private relationships says.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class VisibleFamily
{
    /**
     * Number of couples or children that a caller checks within one
     * {@see withinScope()} call. It bounds the records that stay in memory at once,
     * except for a viewer to whom relationship privacy applies.
     */
    public const int CHUNK_SIZE = 250;

    /**
     * Prevent instantiation, this is a static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Run a lookup with a record cache of its own and restore the cache of the
     * request afterwards. The host keeps every record that it resolves, together
     * with the members of a family, in the cache of the request, so resolving the
     * families of a large tree there exhausts the memory limit.
     *
     * A viewer to whom relationship privacy applies runs the lookup in the cache of
     * the request, because the host compares the records of that rule by object
     * identity and a record of another cache is a different object.
     *
     * @template T
     *
     * @param Tree          $tree   The tree the lookup reads
     * @param callable(): T $lookup The lookup to run
     *
     * @return T The result of the lookup
     */
    public static function withinScope(Tree $tree, callable $lookup)
    {
        if (self::usesRelationshipPrivacy($tree)) {
            return $lookup();
        }

        $requestCache = Registry::cache();

        Registry::cache(new CacheFactory());

        try {
            return $lookup();
        } finally {
            Registry::cache($requestCache);
        }
    }

    /**
     * Whether the host limits what the current viewer sees by the relationship to
     * the own record. It does so only when the tree hides living people, the viewer
     * is below a manager and the account has a linked record and a path length.
     *
     * @param Tree $tree The tree
     *
     * @return bool True when the relationship rule applies to the viewer
     */
    private static function usesRelationshipPrivacy(Tree $tree): bool
    {
        if (!(bool) $tree->getPreference('HIDE_LIVE_PEOPLE') || Auth::isManager($tree)) {
            return false;
        }

        return ($tree->getUserPreference(Auth::user(), UserInterface::PREF_TREE_ACCOUNT_XREF) !== '')
            && ((int) $tree->getUserPreference(Auth::user(), UserInterface::PREF_TREE_PATH_LENGTH) > 0);
    }

    /**
     * The family of a row, or null when the viewer may not see the record.
     *
     * @param Tree   $tree   The tree the family belongs to
     * @param string $xref   The XREF of the family
     * @param string $gedcom The raw `f_gedcom` text of the row
     *
     * @return Family|null The family that the viewer may see
     */
    public static function find(Tree $tree, string $xref, string $gedcom): ?Family
    {
        $family = Registry::familyFactory()->make($xref, $tree, $gedcom);

        return (($family instanceof Family) && $family->canShow()) ? $family : null;
    }

    /**
     * Whether the viewer may see a person as a spouse of a family. Neither the
     * spouse link of the family nor the family link of the person is restricted.
     *
     * @param Family $family The family
     * @param string $tag    `HUSB` or `WIFE`
     * @param string $xref   The XREF of the spouse
     *
     * @return bool True when no restriction hides the relation
     */
    public static function showsSpouse(Family $family, string $tag, string $xref): bool
    {
        if (!self::showsLink($family, $tag, $xref)) {
            return false;
        }

        $individual = Registry::individualFactory()->make($xref, $family->tree());

        return (!$individual instanceof Individual) || self::showsLink($individual, 'FAMS', $family->xref());
    }

    /**
     * Whether a link from one record to another is visible. A line links the target
     * when its value starts with the pointer, in any letter case, as the import of
     * the host reads it. A link that the record does not hold at all is not
     * restricted.
     *
     * @param GedcomRecord $record The record that holds the link
     * @param string       $tag    The level one tag of the link
     * @param string       $target The XREF the link points at
     *
     * @return bool False when every such link is restricted for the viewer
     */
    public static function showsLink(GedcomRecord $record, string $tag, string $target): bool
    {
        // The record itself was checked by the caller or is not relevant to the
        // link, so all facts are read and each one is checked for the viewer.
        $pointer = '/^@' . preg_quote($target, '/') . '@/i';
        $found   = false;

        foreach ($record->facts([$tag], false, Auth::PRIV_HIDE) as $fact) {
            if (preg_match($pointer, $fact->value()) !== 1) {
                continue;
            }

            if ($fact->canShow()) {
                return true;
            }

            $found = true;
        }

        return !$found;
    }
}
