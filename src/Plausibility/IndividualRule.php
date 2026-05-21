<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Plausibility;

/**
 * Rule that inspects a single individual record. Implementations
 * return zero or more {@see Finding} objects per individual; the
 * aggregator concatenates the per-record outputs.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
interface IndividualRule
{
    /**
     * Stable id of the rule — locale-independent so groupings and
     * filters keep working when the user switches language.
     */
    public function id(): string;

    /**
     * Inspect a single individual's raw GEDCOM and yield any
     * findings the rule produces.
     *
     * @param string $xref   GEDCOM xref of the individual
     * @param string $gedcom Raw GEDCOM record body
     *
     * @return iterable<int, Finding>
     */
    public function check(string $xref, string $gedcom): iterable;
}
