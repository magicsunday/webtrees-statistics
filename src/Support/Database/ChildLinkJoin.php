<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support\Database;

use Illuminate\Database\Query\JoinClause;

/**
 * Pure helper for the recurring `link` table join that reaches a family's
 * children through their indexed `FAMC` pointers (`link.l_to` = family,
 * `link.l_from` = child). Several repository queries inlined the same
 * three-condition block — file column join, family-id join, and the
 * `l_type = 'FAMC'` filter — before joining the child `individuals` row with
 * their own per-query filter (sex, birth-date join). Consolidating the FAMC
 * link join into one helper keeps the column convention and condition order
 * consistent across every consumer; the child-side filter stays at each call
 * site because it varies (a sex equality, a sex `whereIn`, a date join).
 *
 * The aliases are fixed by convention: every consumer drives the query off
 * `families` aliased `fam` and joins `link AS famc`, then reads the child
 * through `famc.l_from`. The helper therefore hard-codes those aliases rather
 * than parameterising values that never differ.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class ChildLinkJoin
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Attach the standard three conditions to the `link AS famc` join that
     * resolves a `fam`-aliased family's children: file column equality,
     * family-id equality (`famc.l_to` = `fam.f_id`), and the `FAMC` type filter.
     * The caller then joins `individuals` on `famc.l_from` with whatever
     * child-side filter it needs.
     *
     * @param JoinClause $join The join clause to mutate in place
     */
    public static function famc(JoinClause $join): void
    {
        $join
            ->on('famc.l_file', '=', 'fam.f_file')
            ->on('famc.l_to', '=', 'fam.f_id')
            ->where('famc.l_type', '=', 'FAMC');
    }
}
