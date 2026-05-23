/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import { json } from "d3-fetch";
import { geoMercator } from "d3-geo";

import {
    AreaDensity,
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
 * Lazily load (and cache) the world GeoJSON. The chart-lib WorldMap
 * widget needs the FeatureCollection up-front in its options; we
 * fetch it once per page load and reuse for every map render.
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
 * Adapter that turns a chart-lib widget class (`new Widget(node, opts).draw(data)`)
 * into the functional `(node, data, options)` shape the dispatcher
 * uses. Keeps the dispatch table flat.
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
 * Asynchronous world-map dispatcher. Fetches (and caches) the
 * geojson, then hands it to the chart-lib WorldMap widget alongside
 * a d3-geo Mercator projection. Same async return shape as the
 * other widgets even though they resolve synchronously, so callers
 * never have to special-case the map.
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
 * Dispatch table mapping a `data-widget` attribute value to its
 * draw function. Every widget is a chart-lib widget; the world map
 * just needs a pre-fetch hop to load the GeoJSON the widget
 * consumes via its constructor.
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
    "area-density": fromChartLib(AreaDensity),
    "box-plot": fromChartLib(BoxPlot),
    "chord-diagram": fromChartLib(ChordDiagram),
    "name-bubbles": fromChartLib(NameBubbles),
    "month-radial": fromChartLib(MonthRadial),
    "gauge-arc": fromChartLib(GaugeArc),
    "mirror-histogram": fromChartLib(MirrorHistogram),
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
    const bus = new DashboardBus();
    const widgets = [];

    nodes.forEach((node) => {
        const widget = WIDGETS[node.dataset.widget];

        if (widget === undefined) {
            return;
        }

        const data = parseJsonAttribute(node.dataset.payload, null);
        const options = parseJsonAttribute(node.dataset.options, {});
        const instance = widget(node, data, options);

        // Async widgets (world-map) return a Promise instead of the
        // widget instance; connect the bus inside the .then so the
        // wiring happens once the widget has actually rendered.
        if (instance instanceof Promise) {
            instance.then((resolved) => connectToBus(resolved, bus, widgets));
        } else {
            connectToBus(instance, bus, widgets);
        }
    });

    initPopovers(root);
    return { bus, widgets };
}

/**
 * Wire a single widget into the shared bus: emit clicks via
 * `bus.emit`, re-broadcast incoming selections via the widget's
 * `setSelection` hook. Widgets without a recognisable interface
 * (no `onSelectionChanged` / `setSelection`) are skipped silently
 * so the dispatcher stays additive — a future widget that opts in
 * to the bus only needs to expose the two hooks.
 *
 * The receiver ignores echoes of its own emission so a widget never
 * fights its own click via the round-trip.
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
 * Parse a JSON-encoded dataset attribute, returning the fallback on
 * missing or unparsable input. Logs the parse error to the console
 * so a corrupt payload is debuggable but never breaks the render
 * loop for sibling widgets.
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
