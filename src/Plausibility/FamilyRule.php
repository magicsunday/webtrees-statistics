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
 * Rule that inspects a single family record. The aggregator
 * loads the family-side rules separately from the individual-side
 * rules because the SQL surface differs (family records carry
 * their child + spouse xrefs on the row, individuals do not).
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
interface FamilyRule
{
    /**
     * Stable id of the rule.
     */
    public function id(): string;

    /**
     * Inspect a single family's raw GEDCOM and yield any findings
     * the rule produces. The aggregator already resolved the
     * husband / wife xrefs so they're passed in directly; rules
     * that need child julian-days fetch them via the parent-of /
     * children-of helpers exposed through the same aggregator.
     *
     * @param string                                                                                                        $xref     GEDCOM xref of the family
     * @param string                                                                                                        $gedcom   Raw GEDCOM record body
     * @param array<int, array{xref: string, birthJd: int}>                                                                 $children Pre-resolved child rows so each rule does not have to re-join the dates table
     * @param array{fatherBirthJd?: int|null, motherBirthJd?: int|null, fatherDeathJd?: int|null, motherDeathJd?: int|null} $context  Husband / wife birth and death julian-days, all optional
     *
     * @return iterable<int, Finding>
     */
    public function check(string $xref, string $gedcom, array $children, array $context): iterable;
}
