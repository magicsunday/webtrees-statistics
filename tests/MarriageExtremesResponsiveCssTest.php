<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test;

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function dirname;
use function file_get_contents;

/**
 * Locks the mobile-first responsive contract of the `marriage-extremes` widget:
 * the shortest / longest lists sit in a single column by default and only split
 * into two side-by-side columns once a `min-width` breakpoint is reached, so on
 * a narrow viewport the "longest marriages" block stacks below "shortest
 * marriages" instead of being squeezed beside it.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversNothing]
final class MarriageExtremesResponsiveCssTest extends TestCase
{
    /**
     * The container defaults to one column (mobile-first base) and a `min-width`
     * media query promotes it to two columns. A future edit that drops the
     * breakpoint — or reverts the base back to a fixed two-column grid — fails
     * here instead of silently squeezing the two lists side by side on phones.
     */
    #[Test]
    public function stacksToOneColumnByDefaultAndSplitsAtMinWidthBreakpoint(): void
    {
        $css = file_get_contents(dirname(__DIR__) . '/resources/css/statistics.css');

        self::assertNotFalse($css, 'statistics.css must be readable');

        // Mobile-first base: the container is a single column. Matched without
        // pinning declaration order or spacing, so a cosmetic reformat does not
        // turn this into a false RED — only an actual two-column base would.
        self::assertMatchesRegularExpression(
            '/\.wt-stat-marriage-extremes\s*\{[^}]*?grid-template-columns:\s*1fr\s*;/',
            $css,
            'marriage-extremes must default to a single column (mobile-first base)',
        );

        // A min-width breakpoint promotes it to two side-by-side columns (either
        // `1fr 1fr` or the equivalent `repeat(2, 1fr)`).
        self::assertMatchesRegularExpression(
            '/@media\s*\([^)]*min-width[^)]*\)\s*\{[^@]*?\.wt-stat-marriage-extremes\s*\{[^}]*?'
            . 'grid-template-columns:\s*(?:1fr\s+1fr|repeat\(\s*2\s*,\s*1fr\s*\))\s*;/',
            $css,
            'marriage-extremes must switch to two columns at a min-width breakpoint',
        );
    }
}
