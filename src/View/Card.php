<?php

/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace MagicSunday\Webtrees\Statistic\View;

use function htmlspecialchars;
use function implode;
use function sprintf;

/**
 * Fluent builder for the shared card frame used by every chart / statistic
 * card. Renders the eyebrow + title + sub header strip, the body slot, the
 * optional accent illustration anchored top- right, and the optional footer
 * info-popover button as one HTML string.
 *
 * Accent colour and illustration both resolve from typed enums (`Accent`,
 * `Illustration`) so a tab template gets compile-time spelling-safety on both
 * axes — `Card::for($module, $title)
 *   ->withAccent(Accent::Wine)
 *   ->withIllustration(Illustration::Tree)
 *   ->render()` — instead of free-form strings.
 *
 * The accent colour drives both the eyebrow label and the illustration's
 * `currentColor` stroke automatically.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class Card
{
    /**
     * Default span across the 12-column grid for cards that don't override.
     */
    public const int DEFAULT_SPAN = 12;

    /**
     * @param string            $module        Module slug for the illustration view-namespace prefix
     * @param string            $title         Card title rendered in the h3
     * @param string|null       $sub           Optional sub-heading beneath the title
     * @param string|null       $eyebrow       Optional kicker label above the title
     * @param int               $span          Grid column span (1-12)
     * @param Accent|null       $accent        Accent colour from the Heritage palette
     * @param Illustration|null $illustration  Illustration key from the catalogue
     * @param string|null       $info          Optional long-form help text — when set, an info popover button is rendered
     * @param string            $infoTitle     Localised heading for the info popover (caller supplies via I18N::translate)
     * @param string            $infoAriaLabel Localised aria-label for the info button (caller supplies via I18N::translate)
     * @param bool              $centered      Centre the body content
     * @param bool|null         $showFooter    Render the dashed-line footer. Defaults to true ONLY when $info is set
     * @param string            $bodyHtml      Pre-rendered HTML body (echoed verbatim — caller must e() any user content)
     */
    private function __construct(
        private string $module,
        private string $title,
        private ?string $sub,
        private ?string $eyebrow,
        private int $span,
        private ?Accent $accent,
        private ?Illustration $illustration,
        private ?string $info,
        private string $infoTitle,
        private string $infoAriaLabel,
        private bool $centered,
        private ?bool $showFooter,
        private string $bodyHtml,
    ) {
    }

    /**
     * Start a new card. `$module` is the view-namespace prefix used when the
     * illustration is resolved at render time.
     */
    public static function for(string $module, string $title): self
    {
        return new self(
            module: $module,
            title: $title,
            sub: null,
            eyebrow: null,
            span: self::DEFAULT_SPAN,
            accent: null,
            illustration: null,
            info: null,
            infoTitle: '',
            infoAriaLabel: '',
            centered: false,
            showFooter: null,
            bodyHtml: '',
        );
    }

    /**
     * Optional sub-heading below the title.
     */
    public function withSub(?string $sub): self
    {
        return new self(
            $this->module,
            $this->title,
            $sub,
            $this->eyebrow,
            $this->span,
            $this->accent,
            $this->illustration,
            $this->info,
            $this->infoTitle,
            $this->infoAriaLabel,
            $this->centered,
            $this->showFooter,
            $this->bodyHtml
        );
    }

    /**
     * Optional kicker label above the title.
     */
    public function withEyebrow(?string $eyebrow): self
    {
        return new self(
            $this->module,
            $this->title,
            $this->sub,
            $eyebrow,
            $this->span,
            $this->accent,
            $this->illustration,
            $this->info,
            $this->infoTitle,
            $this->infoAriaLabel,
            $this->centered,
            $this->showFooter,
            $this->bodyHtml
        );
    }

    /**
     * Grid column span (1-12). Defaults to 12.
     */
    public function withSpan(int $span): self
    {
        return new self(
            $this->module,
            $this->title,
            $this->sub,
            $this->eyebrow,
            $span,
            $this->accent,
            $this->illustration,
            $this->info,
            $this->infoTitle,
            $this->infoAriaLabel,
            $this->centered,
            $this->showFooter,
            $this->bodyHtml
        );
    }

    /**
     * Accent colour from the Heritage palette. Drives both the eyebrow label
     * and the illustration's `currentColor` stroke.
     */
    public function withAccent(?Accent $accent): self
    {
        return new self(
            $this->module,
            $this->title,
            $this->sub,
            $this->eyebrow,
            $this->span,
            $accent,
            $this->illustration,
            $this->info,
            $this->infoTitle,
            $this->infoAriaLabel,
            $this->centered,
            $this->showFooter,
            $this->bodyHtml
        );
    }

    /**
     * Illustration key from the catalogue. Rendered into the top- right corner
     * of the card via the shared `components/illustration.phtml` partial.
     */
    public function withIllustration(?Illustration $illustration): self
    {
        return new self(
            $this->module,
            $this->title,
            $this->sub,
            $this->eyebrow,
            $this->span,
            $this->accent,
            $illustration,
            $this->info,
            $this->infoTitle,
            $this->infoAriaLabel,
            $this->centered,
            $this->showFooter,
            $this->bodyHtml
        );
    }

    /**
     * Long-form help text rendered into a Bootstrap popover triggered from the
     * footer info-button. When set, the footer auto-shows unless explicitly
     * suppressed via `withoutFooter()`.
     *
     * `$title` and `$ariaLabel` are the localised popover heading and
     * accessible label — pass them through `I18N::translate()` at the call site
     * so xgettext extracts the source strings.
     *
     * Note: the popover is rendered with Bootstrap's `data-bs-html="true"` so
     * the body supports inline `<b>` / `<em>` formatting. Treat `$text` as
     * translator-controlled developer content — never feed user-supplied data
     * through this setter; with `data-bs-html` on, any HTML in the content is
     * injected via `innerHTML` and would become a stored-XSS sink.
     */
    public function withInfo(string $text, string $title, string $ariaLabel): self
    {
        return new self(
            $this->module,
            $this->title,
            $this->sub,
            $this->eyebrow,
            $this->span,
            $this->accent,
            $this->illustration,
            $text,
            $title,
            $ariaLabel,
            $this->centered,
            $this->showFooter,
            $this->bodyHtml
        );
    }

    /**
     * Centre the body content (used for scalar/podium-style cards).
     */
    public function centered(): self
    {
        return new self(
            $this->module,
            $this->title,
            $this->sub,
            $this->eyebrow,
            $this->span,
            $this->accent,
            $this->illustration,
            $this->info,
            $this->infoTitle,
            $this->infoAriaLabel,
            true,
            $this->showFooter,
            $this->bodyHtml
        );
    }

    /**
     * Force the footer slot off even if `withInfo()` was called.
     */
    public function withoutFooter(): self
    {
        return new self(
            $this->module,
            $this->title,
            $this->sub,
            $this->eyebrow,
            $this->span,
            $this->accent,
            $this->illustration,
            $this->info,
            $this->infoTitle,
            $this->infoAriaLabel,
            $this->centered,
            false,
            $this->bodyHtml
        );
    }

    /**
     * Force the footer slot on even when no info popover is attached (used to
     * keep the dashed-rhythm consistent across a section).
     */
    public function withFooter(): self
    {
        return new self(
            $this->module,
            $this->title,
            $this->sub,
            $this->eyebrow,
            $this->span,
            $this->accent,
            $this->illustration,
            $this->info,
            $this->infoTitle,
            $this->infoAriaLabel,
            $this->centered,
            true,
            $this->bodyHtml
        );
    }

    /**
     * Pre-rendered HTML body. Echoed verbatim into the card body slot — caller
     * is responsible for escaping any user content via `e()`.
     */
    public function withBodyHtml(string $html): self
    {
        return new self(
            $this->module,
            $this->title,
            $this->sub,
            $this->eyebrow,
            $this->span,
            $this->accent,
            $this->illustration,
            $this->info,
            $this->infoTitle,
            $this->infoAriaLabel,
            $this->centered,
            $this->showFooter,
            $html
        );
    }

    /**
     * Render the card to an HTML string. Safe to embed directly into a parent
     * template via `<?php echo Card::for(...)->render(); ?>`.
     */
    public function render(): string
    {
        $classes = ['wt-stat-card'];

        if ($this->illustration instanceof Illustration) {
            $classes[] = 'wt-stat-card-illustrated';
        }

        if ($this->centered) {
            $classes[] = 'wt-stat-card-centered';
        }

        $classAttr = $this->escapeHtml(implode(' ', $classes));
        $styleAttr = $this->escapeHtml($this->buildStyle());

        $illustration = $this->renderIllustration();
        $header       = $this->renderHeader();
        $body         = $this->bodyHtml;
        $footer       = $this->renderFooter();

        return <<<HTML
<section class="{$classAttr}" style="{$styleAttr}">
    {$illustration}
    {$header}
    <div class="wt-stat-card-body">
        {$body}
    </div>
    {$footer}
</section>
HTML;
    }

    /**
     * Assemble the inline `style="..."` rule from grid-span and the optional
     * accent CSS custom property.
     */
    private function buildStyle(): string
    {
        $style = sprintf('grid-column: span %d; --wt-stat-card-span: %d;', $this->span, $this->span);

        if ($this->accent instanceof Accent) {
            $style .= ' --wt-stat-card-accent: ' . $this->accent->value . ';';
        }

        return $style;
    }

    /**
     * Render the illustration slot (top-right SVG) or an empty string when no
     * illustration was passed.
     */
    private function renderIllustration(): string
    {
        if (!$this->illustration instanceof Illustration) {
            return '';
        }

        $accentAttr = ($this->accent instanceof Accent)
            ? sprintf(' style="color: %s;"', $this->escapeHtml($this->accent->value))
            : '';

        $svg = $this->illustration->svg($this->module);

        return <<<HTML
<div class="wt-stat-card-illustration-clip" aria-hidden="true">
    <div class="wt-stat-card-illustration"{$accentAttr}>{$svg}</div>
</div>
HTML;
    }

    /**
     * Render the eyebrow + title + sub header strip.
     */
    private function renderHeader(): string
    {
        $title   = $this->escapeHtml($this->title);
        $eyebrow = $this->renderEyebrow();
        $sub     = $this->renderSub();

        return <<<HTML
<header class="wt-stat-card-head">
    {$eyebrow}
    <h3 class="wt-stat-card-title">{$title}</h3>
    {$sub}
</header>
HTML;
    }

    /**
     * Render the optional eyebrow paragraph (returns empty string when no
     * eyebrow was passed).
     */
    private function renderEyebrow(): string
    {
        if (($this->eyebrow === null) || ($this->eyebrow === '')) {
            return '';
        }

        $accentAttr = ($this->accent instanceof Accent)
            ? sprintf(' style="color: %s;"', $this->escapeHtml($this->accent->value))
            : '';
        $text = $this->escapeHtml($this->eyebrow);

        return sprintf('<p class="wt-stat-card-eyebrow"%s>%s</p>', $accentAttr, $text);
    }

    /**
     * Render the optional sub-heading paragraph (returns empty string when no
     * sub was passed).
     */
    private function renderSub(): string
    {
        if (($this->sub === null) || ($this->sub === '')) {
            return '';
        }

        return sprintf('<p class="wt-stat-card-sub">%s</p>', $this->escapeHtml($this->sub));
    }

    /**
     * Render the footer slot (info popover button) or an empty string when the
     * footer is suppressed.
     */
    private function renderFooter(): string
    {
        $showFooter = $this->showFooter ?? (($this->info !== null) && ($this->info !== ''));

        if (!$showFooter) {
            return '';
        }

        $button = '';

        if (($this->info !== null) && ($this->info !== '')) {
            $title     = $this->escapeHtml($this->infoTitle);
            $content   = $this->escapeHtml($this->info);
            $ariaLabel = $this->escapeHtml($this->infoAriaLabel);

            $button = <<<HTML
<button type="button" 
    class="wt-stat-card-info" 
    data-bs-toggle="popover" 
    data-bs-trigger="focus hover" 
    data-bs-placement="top" 
    data-bs-html="true" 
    data-bs-custom-class="wt-stat-popover wt-popover-wide" 
    data-bs-title="{$title}" 
    data-bs-content="{$content}" 
    aria-label="{$ariaLabel}"
>?</button>
HTML;
        }

        return <<<HTML
<footer class="wt-stat-card-foot">{$button}</footer>
HTML;
    }

    /**
     * HTML-escape any user-supplied string before it lands inside an attribute
     * or text node.
     */
    private function escapeHtml(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
