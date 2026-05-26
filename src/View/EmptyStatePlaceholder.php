<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\View;

use Fisharebest\Webtrees\I18N;

use function e;

/**
 * Single source of truth for the `.chart-empty-state` placeholder
 * markup rendered by every chart partial and every server-rendered
 * card body whose data source is missing or all-zero. Owning the
 * markup once means future ARIA / wrapper / fallback-copy changes
 * land in one file instead of being copy-pasted across 14+ partials.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class EmptyStatePlaceholder
{
    /**
     * Static-only utility; not constructible.
     */
    private function __construct()
    {
    }

    /**
     * Return the localised default copy used when no override is
     * supplied. Exposed so the chart-lib JS side can receive the
     * exact same string via the `data-empty-message` attribute
     * without re-translating the msgid in every partial.
     */
    public static function defaultMessage(): string
    {
        return I18N::translate('No data recorded for this metric.');
    }

    /**
     * Return the localised "no data" placeholder HTML. Callers `echo`
     * the result directly into the card body.
     *
     * @param string|null $message Optional override for the placeholder copy; defaults to the standard module-wide line.
     */
    public static function render(?string $message = null): string
    {
        $copy = $message ?? self::defaultMessage();

        return '<div class="chart-empty-state">' . e($copy) . '</div>';
    }
}
