/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

/**
 * @jest-environment jsdom
 */

import { describe, expect, jest, test, beforeEach, afterEach } from "@jest/globals";

// Mock the chart-lib widget classes so the dispatcher's wiring
// (parse, lookup, call) can be observed without dragging d3 into
// the test bundle. Each class mock exposes a single `draw` spy
// reachable through the instance.
const donutDrawSpy = jest.fn();
const donutPlayEntrySpy = jest.fn();
const streamGraphDrawSpy = jest.fn();
const sankeyFlowDrawSpy = jest.fn();
const worldMapDrawSpy = jest.fn();
const worldMapPlayEntrySpy = jest.fn();
const lineChartDrawSpy = jest.fn();
const populationPyramidDrawSpy = jest.fn();
const heatmapDrawSpy = jest.fn();

class DonutChart {
    constructor(node, options) {
        this.node = node;
        this.options = options;
    }
    draw(data) {
        donutDrawSpy(this.node, data, this.options);
    }
    playEntry() {
        donutPlayEntrySpy(this.node);
    }
}
class StreamGraph {
    constructor(node, options) {
        this.node = node;
        this.options = options;
    }
    draw(data) {
        streamGraphDrawSpy(this.node, data, this.options);
    }
}
class SankeyFlow {
    constructor(node, options) {
        this.node = node;
        this.options = options;
    }
    draw(data) {
        sankeyFlowDrawSpy(this.node, data, this.options);
    }
}
class WorldMap {
    constructor(node, options) {
        this.node = node;
        this.options = options;
    }
    draw(data) {
        worldMapDrawSpy(this.node, data, this.options);
    }
    playEntry() {
        worldMapPlayEntrySpy(this.node);
    }
}
class LineChart {
    constructor(node, options) {
        this.node = node;
        this.options = options;
    }
    draw(data) {
        lineChartDrawSpy(this.node, data, this.options);
    }
}

// The new chart-lib widgets share the same shape; stub each one
// with a no-op draw so the dispatcher table can be exercised
// without dragging d3 into the test bundle.
class BarChart {
    draw() {}
}
class StackedBar {
    draw() {}
}
class DivergingBar {
    draw() {}
}
class ChordDiagram {
    draw() {}
}
class MonthRadial {
    draw() {}
}
class NameBubbles {
    draw() {}
}
class GaugeArc {
    draw() {}
}
class MirrorHistogram {
    draw() {}
}
class BoxPlot {
    draw() {}
}
class PopulationPyramid {
    constructor(node, options) {
        this.node = node;
        this.options = options;
    }
    draw(data) {
        populationPyramidDrawSpy(this.node, data, this.options);
    }
}
class Heatmap {
    constructor(node, options) {
        this.node = node;
        this.options = options;
    }
    draw(data) {
        heatmapDrawSpy(this.node, data, this.options);
    }
}

jest.unstable_mockModule("@magicsunday/webtrees-chart-lib", () => ({
    DonutChart,
    StreamGraph,
    SankeyFlow,
    WorldMap,
    LineChart,
    BarChart,
    StackedBar,
    DivergingBar,
    ChordDiagram,
    MonthRadial,
    NameBubbles,
    GaugeArc,
    MirrorHistogram,
    BoxPlot,
    PopulationPyramid,
    Heatmap,
}));

// world-map dispatch is async (geojson fetch); mock d3-fetch + d3-geo
// so the fetch resolves to an empty FeatureCollection synchronously.
jest.unstable_mockModule("d3-fetch", () => ({
    json: () => Promise.resolve({ type: "FeatureCollection", features: [] }),
}));
jest.unstable_mockModule("d3-geo", () => ({
    geoMercator: () => ({ fitSize: () => {} }),
}));

const { renderWidgets } = await import("../modules/index.js");

describe("renderWidgets", () => {
    beforeEach(() => {
        donutDrawSpy.mockClear();
        donutPlayEntrySpy.mockClear();
        worldMapDrawSpy.mockClear();
        worldMapPlayEntrySpy.mockClear();
        streamGraphDrawSpy.mockClear();
        sankeyFlowDrawSpy.mockClear();
        lineChartDrawSpy.mockClear();
        populationPyramidDrawSpy.mockClear();
        heatmapDrawSpy.mockClear();
        document.body.innerHTML = "";
    });

    afterEach(() => {
        document.body.innerHTML = "";
        // Drop any per-test visibility/motion overrides so they can't leak
        // into the synchronous (eager-fallback) tests above.
        delete global.IntersectionObserver;
        delete window.matchMedia;
    });

    test("dispatches a donut widget when data-widget=donut", () => {
        document.body.innerHTML = `
            <div id="d1"
                 data-widget="donut"
                 data-payload='[{"label":"Male","value":1,"class":"male"}]'></div>
        `;

        renderWidgets(document.body);

        expect(donutDrawSpy).toHaveBeenCalledTimes(1);
        const [node, data, options] = donutDrawSpy.mock.calls[0];
        expect(node.id).toBe("d1");
        expect(data).toEqual([{ label: "Male", value: 1, class: "male" }]);
        expect(options).toEqual({});
    });

    test("parses data-options as JSON and forwards it to the widget", () => {
        document.body.innerHTML = `
            <div id="s1"
                 data-widget="stream-graph"
                 data-payload='{"decades":[1900],"names":["Anna"],"series":{"Anna":{"1900":1}}}'
                 data-options='{"height":280}'></div>
        `;

        renderWidgets(document.body);

        expect(streamGraphDrawSpy).toHaveBeenCalledTimes(1);
        const [, , options] = streamGraphDrawSpy.mock.calls[0];
        expect(options).toEqual({ height: 280 });
    });

    test("dispatches a population-pyramid widget when data-widget=population-pyramid", () => {
        document.body.innerHTML = `
            <div id="p1"
                 data-widget="population-pyramid"
                 data-payload='{"groups":["19th cent."],"bands":["0–9"],"data":[[{"left":2,"right":1}]]}'
                 data-options='{"height":460,"leftLabel":"Male","rightLabel":"Female"}'></div>
        `;

        renderWidgets(document.body);

        expect(populationPyramidDrawSpy).toHaveBeenCalledTimes(1);
        const [node, data, options] = populationPyramidDrawSpy.mock.calls[0];
        expect(node.id).toBe("p1");
        expect(data).toEqual({
            groups: ["19th cent."],
            bands: ["0–9"],
            data: [[{ left: 2, right: 1 }]],
        });
        expect(options.leftLabel).toBe("Male");
        expect(options.rightLabel).toBe("Female");
    });

    test("dispatches a heatmap widget when data-widget=heatmap", () => {
        document.body.innerHTML = `
            <div id="h1"
                 data-widget="heatmap"
                 data-payload='{"rows":["1900s"],"cols":["Jan","Feb"],"values":[[3,1]]}'
                 data-options='{"height":460,"accent":"var(--ochre)","valueLabel":"Births"}'></div>
        `;

        renderWidgets(document.body);

        expect(heatmapDrawSpy).toHaveBeenCalledTimes(1);
        const [node, data, options] = heatmapDrawSpy.mock.calls[0];
        expect(node.id).toBe("h1");
        expect(data).toEqual({
            rows: ["1900s"],
            cols: ["Jan", "Feb"],
            values: [[3, 1]],
        });
        expect(options.accent).toBe("var(--ochre)");
        expect(options.valueLabel).toBe("Births");
    });

    test("ignores nodes without data-widget attribute", () => {
        document.body.innerHTML = '<div id="x1"></div>';

        renderWidgets(document.body);

        expect(donutDrawSpy).not.toHaveBeenCalled();
        expect(worldMapDrawSpy).not.toHaveBeenCalled();
        expect(streamGraphDrawSpy).not.toHaveBeenCalled();
        expect(sankeyFlowDrawSpy).not.toHaveBeenCalled();
    });

    test("skips unknown widget types without throwing", () => {
        document.body.innerHTML = '<div data-widget="unknown-widget" data-payload="[]"></div>';

        expect(() => renderWidgets(document.body)).not.toThrow();
        expect(donutDrawSpy).not.toHaveBeenCalled();
    });

    test("recovers from a corrupt data-payload by passing the fallback", () => {
        // The dispatcher logs the parse error but must keep dispatching
        // so a single malformed widget doesn't take down the whole tab.
        const errorSpy = jest.spyOn(console, "error").mockImplementation(() => {});
        document.body.innerHTML = `
            <div data-widget="donut" data-payload="not-json"></div>
            <div data-widget="donut" data-payload='[{"label":"X","value":2}]'></div>
        `;

        renderWidgets(document.body);

        expect(errorSpy).toHaveBeenCalled();
        expect(donutDrawSpy).toHaveBeenCalledTimes(2);
        expect(donutDrawSpy.mock.calls[0][1]).toBeNull();
        expect(donutDrawSpy.mock.calls[1][1]).toEqual([{ label: "X", value: 2 }]);

        errorSpy.mockRestore();
    });

    test("dispatches every widget type in a multi-card tab", async () => {
        document.body.innerHTML = `
            <div data-widget="donut"        data-payload='[]'></div>
            <div data-widget="world-map"    data-payload='[]'></div>
            <div data-widget="stream-graph" data-payload='{}'></div>
            <div data-widget="sankey-flow"  data-payload='{}'></div>
        `;

        renderWidgets(document.body);

        // world-map resolves through a Promise — flush the microtask queue.
        await Promise.resolve();
        await Promise.resolve();

        expect(donutDrawSpy).toHaveBeenCalledTimes(1);
        expect(worldMapDrawSpy).toHaveBeenCalledTimes(1);
        expect(streamGraphDrawSpy).toHaveBeenCalledTimes(1);
        expect(sankeyFlowDrawSpy).toHaveBeenCalledTimes(1);
    });

    test("initialises Bootstrap popovers attached to chart-header info buttons", () => {
        const getOrCreateInstance = jest.fn();
        window.bootstrap = { Popover: { getOrCreateInstance } };

        document.body.innerHTML = `
            <div class="wt-statistics-chart">
                <button type="button" data-bs-toggle="popover" data-bs-content="x"></button>
            </div>
        `;

        renderWidgets(document.body);

        expect(getOrCreateInstance).toHaveBeenCalledTimes(1);
        expect(getOrCreateInstance.mock.calls[0][1]).toEqual({ container: "body" });

        delete window.bootstrap;
    });

    test("skips popover init when window.bootstrap is unavailable", () => {
        document.body.innerHTML = `
            <div class="wt-statistics-chart">
                <button type="button" data-bs-toggle="popover" data-bs-content="x"></button>
            </div>
        `;

        expect(() => renderWidgets(document.body)).not.toThrow();
    });

    test("returns a DashboardBus instance and the connected widgets", () => {
        document.body.innerHTML = `
            <div id="d1"
                 data-widget="donut"
                 data-payload='[{"label":"Male","value":1}]'></div>
        `;

        const result = renderWidgets(document.body);

        expect(result.bus).toBeDefined();
        expect(typeof result.bus.emit).toBe("function");
        expect(typeof result.bus.onSelectionChanged).toBe("function");
        // The donut mock has no onSelectionChanged hook, so the
        // dispatcher quietly skips it — connected widgets stay empty.
        expect(result.widgets).toEqual([]);
    });

    test("connects every widget exposing onSelectionChanged to the shared bus", () => {
        // Bus-aware donut: emits via onSelectionChanged, receives via setSelection.
        let emitCallback = null;
        const setSelectionSpy = jest.fn();

        class BusAwareDonut {
            constructor(node, options) {
                this.node = node;
                this.options = options;
            }
            draw() {}
            onSelectionChanged(cb) {
                emitCallback = cb;
                return this;
            }
            setSelection(predicate) {
                setSelectionSpy(predicate);
            }
        }

        // Stub the dispatch table entry with the bus-aware variant.
        // Because Jest's ESM module mocks are immutable, swap the
        // donut spy chain via a fresh dispatch instead of re-mocking.
        document.body.innerHTML = `
            <div id="bus-a" data-widget="donut" data-payload='[]'
                 data-options='{"source":"donut.a"}'></div>
            <div id="bus-b" data-widget="donut" data-payload='[]'
                 data-options='{"source":"donut.b"}'></div>
        `;

        // Patch the global DonutChart for this test only.
        // (Cleaner than re-mocking; mock is module-scoped.)
        const originalDraw = DonutChart.prototype.draw;
        DonutChart.prototype.onSelectionChanged = BusAwareDonut.prototype.onSelectionChanged;
        DonutChart.prototype.setSelection = BusAwareDonut.prototype.setSelection;

        const result = renderWidgets(document.body);

        // Both donuts connected.
        expect(result.widgets.length).toBe(2);

        // Emit from donut.a; donut.b's setSelection receives the predicate,
        // donut.a's does NOT (echo suppression by source).
        const otherSpy = jest.fn();
        result.widgets[1].setSelection = otherSpy;
        emitCallback({ source: "donut.a", predicate: { slice: "Male" } });
        expect(otherSpy).toHaveBeenCalledWith({ slice: "Male" });

        // Cleanup
        delete DonutChart.prototype.onSelectionChanged;
        delete DonutChart.prototype.setSelection;
        DonutChart.prototype.draw = originalDraw;
    });

    test("draws every widget eagerly and plays its entrance once the node scrolls into view", () => {
        // Fake IntersectionObserver: records observed nodes and lets the test
        // fire the intersection callback manually.
        const observers = [];
        global.IntersectionObserver = class {
            constructor(callback) {
                this.callback = callback;
                this.observed = [];
                this.unobserved = [];
                observers.push(this);
            }
            observe(node) {
                this.observed.push(node);
            }
            unobserve(node) {
                this.unobserved.push(node);
            }
            disconnect() {}
        };

        document.body.innerHTML = `
            <div id="d1" data-widget="donut" data-payload='[]'></div>
        `;

        renderWidgets(document.body);

        // Eager: the widget is drawn up front (in the DOM + on the bus) with its
        // entrance held via animateOnReveal — but playEntry has NOT fired yet.
        expect(donutDrawSpy).toHaveBeenCalledTimes(1);
        expect(donutDrawSpy.mock.calls[0][2]).toMatchObject({ animateOnReveal: true });
        expect(donutPlayEntrySpy).not.toHaveBeenCalled();
        expect(observers).toHaveLength(1);
        expect(observers[0].observed).toHaveLength(1);

        // Card scrolls into view → entrance plays once, node is unobserved.
        const node = observers[0].observed[0];
        observers[0].callback([{ target: node, isIntersecting: true }], observers[0]);

        expect(donutPlayEntrySpy).toHaveBeenCalledTimes(1);
        expect(observers[0].unobserved).toContain(node);
        // playEntry never re-draws.
        expect(donutDrawSpy).toHaveBeenCalledTimes(1);
    });

    test("does not play the entrance for a non-intersecting entry", () => {
        const observers = [];
        global.IntersectionObserver = class {
            constructor(callback) {
                this.callback = callback;
                this.unobserved = [];
                observers.push(this);
            }
            observe() {}
            unobserve(node) {
                this.unobserved.push(node);
            }
            disconnect() {}
        };

        document.body.innerHTML = `<div id="d1" data-widget="donut" data-payload='[]'></div>`;
        renderWidgets(document.body);

        const node = document.getElementById("d1");
        // A leaving-viewport entry (isIntersecting=false) must be ignored.
        observers[0].callback([{ target: node, isIntersecting: false }], observers[0]);

        // Drawn eagerly, but the entrance has not played and the node stays observed.
        expect(donutDrawSpy).toHaveBeenCalledTimes(1);
        expect(donutPlayEntrySpy).not.toHaveBeenCalled();
        expect(observers[0].unobserved).not.toContain(node);
    });

    test("plays the entrance inline (no reveal-gating) under prefers-reduced-motion", () => {
        window.matchMedia = jest.fn().mockReturnValue({ matches: true });
        // Even with an observer available, reduced motion disables reveal-gating:
        // the widget draws without animateOnReveal and the observer is never set up.
        const observe = jest.fn();
        global.IntersectionObserver = class {
            observe(node) {
                observe(node);
            }
            unobserve() {}
            disconnect() {}
        };

        document.body.innerHTML = `<div id="d1" data-widget="donut" data-payload='[]'></div>`;
        renderWidgets(document.body);

        expect(donutDrawSpy).toHaveBeenCalledTimes(1);
        // animateOnReveal is NOT set — the widget animates inline on draw.
        expect(donutDrawSpy.mock.calls[0][2].animateOnReveal).toBeUndefined();
        // The dispatcher never engaged the observer or called playEntry.
        expect(observe).not.toHaveBeenCalled();
        expect(donutPlayEntrySpy).not.toHaveBeenCalled();
    });

    test("plays the entrance immediately for a card already in the viewport (no observer wait)", () => {
        const observers = [];
        global.IntersectionObserver = class {
            constructor(callback) {
                this.callback = callback;
                this.observed = [];
                observers.push(this);
            }
            observe(node) {
                this.observed.push(node);
            }
            unobserve() {}
            disconnect() {}
        };

        document.body.innerHTML = `<div id="d1" data-widget="donut" data-payload='[]'></div>`;
        const node = document.getElementById("d1");
        // Pretend the card is on-screen — jsdom's default all-zero rect reads as
        // not-visible, so a visible rect must be stubbed. This guards the
        // bottom-band / non-scrollable-page case: a visible card must play even
        // though the negative-margin observer would never fire for it.
        node.getBoundingClientRect = () => ({
            top: 100,
            bottom: 200,
            left: 0,
            right: 0,
            width: 0,
            height: 100,
        });

        renderWidgets(document.body);

        // Visible at render → entrance plays now; the observer is never armed.
        expect(donutPlayEntrySpy).toHaveBeenCalledTimes(1);
        expect(observers[0].observed).not.toContain(node);
    });

    test("arms an async (world-map) widget's reveal only after its promise resolves", async () => {
        const observers = [];
        global.IntersectionObserver = class {
            constructor(callback) {
                this.callback = callback;
                this.observed = [];
                this.unobserved = [];
                observers.push(this);
            }
            observe(node) {
                this.observed.push(node);
            }
            unobserve(node) {
                this.unobserved.push(node);
            }
            disconnect() {}
        };

        document.body.innerHTML = `<div id="w1" data-widget="world-map" data-payload='[]'></div>`;
        const node = document.getElementById("w1");
        // On-screen, so once the async instance resolves it plays immediately —
        // and is never observed-then-unobserved before the instance exists.
        node.getBoundingClientRect = () => ({
            top: 0,
            bottom: 200,
            left: 0,
            right: 0,
            width: 0,
            height: 200,
        });

        renderWidgets(document.body);

        // Async: nothing played synchronously — the instance isn't resolved yet.
        expect(worldMapPlayEntrySpy).not.toHaveBeenCalled();

        // Flush the geojson Promise + the .then chain.
        await Promise.resolve();
        await Promise.resolve();

        // The resolved instance's entrance is now armed (played, since visible),
        // with no premature observe/unobserve on the still-pending node.
        expect(worldMapPlayEntrySpy).toHaveBeenCalledTimes(1);
        expect(observers[0].observed).not.toContain(node);
    });

    test("returns a disconnect() that tears down the observer and motion listener", () => {
        const disconnectSpy = jest.fn();
        global.IntersectionObserver = class {
            constructor(callback) {
                this.callback = callback;
            }
            observe() {}
            unobserve() {}
            disconnect() {
                disconnectSpy();
            }
        };
        const removeListenerSpy = jest.fn();
        window.matchMedia = jest.fn().mockReturnValue({
            matches: false,
            addEventListener: () => {},
            removeEventListener: removeListenerSpy,
        });

        document.body.innerHTML = `<div id="d1" data-widget="donut" data-payload='[]'></div>`;
        const result = renderWidgets(document.body);

        expect(typeof result.disconnect).toBe("function");

        result.disconnect();

        expect(disconnectSpy).toHaveBeenCalledTimes(1);
        expect(removeListenerSpy).toHaveBeenCalledTimes(1);
    });

    test("fast-forwards held entrances and stops arming when reduced-motion turns on after render", () => {
        const observers = [];
        global.IntersectionObserver = class {
            constructor(callback) {
                this.callback = callback;
                this.observed = [];
                this.disconnected = false;
                observers.push(this);
            }
            observe(node) {
                this.observed.push(node);
            }
            unobserve() {}
            disconnect() {
                this.disconnected = true;
            }
        };
        // matchMedia starts at matches:false (reveal-gating active) and captures
        // the change handler so the test can flip the preference mid-life.
        let changeHandler = null;
        window.matchMedia = jest.fn().mockReturnValue({
            matches: false,
            addEventListener: (event, handler) => {
                changeHandler = handler;
            },
            removeEventListener: () => {},
        });

        document.body.innerHTML = `<div id="d1" data-widget="donut" data-payload='[]'></div>`;
        renderWidgets(document.body);

        // Off-screen at render (default jsdom rect) → entrance HELD, node observed,
        // nothing played yet.
        expect(donutPlayEntrySpy).not.toHaveBeenCalled();
        expect(observers[0].observed).toHaveLength(1);
        expect(typeof changeHandler).toBe("function");

        // User turns on reduce-motion: the held entrance fast-forwards and the
        // observer is disconnected so no further reveals arm.
        changeHandler({ matches: true });

        expect(donutPlayEntrySpy).toHaveBeenCalledTimes(1);
        expect(observers[0].disconnected).toBe(true);
    });

    test("ignores a reduced-motion change that turns the preference back off", () => {
        const observers = [];
        global.IntersectionObserver = class {
            constructor(callback) {
                this.callback = callback;
                this.observed = [];
                this.disconnected = false;
                observers.push(this);
            }
            observe(node) {
                this.observed.push(node);
            }
            unobserve() {}
            disconnect() {
                this.disconnected = true;
            }
        };
        let changeHandler = null;
        window.matchMedia = jest.fn().mockReturnValue({
            matches: false,
            addEventListener: (event, handler) => {
                changeHandler = handler;
            },
            removeEventListener: () => {},
        });

        document.body.innerHTML = `<div id="d1" data-widget="donut" data-payload='[]'></div>`;
        renderWidgets(document.body);

        // A change to matches:false (reduce turned OFF) must be a no-op: the
        // held entrance keeps waiting for its scroll-in reveal.
        changeHandler({ matches: false });

        expect(donutPlayEntrySpy).not.toHaveBeenCalled();
        expect(observers[0].disconnected).toBe(false);

        // Prove the held entry SURVIVED the no-op (distinct from a teardown): a
        // later scroll-in still reveals it exactly once.
        const node = document.getElementById("d1");
        observers[0].callback([{ target: node, isIntersecting: true }], observers[0]);
        expect(donutPlayEntrySpy).toHaveBeenCalledTimes(1);
    });

    test("plays an async widget inline (no observer re-arm) when reduced-motion turned on before it resolved", async () => {
        const observers = [];
        global.IntersectionObserver = class {
            constructor(callback) {
                this.callback = callback;
                this.observed = [];
                this.disconnected = false;
                observers.push(this);
            }
            observe(node) {
                this.observed.push(node);
            }
            unobserve() {}
            disconnect() {
                this.disconnected = true;
            }
        };
        let changeHandler = null;
        window.matchMedia = jest.fn().mockReturnValue({
            matches: false,
            addEventListener: (event, handler) => {
                changeHandler = handler;
            },
            removeEventListener: () => {},
        });

        document.body.innerHTML = `<div id="w1" data-widget="world-map" data-payload='[]'></div>`;
        const node = document.getElementById("w1");
        // Off-screen, so absent the retire it would defer to the observer.
        node.getBoundingClientRect = () => ({
            top: 10000,
            bottom: 10100,
            left: 0,
            right: 0,
            width: 0,
            height: 100,
        });

        renderWidgets(document.body);

        // Reduce-motion turns on while the geojson promise is still pending —
        // the world-map's .then has not run yet, so it is not in `held`.
        changeHandler({ matches: true });
        expect(observers[0].disconnected).toBe(true);
        expect(worldMapPlayEntrySpy).not.toHaveBeenCalled();

        // Flush the geojson promise + .then chain.
        await Promise.resolve();
        await Promise.resolve();

        // The resolved map plays its entrance inline (final state reached) and
        // is NEVER re-observed on the disconnected observer.
        expect(worldMapPlayEntrySpy).toHaveBeenCalledTimes(1);
        expect(observers[0].observed).not.toContain(node);
    });
});
