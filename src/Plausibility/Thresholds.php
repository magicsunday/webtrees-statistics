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
 * Per-rule cut-offs centralised in one place so rule logic stays
 * pure (no constant juggling). Tweak the numbers here, every rule
 * picks them up on the next aggregator pass.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class Thresholds
{
    /**
     * Maximum plausible lifespan in years. webtrees defaults to
     * 120 via the per-tree MAX_ALIVE_AGE preference; this
     * constant is the fall-back when no preference is set.
     */
    public const int MAX_LIFESPAN_YEARS = 120;

    /** Mother age cut-offs at the birth of any of her children. */
    public const int MOTHER_MIN_AGE = 14;

    public const int MOTHER_MAX_AGE = 55;

    /** Father age cut-offs at the birth of any of his children. */
    public const int FATHER_MIN_AGE = 16;

    public const int FATHER_MAX_AGE = 80;

    /** Minimum plausible interval between two siblings of the same mother (months). */
    public const int SIBLING_MIN_INTERVAL_MONTHS = 9;

    /** Maximum plausible age-gap between two siblings of the same mother (years). */
    public const int SIBLING_MAX_GAP_YEARS = 50;

    /**
     * Prevent instantiation — constants holder.
     */
    private function __construct()
    {
    }
}
