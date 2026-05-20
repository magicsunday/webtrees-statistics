/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import drawDonut from "./widgets/donut.js";
import drawWorldMap from "./widgets/world-map.js";
import drawStreamGraph from "./widgets/stream-graph.js";
import drawSankeyFlow from "./widgets/sankey-flow.js";

/**
 * Dispatch table mapping a `data-widget` attribute value to its
 * draw function. Each draw is `(node, data, options) => Node|null`.
 *
 * @type {Object<String, function(HTMLElement, *, Object): Node|null>}
 */
const WIDGETS = {
    donut: drawDonut,
    "world-map": drawWorldMap,
    "stream-graph": drawStreamGraph,
    "sankey-flow": drawSankeyFlow,
};

/**
 * Render every `[data-widget]` element inside `root` by dispatching
 * to the registered draw function. Each node carries its widget
 * type in `data-widget`, its serialised payload in `data-payload`,
 * and its renderer options in `data-options` (both JSON).
 *
 * Bootstrap popovers attached to chart-header info buttons are
 * initialised in the same pass so the consumer doesn't need a
 * second hook.
 *
 * @param {ParentNode} root Document fragment to scan.
 *
 * @returns {void}
 */
export function renderWidgets(root) {
    const nodes = root.querySelectorAll("[data-widget]");

    nodes.forEach((node) => {
        const widget = WIDGETS[node.dataset.widget];

        if (widget === undefined) {
            return;
        }

        const data = parseJsonAttribute(node.dataset.payload, null);
        const options = parseJsonAttribute(node.dataset.options, {});

        widget(node, data, options);
    });

    initPopovers(root);
}

/**
 * Parse a JSON-encoded dataset attribute, returning the fallback on
 * missing or unparsable input. Logs the parse error to the console
 * so a corrupt payload is debuggable but never breaks the render
 * loop for sibling widgets.
 *
 * @param {String|undefined} raw      The serialised JSON string.
 * @param {*}                fallback Value returned when parse fails / input is empty.
 *
 * @returns {*}
 */
function parseJsonAttribute(raw, fallback) {
    if (raw === undefined || raw === "") {
        return fallback;
    }

    try {
        return JSON.parse(raw);
    } catch (error) {
        // eslint-disable-next-line no-console
        console.error("renderWidgets: unable to parse widget payload", error);
        return fallback;
    }
}

/**
 * Initialise Bootstrap popovers used by the "About this chart" info
 * buttons. Bootstrap ships with the webtrees vendor bundle and
 * exposes itself on `window.bootstrap`. getOrCreateInstance keeps
 * the call idempotent across re-renders.
 *
 * @param {ParentNode} root Document fragment to scan.
 */
function initPopovers(root) {
    if (typeof window.bootstrap === "undefined" || !window.bootstrap.Popover) {
        return;
    }

    root.querySelectorAll('.wt-statistics-chart [data-bs-toggle="popover"]').forEach((element) => {
        window.bootstrap.Popover.getOrCreateInstance(element, { container: "body" });
    });
}
