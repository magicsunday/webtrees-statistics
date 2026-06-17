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
    DivergingBarChart,
    DonutChart,
    EventTimeline,
    GaugeArc,
    Heatmap,
    LineChart,
    MirrorHistogram,
    MonthRadial,
    NameBubbles,
    NameTimeline,
    SankeyFlow,
    StackedBar,
    StreamGraph,
    Treemap,
    WorldMap,
} from "@magicsunday/webtrees-chart-lib";

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
        const raw = /** @type {import("geojson").FeatureCollection} */ (
            await json(WORLD_GEOJSON_URL)
        );
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
    let geojson;

    try {
        geojson = await loadWorldGeoJson();
    } catch (error) {
        // A failed geojson fetch must not surface as an unhandled rejection or
        // leave a blank gap — render the shared empty-state and bow out. (The
        // WorldMap constructor itself throws without geojson, so we render the
        // placeholder directly rather than instantiating it.)
        console.error("renderWidgets: world-map geojson failed to load", error);
        const placeholder = document.createElement("div");
        placeholder.className = "chart-empty-state";
        // A load failure is distinct from "no data": prefer the localised
        // error copy the partial ships, falling back to the empty-state message.
        placeholder.textContent =
            node.dataset.errorMessage ||
            (typeof options.emptyMessage === "string" ? options.emptyMessage : "");
        node.replaceChildren(placeholder);
        return null;
    }

    const widget = new WorldMap(node, {
        ...options,
        geojson,
        projection: geoMercator(),
    });
    // The dispatcher carries every widget's payload as `unknown`; the WorldMap
    // draw signature is concrete, so narrow at this boundary the same way the
    // `fromChartLib` adapter treats its `(data: unknown)` draw calls.
    widget.draw(/** @type {Parameters<WorldMap["draw"]>[0]} */ (data));
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
    "donut-chart": fromChartLib(DonutChart),
    "world-map": drawWorldMap,
    "stream-graph": fromChartLib(StreamGraph),
    "sankey-flow": fromChartLib(SankeyFlow),
    "line-chart": fromChartLib(LineChart),
    "bar-chart": fromChartLib(BarChart),
    "stacked-bar": fromChartLib(StackedBar),
    "chord-diagram": fromChartLib(ChordDiagram),
    "name-bubbles": fromChartLib(NameBubbles),
    "name-timeline": fromChartLib(NameTimeline),
    "month-radial": fromChartLib(MonthRadial),
    "gauge-arc": fromChartLib(GaugeArc),
    "event-timeline": fromChartLib(EventTimeline),
    "mirror-histogram": fromChartLib(MirrorHistogram),
    "box-plot": fromChartLib(BoxPlot),
    "population-pyramid": fromChartLib(DivergingBarChart),
    heatmap: fromChartLib(Heatmap),
    treemap: fromChartLib(Treemap),
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
 * @returns {{widgets: Array<object>, disconnect: () => void}}
 *          The rendered widget instances and a teardown that disconnects the
 *          reveal observer and drops the reduced-motion listener.
 */
export function renderWidgets(root) {
    const nodes = /** @type {NodeListOf<HTMLElement>} */ (root.querySelectorAll("[data-widget]"));
    const widgets = [];

    // Reveal-on-scroll: every widget is drawn up front — in the DOM immediately
    // (print, in-page search) — with animated entrances HELD at their initial
    // keyframe. A card already within
    // the viewport plays its entrance right away; an off-screen card waits for
    // an IntersectionObserver to reveal it once it scrolls a quarter into view.
    // Eager draw keeps the layout stable (each chart sizes itself on draw).
    // Disabled under reduced motion (no entrance to gate) or when
    // IntersectionObserver is unavailable (older browser, jsdom): the entrance
    // then plays inline on draw.
    const motionQuery =
        typeof window.matchMedia === "function"
            ? window.matchMedia("(prefers-reduced-motion: reduce)")
            : null;
    const reduceMotion = motionQuery?.matches === true;
    const revealOnScroll = reduceMotion === false && typeof IntersectionObserver !== "undefined";
    // node → instance for cards whose entrance is HELD waiting to be revealed.
    // A Map (not a WeakMap) so a late `prefers-reduced-motion` toggle can iterate
    // the still-held entries and fast-forward them; entries are removed as each
    // card reveals, and the whole map is cleared on teardown.
    const held = revealOnScroll ? new Map() : null;
    // Collected during the draw pass and revealed in a second pass, so the
    // getBoundingClientRect reads batch into a single layout flush instead of
    // forcing a reflow after every widget's draw.
    const pendingReveals = revealOnScroll ? [] : null;
    // Latched once the reveal machinery is retired — either by a late
    // reduced-motion switch or by an explicit disconnect(). The async world-map
    // arms its reveal in a `.then` that runs after this function returns, so it
    // must re-check this before touching the (possibly already disconnected)
    // observer.
    let revealRetired = false;

    /**
     * Play a widget's held entrance if it exposes one. Idempotent — BaseWidget
     * clears the stored entry after the first call.
     *
     * @param {object|null|undefined} instance
     *
     * @returns {void}
     */
    const playEntry = (instance) => {
        if (
            instance !== null &&
            instance !== undefined &&
            typeof instance.playEntry === "function"
        ) {
            instance.playEntry();
        }
    };

    const observer = revealOnScroll
        ? new IntersectionObserver(
              (entries, obs) => {
                  entries.forEach((entry) => {
                      if (entry.isIntersecting === false) {
                          return;
                      }

                      // One-shot per node: stop watching, then play (no re-draw).
                      obs.unobserve(entry.target);
                      playEntry(held.get(entry.target));
                      held.delete(entry.target);
                  });
              },
              // Negative bottom margin pulls the trigger line a quarter up from
              // the viewport bottom, so an off-screen card animates once its top
              // edge is a quarter of the way up — not at the first sliver.
              { rootMargin: "0px 0px -25% 0px", threshold: 0 },
          )
        : null;

    /**
     * Reveal a freshly-drawn widget: play its entrance now if the card is
     * already visible — above the fold, OR sitting in the bottom band of a short
     * page that cannot scroll the observer's trigger line into reach, which
     * would otherwise leave it invisible forever — else defer to the observer.
     *
     * @param {Element}               node
     * @param {object|null|undefined} instance
     *
     * @returns {void}
     */
    const revealWhenSeen = (node, instance) => {
        const rect = node.getBoundingClientRect();
        if (rect.top < window.innerHeight && rect.bottom > 0) {
            playEntry(instance);
            return;
        }

        held.set(node, instance);
        observer.observe(node);
    };

    /**
     * Respond to a `prefers-reduced-motion` change that arrives AFTER render.
     * When the user turns reduce-motion on, every entrance still held for an
     * off-screen card is fast-forwarded immediately and the observer stops
     * arming new reveals — so the comfort setting takes effect without waiting
     * for a navigation. Turning reduce-motion back off is ignored: already-drawn
     * cards keep whatever path they were assigned.
     *
     * @param {MediaQueryListEvent} event
     *
     * @returns {void}
     */
    const onMotionPreferenceChange = (event) => {
        if (event.matches !== true) {
            return;
        }

        revealRetired = true;
        held.forEach((instance) => {
            playEntry(instance);
        });
        held.clear();
        observer.disconnect();

        // One-shot: the machinery is now retired, so drop this listener and let
        // its closure be collected. disconnect() removing it again is harmless.
        if (typeof motionQuery.removeEventListener === "function") {
            motionQuery.removeEventListener("change", onMotionPreferenceChange);
        }
    };

    if (
        revealOnScroll &&
        motionQuery !== null &&
        typeof motionQuery.addEventListener === "function"
    ) {
        motionQuery.addEventListener("change", onMotionPreferenceChange);
    }

    /**
     * Draw a single widget node and collect its rendered instance.
     *
     * @param {HTMLElement} node
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

        // Hold the entrance until the card is revealed; revealWhenSeen starts it.
        if (revealOnScroll) {
            options.animateOnReveal = true;
        }

        const instance = widget(node, data, options);

        // Async widgets (world-map) return a Promise instead of the widget
        // instance; collect the instance and arm the reveal inside the .then so
        // the instance is known before it is observed (no unobserve-before-resolve
        // race).
        if (instance instanceof Promise) {
            // Async widgets resolve in their own microtask, after the draw pass
            // has flushed layout, so they can reveal directly.
            instance.then((resolved) => {
                // The statistics chart is loaded into the page over AJAX, so a
                // tab swap or reload can detach this node before the async widget
                // resolves. Abort if it is no longer in the document — touching a
                // detached node would only leak a held reference.
                if (!node.isConnected) {
                    return;
                }

                if (resolved === null || resolved === undefined) {
                    return;
                }

                widgets.push(resolved);

                if (revealOnScroll === false) {
                    return;
                }

                // The reveal machinery may already be retired (a reduced-motion
                // switch or a disconnect() fired before this promise resolved).
                // Re-arming the disconnected observer would resurface the card
                // through the animated reveal path and leak a held reference, so
                // play the entrance inline instead — the card reaches its final
                // state without re-observing.
                if (revealRetired) {
                    playEntry(resolved);
                    return;
                }

                revealWhenSeen(node, resolved);
            });
        } else if (instance !== null && instance !== undefined) {
            widgets.push(instance);

            if (pendingReveals !== null) {
                pendingReveals.push({ node, instance });
            }
        }
    };

    // Pass 1: draw every widget (layout-affecting writes batch together).
    nodes.forEach((node) => {
        renderNode(node);
    });

    // Pass 2: arm the reveals. All draws are done, so the getBoundingClientRect
    // reads here trigger a single layout flush rather than one per widget.
    if (pendingReveals !== null) {
        pendingReveals.forEach(({ node, instance }) => {
            revealWhenSeen(node, instance);
        });
    }

    initPopovers(root);
    initPlacesPanelTabs(root);

    /**
     * Tear down the reveal machinery this render created: disconnect the
     * IntersectionObserver and drop the `prefers-reduced-motion` listener so a
     * caller that remounts the statistics page (Turbo/SPA-style, without a full
     * reload) leaves no orphaned observer or listener behind. A no-op when
     * reveal-on-scroll was never armed (reduced motion / no IntersectionObserver).
     *
     * @returns {void}
     */
    const disconnect = () => {
        revealRetired = true;

        if (observer !== null) {
            observer.disconnect();
        }

        if (motionQuery !== null && typeof motionQuery.removeEventListener === "function") {
            motionQuery.removeEventListener("change", onMotionPreferenceChange);
        }

        if (held !== null) {
            held.clear();
        }
    };

    return { widgets, disconnect };
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
    const wraps = /** @type {NodeListOf<HTMLElement>} */ (
        root.querySelectorAll("[data-wt-stat-places]")
    );

    wraps.forEach((wrap) => {
        const tabs = /** @type {NodeListOf<HTMLElement>} */ (
            wrap.querySelectorAll(".wt-stat-places-tab")
        );
        const panels = /** @type {NodeListOf<HTMLElement>} */ (
            wrap.querySelectorAll(".wt-stat-places-panel")
        );
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
    // Bootstrap is not part of the page's typed globals — it rides in on the
    // webtrees vendor bundle and self-registers on `window.bootstrap`. Read it
    // through a single cast rather than annotating the global Window type.
    const bootstrap = /** @type {{ Popover?: { getOrCreateInstance: Function } } | undefined } */ (
        /** @type {{ bootstrap?: unknown }} */ (window).bootstrap
    );

    if (bootstrap === undefined || bootstrap.Popover === undefined) {
        return;
    }

    const { Popover } = bootstrap;

    root.querySelectorAll('.wt-statistics-chart [data-bs-toggle="popover"]').forEach((element) => {
        Popover.getOrCreateInstance(element, { container: "body" });
    });
}
