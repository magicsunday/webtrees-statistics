<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\View;

use MagicSunday\Webtrees\Statistic\View\Section;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the shared section-divider builder used between card
 * groups across the chart / statistic modules.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class SectionTest extends TestCase
{
    /**
     * A title-only section renders the wrapper and the h2 title, no
     * kicker, no sub.
     */
    #[Test]
    public function titleOnlySectionRendersMinimalShell(): void
    {
        $html = Section::create('Family')->render();

        self::assertSame(
            '<section class="wt-stat-section"><h2 class="wt-stat-section-title">Family</h2></section>',
            $html,
        );
    }

    /**
     * Kicker + sub render in the documented order: kicker, title,
     * sub.
     */
    #[Test]
    public function kickerAndSubRenderInOrder(): void
    {
        $html = Section::create('Family')
            ->withKicker('Demographics')
            ->withSub('Lifespan, mortality, births, deaths')
            ->render();

        $expected = '<section class="wt-stat-section">'
            . '<p class="wt-stat-section-kicker">Demographics</p>'
            . '<h2 class="wt-stat-section-title">Family</h2>'
            . '<p class="wt-stat-section-sub">Lifespan, mortality, births, deaths</p>'
            . '</section>';

        self::assertSame($expected, $html);
    }

    /**
     * Title goes through HTML escape so accidental user content with
     * quotes or brackets cannot break out of the heading.
     */
    #[Test]
    public function titleIsHtmlEscaped(): void
    {
        $html = Section::create('<b>Title</b>')->render();

        self::assertStringContainsString('&lt;b&gt;Title&lt;/b&gt;', $html);
        self::assertStringNotContainsString('<b>Title', $html);
    }

    /**
     * `withKicker(null)` clears a previously-set kicker — supports
     * builder chains that conditionally remove the kicker.
     */
    #[Test]
    public function nullKickerClearsPreviouslySetKicker(): void
    {
        $html = Section::create('Family')
            ->withKicker('Demographics')
            ->withKicker(null)
            ->render();

        self::assertStringNotContainsString('wt-stat-section-kicker', $html);
    }
}
