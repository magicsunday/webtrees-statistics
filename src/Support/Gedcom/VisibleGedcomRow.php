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
use function explode;
use function preg_match;
use function preg_match_all;
use function strstr;

use const PREG_SET_ORDER;

/**
 * One individual row reduced to the facts that the current viewer may see. The
 * `gedcom` text is the header line of the record followed by the raw text of
 * the facts that `Individual::facts()` returns for the tags of the row that
 * occur at least once with a value other than a pointer. A `1 BAPM` without a
 * value counts, because it can hold `2 RELI` lines. The GEDCOM scanners run on
 * that text, so they see only what the host shows to the viewer. The host
 * applies the record visibility, an explicit `RESN` on the fact and the tree and
 * individual fact privacy defaults there.
 *
 * A tag that occurs only as pointer facts (`1 FAMC @F1@`) is not passed to the
 * host, which spares resolving the targets. When a tag occurs as a pointer and
 * with another value, the host resolves the pointer target and the pointer fact
 * is dropped afterwards. A value is a pointer when it is exactly an XREF
 * between at signs of the class the host uses for record references, with no
 * surrounding whitespace, so an escaped free text value such as `@@Home` is
 * kept. A row that the host rejects as facts, for example a fact
 * line without a valid tag, yields the header line alone and contributes no
 * values.
 *
 * The record is built from the committed row text without pending changes and
 * is not registered in the record factory cache.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class VisibleGedcomRow
{
    /**
     * Pattern of a pointer value, the XREF class that the host uses in
     * `Fact::target()` between at signs. An escaped value such as `@@x@`, a value
     * with spaces such as `@Home town@` and a calendar escape such as
     * `@#DJULIAN@` do not match.
     */
    private const string POINTER_VALUE = '@[A-Za-z0-9:_.-]{1,20}@';

    /**
     * @param string $gedcom The header line plus the raw text of the facts the viewer may see
     */
    public function __construct(
        public string $gedcom,
    ) {
    }

    /**
     * Reduce one individual row to the facts the current viewer may see.
     *
     * @param Tree   $tree   The tree the individual belongs to
     * @param string $xref   The individual's XREF
     * @param string $gedcom The raw `i_gedcom` text of the row
     *
     * @return string The header line plus the raw text of the visible non-pointer facts
     */
    public static function text(Tree $tree, string $xref, string $gedcom): string
    {
        $header = strstr($gedcom, "\n", true);
        $header = ($header === false) ? $gedcom : $header;

        $tags = self::valueFactTags($gedcom);

        // Without a non-pointer fact there is nothing to scan, and an empty tag list
        // would select every fact in the host call below.
        if ($tags === []) {
            return $header;
        }

        try {
            $individual = Registry::individualFactory()->new($xref, $gedcom, null, $tree);

            $visible = $header;

            foreach ($individual->facts($tags) as $fact) {
                $factGedcom = $fact->gedcom();

                // A tag can occur as a pointer and with an inline value, so the
                // pointer facts are dropped per fact and not per tag.
                if (self::hasPointerValue($factGedcom)) {
                    continue;
                }

                $visible .= "\n" . $factGedcom;
            }
        } catch (InvalidArgumentException) {
            return $header;
        }

        return $visible;
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
            static fn (object $row): self => new self(
                self::text(
                    $tree,
                    RowCast::string($row, 'xref'),
                    RowCast::string($row, 'gedcom'),
                ),
            ),
        )->values();
    }

    /**
     * The tags of the level-1 facts that occur at least once with a value other
     * than a pointer or without a value. A tag that occurs only as a pointer is
     * left out, so the host does not resolve the targets of such facts to decide
     * on their visibility.
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
            if (preg_match('/^' . self::POINTER_VALUE . '$/', $match[2] ?? '') === 1) {
                continue;
            }

            $tags[$match[1]] = $match[1];
        }

        return array_values($tags);
    }

    /**
     * Whether the first line of a fact carries a real pointer as its value.
     *
     * @param string $factGedcom The raw text of one level-1 fact
     */
    private static function hasPointerValue(string $factGedcom): bool
    {
        $firstLine = explode("\n", $factGedcom, 2)[0];

        return preg_match('/^1 [A-Z0-9_]+ ' . self::POINTER_VALUE . '$/', $firstLine) === 1;
    }
}
