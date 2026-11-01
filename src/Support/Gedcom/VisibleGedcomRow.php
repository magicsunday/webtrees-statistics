<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Gedcom;

use Fisharebest\Webtrees\Registry;
use Fisharebest\Webtrees\Tree;
use Illuminate\Support\Collection;
use InvalidArgumentException;

use function array_values;
use function preg_match_all;
use function str_starts_with;
use function strstr;

/**
 * One individual row reduced to the facts that the current viewer may see. The
 * `gedcom` text is the header line of the record followed by the raw text of
 * every value fact that `Individual::facts()` returns, so the GEDCOM scanners
 * select values the same way as on the raw row but only over what the host
 * shows to the viewer. A fact whose value points to another record is left out,
 * which spares the host from resolving its target. The host applies the record visibility, an explicit `RESN` on the fact
 * and the tree and individual fact privacy defaults there.
 *
 * The record is built from the committed row text without pending changes and
 * is not registered in the record factory cache, so it cannot be bound to a
 * stale tree object. A row that the host cannot parse as facts yields the header
 * line alone and contributes no values.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class VisibleGedcomRow
{
    /**
     * @param string $xref   The individual's XREF
     * @param string $gedcom The header line plus the raw text of the facts the viewer may see
     */
    public function __construct(
        public string $xref,
        public string $gedcom,
    ) {
    }

    /**
     * Reduce one individual row to the facts the current viewer may see.
     *
     * @param Tree   $tree   The tree the individual belongs to
     * @param string $xref   The individual's XREF
     * @param string $gedcom The raw `i_gedcom` text of the row
     */
    public static function of(Tree $tree, string $xref, string $gedcom): self
    {
        $header = strstr($gedcom, "\n", true);
        $header = ($header === false) ? $gedcom : $header;

        $tags = self::valueFactTags($gedcom);

        // Without a value fact there is nothing to scan, and an empty tag list
        // would select every fact in the host call below.
        if ($tags === []) {
            return new self($xref, $header);
        }

        try {
            $individual = Registry::individualFactory()->new($xref, $gedcom, null, $tree);

            $visible = $header;

            foreach ($individual->facts($tags) as $fact) {
                $visible .= "\n" . $fact->gedcom();
            }
        } catch (InvalidArgumentException) {
            return new self($xref, $header);
        }

        return new self($xref, $visible);
    }

    /**
     * The tags of the level-1 facts that carry a value. A fact whose value is a
     * pointer to another record (`1 FAMC @F1@`, `1 SOUR @S1@`) is left out, so
     * the host does not resolve its target to decide on its visibility, and the
     * text of such a fact is not part of the visible text.
     *
     * @param string $gedcom The raw `i_gedcom` text of the row
     *
     * @return list<string>
     */
    private static function valueFactTags(string $gedcom): array
    {
        if (preg_match_all('/^1 ([A-Z0-9_]+)(?: ([^\n]*))?$/m', $gedcom, $matches, PREG_SET_ORDER) === 0) {
            return [];
        }

        $tags = [];

        foreach ($matches as $match) {
            $value = $match[2] ?? '';

            if (str_starts_with($value, '@')) {
                continue;
            }

            $tags[$match[1]] = $match[1];
        }

        return array_values($tags);
    }

    /**
     * Reduce every loaded individual row to its visible facts.
     *
     * @param Tree                    $tree The tree the rows belong to
     * @param Collection<int, object> $rows Rows carrying the `xref` and `gedcom` columns of {@see \MagicSunday\Webtrees\Statistic\Support\Database\TreeScope::individualGedcoms()}
     *
     * @return Collection<int, self>
     */
    public static function collect(Tree $tree, Collection $rows): Collection
    {
        return $rows->map(
            static fn (object $row): self => self::of(
                $tree,
                RowCast::string($row, 'xref'),
                RowCast::string($row, 'gedcom'),
            ),
        )->values();
    }
}
