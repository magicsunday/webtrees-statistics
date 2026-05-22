<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic;

/**
 * GEDCOM sex token used as a binary spouse / parent selector inside
 * the per-sex aggregations on the Family tab. The Statistic facade
 * stays string-typed because PHTML templates and webtrees core (e.g.
 * `StatisticsData::statsMarrAgeQuery`) speak raw `'M'` / `'F'`
 * literals; this enum is the internal type the repositories convert
 * to before picking the correct family column (husband vs wife) or
 * parent column (father vs mother).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
enum Sex: string
{
    case Male   = 'M';
    case Female = 'F';

    /**
     * Pick the `families.f_husb` / `families.f_wife` column that
     * holds the spouse of this sex. Used by the marriage and divorce
     * repositories when they need a sex-specific join target.
     */
    public function spouseColumn(): string
    {
        return match ($this) {
            self::Male   => 'f_husb',
            self::Female => 'f_wife',
        };
    }
}
