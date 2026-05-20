<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use function explode;
use function implode;
use function preg_match;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/**
 * Reusable raw-GEDCOM helpers. Repositories that scan individual or
 * family records (marital classification, data-quality metrics, future
 * name / place aggregators) share the same anchoring rules: level-1 tags
 * must be terminated by space, newline, or end-of-string so substring
 * matches like `1 DIV` vs `1 DIVF` cannot collide.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class GedcomScanner
{
    /**
     * True when the GEDCOM blob contains `\n1 <tag>` for any tag in the
     * list, anchored so substring tags (e.g. `DIV` vs `DIVF`) cannot
     * collide.
     *
     * @param string             $gedcom Raw GEDCOM record body
     * @param array<int, string> $tags   Level-1 tags to test for (e.g. ['BIRT'], ['DIV','ANUL'])
     */
    public static function hasAnyTagAnchored(string $gedcom, array $tags): bool
    {
        foreach ($tags as $tag) {
            if (self::hasTagAnchored($gedcom, $tag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when the GEDCOM blob contains a single `\n1 <tag>` line
     * anchored by space, newline, or end-of-string.
     *
     * @param string $gedcom Raw GEDCOM record body
     * @param string $tag    Level-1 tag to test for (e.g. 'BIRT')
     */
    public static function hasTagAnchored(string $gedcom, string $tag): bool
    {
        $prefix = "\n1 " . $tag;

        return str_contains($gedcom, $prefix . ' ')
            || str_contains($gedcom, $prefix . "\n")
            || str_ends_with($gedcom, $prefix);
    }

    /**
     * True when the GEDCOM blob carries the given level-1 event AND its
     * sub-block contains a non-empty `2 PLAC` sub-line. Lines past the
     * next level-0 / level-1 boundary are ignored so a later event's
     * PLAC cannot satisfy an earlier event's missing-place check; an
     * empty `2 PLAC` (no place name after the tag) is treated as no
     * place at all.
     *
     * @param string $gedcom Raw GEDCOM record body
     * @param string $tag    Level-1 event tag whose place sub-line to look for
     */
    public static function hasEventPlace(string $gedcom, string $tag): bool
    {
        $block = self::eventBlock($gedcom, $tag);

        if ($block === null) {
            return false;
        }

        // Require a non-whitespace character on the same physical line
        // as the `2 PLAC` tag. `\s` includes `\n`, so a bare `2 PLAC`
        // followed by `3 NOTE …` would otherwise be reported as "place
        // present" — that's the empty-place case we want to reject.
        return preg_match('/\n2 PLAC +\S/', $block) === 1;
    }

    /**
     * Pull a four-digit year out of the first `2 DATE` sub-line of the
     * given event block. Range markers (`BEF`, `AFT`, `ABT`, `EST`,
     * `CAL`, `INT`) are stripped before the year capture so the first
     * concrete `\d{4}` token wins; `BET 1900 AND 1910` returns 1900,
     * `FROM 1900 TO 1910` also returns 1900.
     *
     * @param string $gedcom Raw GEDCOM record body
     * @param string $tag    Level-1 event tag whose first sub-date to read
     */
    public static function extractEventYear(string $gedcom, string $tag): ?int
    {
        $block = self::eventBlock($gedcom, $tag);

        if ($block === null) {
            return null;
        }

        if (preg_match('/\n2 DATE\s+([^\n]+)/', $block, $dateMatch) !== 1) {
            return null;
        }

        if (preg_match('/\b(\d{4})\b/', $dateMatch[1], $yearMatch) !== 1) {
            return null;
        }

        return (int) $yearMatch[1];
    }

    /**
     * Extract the GEDCOM sub-block belonging to a given level-1 event
     * (everything from `\n1 <tag>` up to the next level-0 / level-1
     * line). Returns null when the event is not present.
     *
     * @param string $gedcom Raw GEDCOM record body
     * @param string $tag    Level-1 tag whose block to extract
     */
    private static function eventBlock(string $gedcom, string $tag): ?string
    {
        $needle = "\n1 " . $tag;
        $start  = strpos($gedcom, $needle);

        if ($start === false) {
            return null;
        }

        // Confirm the match terminates at a line boundary (avoid `1 BIRT` matching `1 BIRTHHACK`).
        $afterTag = substr($gedcom, $start + strlen($needle), 1);

        if (!in_array($afterTag, ['', ' ', "\n"], true)) {
            return null;
        }

        $rest  = substr($gedcom, $start + 1);
        $cutAt = self::findNextLevelOneOrZero($rest, 1);

        return ($cutAt === null) ? $rest : substr($rest, 0, $cutAt);
    }

    /**
     * Locate the next line at level 0 or 1 within the GEDCOM substring,
     * starting from a given line offset (1 = skip the opening line of
     * the parent event). Returns the absolute byte position of the
     * matching newline, or null if no level-0/1 line follows.
     *
     * @param string $block  GEDCOM substring to scan
     * @param int    $offset Number of leading lines to skip
     */
    private static function findNextLevelOneOrZero(string $block, int $offset): ?int
    {
        $lines  = explode("\n", $block);
        $cursor = 0;

        foreach ($lines as $index => $line) {
            if (($index >= $offset) && (str_starts_with($line, '0 ') || str_starts_with($line, '1 '))) {
                return $cursor;
            }

            $cursor += strlen($line) + 1;
        }

        return null;
    }

    /**
     * Build an OR-joined LIKE SQL fragment with the same anchoring as
     * {@see hasTagAnchored()} for use in SQL counts ("how many
     * individuals carry / lack this tag"). Each tag yields three
     * alternatives (`%\n1 <tag> %`, `%\n1 <tag>\n%`, `%\n1 <tag>`
     * suffix).
     *
     * @param string             $column Fully-qualified column reference, e.g. `individuals.i_gedcom`
     * @param array<int, string> $tags   Level-1 tags to test for
     */
    public static function orLikeAnchoredSql(string $column, array $tags): string
    {
        $clauses = [];

        foreach ($tags as $tag) {
            $clauses[] = $column . " LIKE '%\\n1 " . $tag . " %'";
            $clauses[] = $column . " LIKE '%\\n1 " . $tag . "\\n%'";
            $clauses[] = $column . " LIKE '%\\n1 " . $tag . "'";
        }

        return implode(' OR ', $clauses);
    }

    /**
     * Build a list of single-tag LIKE patterns for use with a Query
     * Builder's `where(..., 'LIKE', $pattern)`. Mirrors the anchoring
     * of {@see hasTagAnchored()} so SQL and PHP scans agree.
     *
     * @param string $tag Level-1 tag to anchor
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function anchoredLikePatterns(string $tag): array
    {
        return [
            "%\n1 " . $tag . ' %',
            "%\n1 " . $tag . "\n%",
            "%\n1 " . $tag,
        ];
    }
}
