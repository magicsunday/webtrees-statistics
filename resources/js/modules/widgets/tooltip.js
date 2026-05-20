/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

/**
 * Escape a string for safe interpolation into a tooltip's innerHTML.
 * Tooltip bodies built from GEDCOM-derived strings (place names,
 * given names) must never trust the input — a hand-edited tree can
 * contain `<script>` or stray quotes.
 *
 * @param {String} value Raw text from a data source.
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
 * Build a follow-cursor tooltip that lives on `document.body` and
 * uses `position: fixed` so it can extend past any chart container's
 * bounding box without forcing the host to grow / scrollbars to
 * appear. Clamped only to the viewport edges.
 *
 * The single body-level element is shared across every chart that
 * uses this helper — only one chart can be hovered at a time, so
 * sharing is safe and keeps the DOM lean.
 *
 * @returns {{
 *   element: HTMLDivElement,
 *   show:    (event: MouseEvent | {clientX: number, clientY: number}, html: string) => void,
 *   move:    (event: MouseEvent | {clientX: number, clientY: number}) => void,
 *   hide:    () => void
 * }}
 */
export function createChartTooltip() {
    let element = document.body.querySelector(":scope > .wt-chart-tooltip");

    if (element === null) {
        element = document.createElement("div");
        element.className = "wt-chart-tooltip";
        document.body.appendChild(element);
    }

    const move = (event) => {
        const tooltipRect = element.getBoundingClientRect();
        const margin = 8;
        const viewportW = window.innerWidth;
        const viewportH = window.innerHeight;

        // Horizontal: prefer right of the cursor, flip left when the
        // tooltip would overflow the viewport's right edge.
        let left = event.clientX + 14;
        if (left + tooltipRect.width + margin > viewportW) {
            left = event.clientX - tooltipRect.width - 14;
        }
        if (left < margin) {
            left = margin;
        }

        // Vertical: prefer below the cursor, flip above when the
        // tooltip would overflow the viewport's bottom edge.
        let top = event.clientY + 14;
        if (top + tooltipRect.height + margin > viewportH) {
            top = event.clientY - tooltipRect.height - 14;
        }
        if (top < margin) {
            top = margin;
        }

        element.style.left = `${left}px`;
        element.style.top = `${top}px`;
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
