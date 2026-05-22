<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Support;

use function get_object_vars;
use function is_numeric;
use function is_string;

/**
 * Pure helper that defends against the loosely-typed `stdClass`
 * rows Eloquent hands back from `DB::table(...)->get()`. The
 * repository code previously inlined
 * `is_numeric($row->col ?? null) ? (int) $row->col : 0` and
 * `is_string($row->col ?? null) ? $row->col : ''` at 40+ sites
 * — every Read after a query — and the wider the surface grew the
 * easier it became to drop a guard or to fat-finger a default.
 * Routing every cast through this helper keeps the defaults
 * uniform, makes a future schema-column-rename a single grep, and
 * keeps the call sites focused on the business logic that lives
 * beside them.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class RowCast
{
    /**
     * Prevent instantiation — static-only utility.
     */
    private function __construct()
    {
    }

    /**
     * Read `$row->$column` as an integer. Missing properties and
     * non-numeric values collapse to `$default`. The underlying
     * `is_numeric()` check accepts both numeric strings and numeric
     * literals so the SQLite/MySQL driver split (strings vs ints
     * for the same column type) stays invisible.
     */
    public static function int(object $row, string $column, int $default = 0): int
    {
        $value = get_object_vars($row)[$column] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    /**
     * Read `$row->$column` as a string. Missing properties and
     * non-string values collapse to `$default`. Used for the xref
     * and label columns the repositories rely on as map keys.
     */
    public static function string(object $row, string $column, string $default = ''): string
    {
        $value = get_object_vars($row)[$column] ?? null;

        return is_string($value) ? $value : $default;
    }
}
