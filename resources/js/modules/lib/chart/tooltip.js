/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

/**
 * Escape a string for safe interpolation into the tooltip's innerHTML.
 * Tooltip bodies built from GEDCOM-derived strings (place names,
 * given names) must never trust the input — a hand-edited tree can
 * contain `<script>` or stray quotes.
 *
 * @param {String} value Raw text from a data source (place name, person name, etc.).
 *
 * @returns {String} HTML-safe representation, ready to drop into innerHTML.
 */
export function escapeHtml(value) {
    return String(value)
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#39;");
}

/**
 * Build a host-anchored, follow-cursor tooltip with hover-bounds
 * clamping. Shared by chart renderers (stream graph, sankey flow).
 * The tooltip is absolutely positioned inside the host and clamped
 * to stay within its bounding box so it never spills past the right
 * or bottom edges and triggers a page-level scrollbar.
 *
 * @param {HTMLElement} host      Host container (must be position:relative + overflow:hidden in CSS).
 * @param {String}      className CSS class to attach to the tooltip div.
 *
 * @returns {{
 *   element:  HTMLDivElement,
 *   show:     (event: MouseEvent, html: string) => void,
 *   move:     (event: MouseEvent) => void,
 *   hide:     () => void
 * }}
 */
export function createHostTooltip(host, className) {
    let element = host.querySelector(`.${className}`);

    if (element === null) {
        element = document.createElement("div");
        element.className = className;
        host.appendChild(element);
    }

    const move = (event) => {
        const hostRect    = host.getBoundingClientRect();
        const tooltipRect = element.getBoundingClientRect();
        const desiredX    = event.clientX - hostRect.left + 12;
        const desiredY    = event.clientY - hostRect.top + 12;
        const maxX        = Math.max(0, hostRect.width - tooltipRect.width - 4);
        const maxY        = Math.max(0, hostRect.height - tooltipRect.height - 4);
        element.style.left = `${Math.min(desiredX, maxX)}px`;
        element.style.top  = `${Math.min(desiredY, maxY)}px`;
    };

    const show = (event, html) => {
        element.innerHTML = html;
        element.classList.add("is-visible");
        move(event);
    };

    const hide = () => {
        element.classList.remove("is-visible");
    };

    return { element, show, move, hide };
}
