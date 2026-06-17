<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\Test\View;

use MagicSunday\Webtrees\Statistic\View\Accent;
use MagicSunday\Webtrees\Statistic\View\Card;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Verifies the immutable fluent Card builder used by the chart / statistic
 * modules. The rendered HTML is asserted in shape, not byte-for-byte — the goal
 * is to lock the contract a consuming template relies on, not the whitespace.
 *
 * Illustration-related assertions are out of scope here: the `Illustration`
 * enum's `svg()` method calls the webtrees `view()` helper, which needs the
 * full webtrees runtime. The illustration code-path is covered by browser-level
 * smoke tests on every tab.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
#[CoversClass(Card::class)]
final class CardTest extends TestCase
{
    /**
     * A bare title-only card emits the `wt-stat-card` section, the title h3, no
     * eyebrow, no sub, no illustration, no footer.
     */
    #[Test]
    public function bareTitleOnlyCardRendersMinimalShell(): void
    {
        $html = Card::for('m', 'Hello')->render();

        self::assertStringContainsString('<section class="wt-stat-card" ', $html);
        self::assertStringContainsString('<h3 class="wt-stat-card-title">Hello</h3>', $html);
        self::assertStringNotContainsString('wt-stat-card-eyebrow', $html);
        self::assertStringNotContainsString('wt-stat-card-sub', $html);
        self::assertStringNotContainsString('wt-stat-card-illustration', $html);
        self::assertStringNotContainsString('wt-stat-card-foot', $html);
    }

    /**
     * Eyebrow + sub appear in the header when set; the eyebrow picks up the
     * accent colour as an inline style.
     */
    #[Test]
    public function eyebrowAndSubRenderWhenSet(): void
    {
        $html = Card::for('m', 'Title')
            ->withEyebrow('Section')
            ->withSub('Subtitle text')
            ->withAccent(Accent::Wine)
            ->render();

        self::assertStringContainsString('<p class="wt-stat-card-eyebrow" style="color: var(--wine);">Section</p>', $html);
        self::assertStringContainsString('<p class="wt-stat-card-sub">Subtitle text</p>', $html);
    }

    /**
     * Accent enum is serialised as its CSS literal value into the
     * `--wt-stat-card-accent` custom property + the eyebrow inline style.
     */
    #[Test]
    public function accentEnumPropagatesToCustomPropertyAndEyebrow(): void
    {
        $html = Card::for('m', 'T')
            ->withEyebrow('E')
            ->withAccent(Accent::Sage)
            ->render();

        self::assertStringContainsString('--wt-stat-card-accent: var(--sage);', $html);
        self::assertStringContainsString('style="color: var(--sage);"', $html);
    }

    /**
     * The grid-span value is reflected in BOTH the inline `grid-column: span N`
     * rule and the `--wt-stat-card-span` custom property, so consumer CSS can
     * react to it.
     */
    #[Test]
    public function spanPropagatesToGridColumnAndCustomProperty(): void
    {
        $html = Card::for('m', 'T')->withSpan(4)->render();

        self::assertStringContainsString('grid-column: span 4', $html);
        self::assertStringContainsString('--wt-stat-card-span: 4', $html);
    }

    /**
     * The body-HTML branch echoes the caller string verbatim — the caller is
     * responsible for escaping any user content. This test asserts the verbatim
     * contract, not security.
     */
    #[Test]
    public function bodyHtmlEchoesCallerStringVerbatim(): void
    {
        $html = Card::for('m', 'T')
            ->withBodyHtml('<div class="my-chart"><svg></svg></div>')
            ->render();

        self::assertStringContainsString('<div class="wt-stat-card-body">', $html);
        self::assertStringContainsString('<div class="my-chart"><svg></svg></div>', $html);
    }

    /**
     * Setting `withInfo()` auto-shows the footer and wires up the Bootstrap
     * popover attributes with the localised title and accessible label. The
     * info button's `aria-label` names the card it explains (here "T") so a
     * screen-reader user can tell the otherwise-identical "About this chart"
     * buttons apart.
     */
    #[Test]
    public function withInfoAutoShowsFooterAndWiresPopover(): void
    {
        $html = Card::for('m', 'T')
            ->withInfo('Long help text', 'About this chart', 'About this chart')
            ->render();

        self::assertStringContainsString('<footer class="wt-stat-card-foot">', $html);
        self::assertStringContainsString('class="wt-stat-card-info"', $html);
        self::assertStringContainsString('data-bs-toggle="popover"', $html);
        self::assertStringContainsString('data-bs-content="Long help text"', $html);
        self::assertStringContainsString('aria-label="About this chart: T"', $html);
        // Heritage popover theme rides on the `wt-stat-popover`
        // custom-class — the CSS in statistics.css scopes its
        // background / typography / dark-mode overrides under
        // `.popover.wt-stat-popover`. Match both class tokens
        // individually so a future change adding a third modifier
        // (e.g. `wt-popover-compact`) does not fail this lock for
        // a non-regression.
        self::assertMatchesRegularExpression(
            '/data-bs-custom-class="[^"]*\bwt-stat-popover\b[^"]*"/',
            $html,
            'heritage popover theme class survives',
        );
        self::assertMatchesRegularExpression(
            '/data-bs-custom-class="[^"]*\bwt-popover-wide\b[^"]*"/',
            $html,
            'wide-popover modifier survives',
        );
    }

    /**
     * Without `withInfo()`, `withFooter()` and `withoutFooter()`, the footer
     * defaults to suppressed. Locks the implicit fallback so a regression of
     * the tri-state branch (default → footer suppressed) fails its own
     * clearly-named test instead of dragging the bare-shell test down with it.
     */
    #[Test]
    public function defaultFooterStaysSuppressedWithoutInfo(): void
    {
        $html = Card::for('m', 'T')->render();

        self::assertStringNotContainsString('wt-stat-card-foot', $html);
    }

    /**
     * `withoutFooter()` suppresses the footer even when `withInfo()` is set —
     * for cases where the section already has a single shared info button.
     */
    #[Test]
    public function withoutFooterSuppressesEvenWhenInfoIsSet(): void
    {
        $html = Card::for('m', 'T')
            ->withInfo('Help', 'Title', 'Aria')
            ->withoutFooter()
            ->render();

        self::assertStringNotContainsString('wt-stat-card-foot', $html);
    }

    /**
     * `centered()` adds the modifier class so the consumer CSS can centre the
     * body content for scalar / podium cards.
     */
    #[Test]
    public function centeredAddsModifierClass(): void
    {
        $html = Card::for('m', 'T')->centered()->render();

        self::assertStringContainsString('class="wt-stat-card wt-stat-card-centered"', $html);
    }

    /**
     * The title goes through HTML-escape so accidental user content with quotes
     * / angle brackets cannot break out of the h3.
     */
    #[Test]
    public function titleIsHtmlEscaped(): void
    {
        $html = Card::for('m', '<script>alert("x")</script>')->render();

        self::assertStringContainsString('&lt;script&gt;', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }

    /**
     * The builder is immutable — each `with*()` call returns a new instance
     * with the field overridden, leaving the source object unchanged.
     */
    #[Test]
    public function builderIsImmutable(): void
    {
        $base    = Card::for('m', 'Original');
        $derived = $base->withEyebrow('Section');

        self::assertNotSame($base, $derived);
        self::assertStringNotContainsString('Section', $base->render());
        self::assertStringContainsString('Section', $derived->render());
    }

    /**
     * Passing `null` to a setter clears the previously-set field (e.g. for
     * cases where the field is conditionally cleared after being set in an
     * earlier branch).
     */
    #[Test]
    public function nullClearsPreviouslySetField(): void
    {
        $cleared = Card::for('m', 'T')
            ->withEyebrow('Section')
            ->withEyebrow(null)
            ->render();

        self::assertStringNotContainsString('wt-stat-card-eyebrow', $cleared);
    }

    /**
     * `withFooter()` forces the dashed footer slot to render even when no info
     * popover is attached — supports keeping the dashed-rhythm consistent
     * across a section.
     */
    #[Test]
    public function withFooterEmitsEmptyFooterWhenNoInfoIsSet(): void
    {
        $html = Card::for('m', 'T')->withFooter()->render();

        self::assertStringContainsString('<footer class="wt-stat-card-foot">', $html);
        self::assertStringNotContainsString('wt-stat-card-info', $html);
    }
}
