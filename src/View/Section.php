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

/**
 * Fluent builder for the shared section-divider strip used between
 * card groups (kicker + serif title + sub). Spans the full 12-column
 * grid by default; consuming modules style the output classes
 * scoped under their own container.
 *
 * Output class set: `wt-section`, `wt-stat-section-kicker`,
 * `wt-stat-section-title`, `wt-stat-section-sub`.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
final readonly class Section
{
    /**
     * @param string      $title  Section title
     * @param string|null $kicker Optional short kicker label above the title (typically uppercase tracking)
     * @param string|null $sub    Optional sub-heading below the title
     */
    private function __construct(
        private string $title,
        private ?string $kicker,
        private ?string $sub,
    ) {
    }

    /**
     * Start a new section with the required title.
     */
    public static function create(string $title): self
    {
        return new self(
            title: $title,
            kicker: null,
            sub: null
        );
    }

    /**
     * Optional kicker label above the title (usually uppercase short
     * keyword like "DEMOGRAPHICS" / "FAMILY").
     */
    public function withKicker(?string $kicker): self
    {
        return new self(
            $this->title,
            $kicker,
            $this->sub
        );
    }

    /**
     * Optional sub-heading below the title.
     */
    public function withSub(?string $sub): self
    {
        return new self(
            $this->title,
            $this->kicker,
            $sub
        );
    }

    /**
     * Render the section to an HTML string.
     */
    public function render(): string
    {
        $out = '<section class="wt-stat-section">';

        if (($this->kicker !== null) && ($this->kicker !== '')) {
            $out .= '<p class="wt-stat-section-kicker">' . htmlspecialchars($this->kicker, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        $out .= '<h2 class="wt-stat-section-title">' . htmlspecialchars($this->title, ENT_QUOTES, 'UTF-8') . '</h2>';

        if (($this->sub !== null) && ($this->sub !== '')) {
            $out .= '<p class="wt-stat-section-sub">' . htmlspecialchars($this->sub, ENT_QUOTES, 'UTF-8') . '</p>';
        }

        return $out . '</section>';
    }
}
