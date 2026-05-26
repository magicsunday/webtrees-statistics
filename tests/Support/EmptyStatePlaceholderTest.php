<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\Support;

use MagicSunday\Webtrees\Statistic\Support\EmptyStatePlaceholder;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the single-source-of-truth empty-state placeholder used by
 * every chart partial and server-rendered card body whose data source
 * is missing or all-zero. The default-message path delegates to
 * `I18N::translate()` which needs the webtrees runtime — covered in
 * the integration test suite. Here we cover the custom-message path
 * and the HTML-escape contract.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final class EmptyStatePlaceholderTest extends TestCase
{
    /**
     * A custom message lands inside the canonical wrapper unchanged
     * (plain ASCII path — no characters that need escaping).
     */
    #[Test]
    public function renderWrapsCustomMessageInChartEmptyStateDiv(): void
    {
        self::assertSame(
            '<div class="chart-empty-state">Custom placeholder</div>',
            EmptyStatePlaceholder::render('Custom placeholder'),
        );
    }

    /**
     * HTML-special characters in the custom message must be escaped
     * via the `e()` helper so a stray quote, ampersand or angle
     * bracket cannot break out of the wrapper or inject markup.
     */
    #[Test]
    public function renderEscapesHtmlSpecialCharacters(): void
    {
        $output = EmptyStatePlaceholder::render('<script>alert("x")</script> & friends');

        self::assertStringContainsString('&lt;script&gt;', $output);
        self::assertStringContainsString('&amp; friends', $output);
        self::assertStringNotContainsString('<script>', $output);
    }

    /**
     * Passing an empty string still emits the wrapper — callers that
     * want to suppress the placeholder skip the `render()` call
     * altogether rather than passing `''`.
     */
    #[Test]
    public function renderWithEmptyStringStillEmitsWrapper(): void
    {
        self::assertSame(
            '<div class="chart-empty-state"></div>',
            EmptyStatePlaceholder::render(''),
        );
    }
}
