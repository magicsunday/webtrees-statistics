/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import { json } from "d3-fetch";
import { geoMercator } from "d3-geo";

import {
    BarChart,
    BoxPlot,
    ChordDiagram,
    DivergingBar,
    DonutChart,
    GaugeArc,
    LineChart,
    MirrorHistogram,
    MonthRadial,
    NameBubbles,
    SankeyFlow,
    StackedBar,
    StreamGraph,
    WorldMap,
} from "@magicsunday/webtrees-chart-lib";

import { DashboardBus } from "./dashboard-bus.js";

const WORLD_GEOJSON_URL =
    "/index.php?route=%2Fmodule%2F_webtrees-statistics_%2FAsset&asset=js/world-map.geojson";

let cachedGeoJson = null;

/**
 * Lazily load (and cache) the world GeoJSON. The chart-lib WorldMap widget
 * needs the FeatureCollection up-front in its options; we fetch it once per
 * page load and reuse for every map render.
 *
 * @returns {Promise<object>} The parsed FeatureCollection.
 */
async function loadWorldGeoJson() {
    if (cachedGeoJson === null) {
        const raw = await json(WORLD_GEOJSON_URL);
        // Drop Antarctica — no genealogy data is going to land in
        // AQ and the continent eats roughly a third of a Mercator
        // projection's vertical space, squishing every populated
        // landmass.
        cachedGeoJson = {
            ...raw,
            features: raw.features.filter((feature) => {
                const iso =
                    feature?.properties?.ISO_A2_EH ??
                    feature?.properties?.ISO_A2 ??
                    feature?.properties?.iso_a2;
                return iso !== "AQ";
            }),
        };
    }
    return cachedGeoJson;
}

/**
 * Adapter that turns a chart-lib widget class (`new Widget(node,
 * opts).draw(data)`) into the functional `(node, data, options)` shape the
 * dispatcher uses. Keeps the dispatch table flat.
 *
 * @param {{new (node: HTMLElement, options: object): {draw: (data: unknown) => unknown}}} Widget Chart-lib widget class.
 *
 * @returns {(node: HTMLElement, data: unknown, options: object) => unknown}
 */
function fromChartLib(Widget) {
    return (node, data, options) => {
        const widget = new Widget(node, options);
        widget.draw(data);
        return widget;
    };
}

/**
 * Asynchronous world-map dispatcher. Fetches (and caches) the geojson, then
 * hands it to the chart-lib WorldMap widget alongside a d3-geo Mercator
 * projection. Same async return shape as the other widgets even though they
 * resolve synchronously, so callers never have to special-case the map.
 *
 * @param {HTMLElement} node
 * @param {unknown}     data
 * @param {object}      options
 *
 * @returns {Promise<unknown>}
 */
async function drawWorldMap(node, data, options) {
    const geojson = await loadWorldGeoJson();
    const widget = new WorldMap(node, {
        ...options,
        geojson,
        projection: geoMercator(),
    });
    widget.draw(data);
    return widget;
}

/**
 * Dispatch table mapping a `data-widget` attribute value to its draw function.
 * Every widget is a chart-lib widget; the world map just needs a pre-fetch hop
 * to load the GeoJSON the widget consumes via its constructor.
 *
 * @type {Object<string, (node: HTMLElement, data: unknown, options: object) => unknown>}
 */
const WIDGETS = {
    donut: fromChartLib(DonutChart),
    "world-map": drawWorldMap,
    "stream-graph": fromChartLib(StreamGraph),
    "sankey-flow": fromChartLib(SankeyFlow),
    "line-chart": fromChartLib(LineChart),
    "bar-chart": fromChartLib(BarChart),
    "stacked-bar": fromChartLib(StackedBar),
    "diverging-bar": fromChartLib(DivergingBar),
    "chord-diagram": fromChartLib(ChordDiagram),
    "name-bubbles": fromChartLib(NameBubbles),
    "month-radial": fromChartLib(MonthRadial),
    "gauge-arc": fromChartLib(GaugeArc),
    "mirror-histogram": fromChartLib(MirrorHistogram),
    "box-plot": fromChartLib(BoxPlot),
};

/**
 * Render every `[data-widget]` element inside `root` by dispatching to the
 * registered draw function. Each node carries its widget type in `data-widget`,
 * its serialised payload in `data-payload`, and its renderer options in
 * `data-options` (both JSON).
 *
 * Bootstrap popovers attached to chart-header info buttons are initialised in
 * the same pass so the consumer doesn't need a second hook.
 *
 * @param {ParentNode} root Document fragment to scan.
 *
 * @returns {void}
 */
export function renderWidgets(root) {
    const nodes = root.querySelectorAll("[data-widget]");
    const bus = new DashboardBus();
    const widgets = [];

    // Reveal-on-scroll: every widget is drawn up front — so it is in the DOM
    // and wired to the bus immediately (needed for cross-widget filtering,
    // print, and in-page search) — but each animated widget HOLDS its entrance
    // at the initial keyframe and only plays it once the card scrolls into
    // view. Eager draw also keeps the layout stable (each chart sizes itself on
    // draw), so the observer fires only for genuinely-visible cards instead of
    // a collapsed initial stack. Disabled under reduced motion (no entrance to
    // gate) or when IntersectionObserver is unavailable (older browser, jsdom):
    // the entrance then plays inline on draw.
    const reduceMotion =
        typeof window.matchMedia === "function" &&
        window.matchMedia("(prefers-reduced-motion: reduce)").matches;
    const revealOnScroll = reduceMotion === false && typeof IntersectionObserver !== "undefined";
    const instanceByNode = revealOnScroll ? new WeakMap() : null;

    /**
     * Draw a single widget node and wire it into the shared bus.
     *
     * @param {Element} node
     *
     * @returns {void}
     */
    const renderNode = (node) => {
        const widget = WIDGETS[node.dataset.widget];

        if (widget === undefined) {
            return;
        }

        const data = parseJsonAttribute(node.dataset.payload, null);
        const options = parseJsonAttribute(node.dataset.options, {});

        // The chart partials emit the translated empty-state copy as
        // a `data-empty-message` attribute alongside the widget
        // marker. Hoist it into options so widgets pick up the
        // localised string instead of the built-in English fallback
        // ("No data available").
        if (
            typeof node.dataset.emptyMessage === "string" &&
            node.dataset.emptyMessage !== "" &&
            options.emptyMessage === undefined
        ) {
            options.emptyMessage = node.dataset.emptyMessage;
        }

        // Hold the entrance until the card is revealed; playEntry() starts it.
        if (revealOnScroll) {
            options.animateOnReveal = true;
        }

        const instance = widget(node, data, options);

        // Async widgets (world-map) return a Promise instead of the
        // widget instance; connect the bus inside the .then so the
        // wiring happens once the widget has actually rendered.
        if (instance instanceof Promise) {
            instance.then((resolved) => {
                connectToBus(resolved, bus, widgets);
                if (instanceByNode !== null) {
                    instanceByNode.set(node, resolved);
                }
            });
        } else {
            connectToBus(instance, bus, widgets);
            if (instanceByNode !== null) {
                instanceByNode.set(node, instance);
            }
        }
    };

    nodes.forEach((node) => {
        renderNode(node);
    });

    if (revealOnScroll) {
        const observer = new IntersectionObserver(
            (entries, obs) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting === false) {
                        return;
                    }

                    // One-shot per node: stop watching, then play the entrance
                    // on the already-drawn widget (no re-draw).
                    obs.unobserve(entry.target);
                    const instance = instanceByNode.get(entry.target);
                    if (instance !== undefined && typeof instance.playEntry === "function") {
                        instance.playEntry();
                    }
                });
            },
            // Hold the entrance until the card has scrolled meaningfully into
            // view: the negative bottom margin pulls the effective trigger line
            // 25% up from the viewport bottom, so a card animates once its top
            // edge is a quarter of the way up the screen — not the instant its
            // first sliver appears.
            { rootMargin: "0px 0px -25% 0px", threshold: 0 },
        );

        nodes.forEach((node) => {
            observer.observe(node);
        });
    }

    initPopovers(root);
    initPlacesPanelTabs(root);
    return { bus, widgets };
}

/**
 * Wire up the Place-of-birth / Recorded-residences / Place-of-death
 * tab-switcher rendered by the PlacesPanel partial. The server ships ALL three
 * panels in the DOM with `.is-active` toggled on the default; a click on a tab
 * swaps that flag + the wrapper's `data-view` attribute (which CSS reads to
 * recolour the accent). No widget re-instantiation — switching is purely a
 * class toggle.
 *
 * @param {ParentNode} root Document fragment to scan.
 */
function initPlacesPanelTabs(root) {
    root.querySelectorAll("[data-wt-stat-places]").forEach((wrap) => {
        const tabs = wrap.querySelectorAll(".wt-stat-places-tab");
        const panels = wrap.querySelectorAll(".wt-stat-places-panel");
        tabs.forEach((tab) => {
            tab.addEventListener("click", () => {
                const targetView = tab.dataset.view;
                if (typeof targetView !== "string" || targetView === "") {
                    return;
                }
                tabs.forEach((other) => {
                    const isActive = other === tab;
                    other.classList.toggle("is-active", isActive);
                    other.setAttribute("aria-selected", isActive ? "true" : "false");
                });
                panels.forEach((panel) => {
                    panel.classList.toggle("is-active", panel.dataset.view === targetView);
                });
                wrap.dataset.view = targetView;
            });
        });
    });
}

/**
 * Wire a single widget into the shared bus: emit clicks via `bus.emit`,
 * re-broadcast incoming selections via the widget's `setSelection` hook.
 * Widgets without a recognisable interface (no `onSelectionChanged` /
 * `setSelection`) are skipped silently so the dispatcher stays additive — a
 * future widget that opts in to the bus only needs to expose the two hooks.
 *
 * The receiver ignores echoes of its own emission so a widget never fights its
 * own click via the round-trip.
 *
 * @param {object|null|undefined} instance
 * @param {DashboardBus}          bus
 * @param {Array<object>}         widgets   Mutated — every instance the bus accepted is pushed.
 * @returns {void}
 */
function connectToBus(instance, bus, widgets) {
    if (instance === null || instance === undefined) {
        return;
    }
    if (typeof instance.onSelectionChanged !== "function") {
        return;
    }
    widgets.push(instance);
    const ownSource = typeof instance.options?.source === "string" ? instance.options.source : "";
    instance.onSelectionChanged((payload) => bus.emit(payload));
    bus.onSelectionChanged((payload) => {
        if (payload.source === ownSource && ownSource !== "") {
            return;
        }
        if (typeof instance.setSelection === "function") {
            instance.setSelection(payload.predicate);
        }
    });
}

/**
 * Parse a JSON-encoded dataset attribute, returning the fallback on missing or
 * unparsable input. Logs the parse error to the console so a corrupt payload is
 * debuggable but never breaks the render loop for sibling widgets.
 *
 * @param {string|undefined} raw      The serialised JSON string.
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
        console.error("renderWidgets: unable to parse widget payload", error);
        return fallback;
    }
}

/**
 * Initialise Bootstrap popovers used by the "About this chart" info buttons.
 * Bootstrap ships with the webtrees vendor bundle and exposes itself on
 * `window.bootstrap`. getOrCreateInstance keeps the call idempotent across
 * re-renders.
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
