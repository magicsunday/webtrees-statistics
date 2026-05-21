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
 * Single plausibility-check finding. Carries enough metadata for
 * the view to render a linkable drilldown row: the offending
 * record's xref, whether it's an individual or family (so the
 * view knows which url() to build), the rule id (stable across
 * locales for filtering), and the human-readable reason.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class Finding
{
    /**
     * @param string      $xref   GEDCOM xref of the offending record
     * @param string      $kind   `'individual'` or `'family'` — drives the URL builder in the view
     * @param string      $ruleId Stable id of the rule that fired (locale-independent, suitable for grouping)
     * @param string      $reason Human-readable explanation (already localised)
     * @param string|null $url    Fully-built webtrees URL for the record; populated by the repository when the xref resolves to a live record, null otherwise
     */
    public function __construct(
        public string $xref,
        public string $kind,
        public string $ruleId,
        public string $reason,
        public ?string $url = null,
    ) {
    }
}
