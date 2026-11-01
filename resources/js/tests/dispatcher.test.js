/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

/**
 * @jest-environment jsdom
 */

import { readdirSync, readFileSync } from "node:fs";
import { join } from "node:path";
import { fileURLToPath } from "node:url";

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
const treemapDrawSpy = jest.fn();
const networkGraphDrawSpy = jest.fn();
const sequenceChainDrawSpy = jest.fn();

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
class ChordDiagram {
    draw() {}
}
class MonthRadial {
    draw() {}
}
class NameBubbles {
    draw() {}
}
class NameTimeline {
    draw() {}
}
class GaugeArc {
    draw() {}
}
class EventTimeline {
    draw() {}
}
class MirrorHistogram {
    draw() {}
}
class BoxPlot {
    draw() {}
}
class DivergingBarChart {
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
class Treemap {
    constructor(node, options) {
        this.node = node;
        this.options = options;
    }
    draw(data) {
        treemapDrawSpy(this.node, data, this.options);
    }
}
class NetworkGraph {
    constructor(node, options) {
        this.node = node;
        this.options = options;
    }
    draw(data) {
        networkGraphDrawSpy(this.node, data, this.options);
    }
}
class SequenceChain {
    constructor(node, options) {
        this.node = node;
        this.options = options;
    }
    draw(data) {
        sequenceChainDrawSpy(this.node, data, this.options);
    }
}

jest.unstable_mockModule("@magicsunday/webtrees-chart-lib/widgets", () => ({
    DonutChart,
    StreamGraph,
    SankeyFlow,
    WorldMap,
    LineChart,
    BarChart,
    StackedBar,
    ChordDiagram,
    MonthRadial,
    NameBubbles,
    NameTimeline,
    GaugeArc,
    EventTimeline,
    MirrorHistogram,
    BoxPlot,
    DivergingBarChart,
    Heatmap,
    Treemap,
    NetworkGraph,
    SequenceChain,
}));

// world-map dispatch is async (geojson fetch); mock d3-fetch + d3-geo
// so the fetch resolves to an empty FeatureCollection synchronously. A spy
// rather than a plain stub, so the requested URL can be asserted.
const geoJsonFetchSpy = jest.fn(() => Promise.resolve({ type: "FeatureCollection", features: [] }));

jest.unstable_mockModule("d3-fetch", () => ({
    json: geoJsonFetchSpy,
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
        treemapDrawSpy.mockClear();
        networkGraphDrawSpy.mockClear();
        sequenceChainDrawSpy.mockClear();
        document.body.innerHTML = "";
    });

    afterEach(() => {
        document.body.innerHTML = "";
        // Drop any per-test visibility/motion overrides so they can't leak
        // into the synchronous (eager-fallback) tests above.
        delete global.IntersectionObserver;
        delete window.matchMedia;
    });

    test("dispatches a donut widget when data-widget=donut-chart", () => {
        document.body.innerHTML = `
            <div id="d1"
                 data-widget="donut-chart"
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

    test("dispatches a treemap widget when data-widget=treemap", () => {
        document.body.innerHTML = `
            <div id="t1"
                 data-widget="treemap"
                 data-payload='{"items":[{"rank":1,"members":42,"label":"Meinel"}],"restMembers":7}'
                 data-options='{"height":360,"accent":"var(--slate)","valueLabel":"persons","restLabel":"other islands"}'></div>
        `;

        renderWidgets(document.body);

        expect(treemapDrawSpy).toHaveBeenCalledTimes(1);
        const [node, data, options] = treemapDrawSpy.mock.calls[0];
        expect(node.id).toBe("t1");
        expect(data).toEqual({
            items: [{ rank: 1, members: 42, label: "Meinel" }],
            restMembers: 7,
        });
        expect(options.accent).toBe("var(--slate)");
        expect(options.restLabel).toBe("other islands");
    });

    test("dispatches a network-graph widget when data-widget=network-graph", () => {
        document.body.innerHTML = `
            <div id="n1"
                 data-widget="network-graph"
                 data-payload='{"nodes":[{"id":"I1","label":"Anna","group":"F"}],"links":[],"hubId":"I1"}'
                 data-options='{"height":360,"accent":"var(--rose)"}'></div>
        `;

        expect(() => renderWidgets(document.body)).not.toThrow();

        expect(networkGraphDrawSpy).toHaveBeenCalledTimes(1);
        const [node, data, options] = networkGraphDrawSpy.mock.calls[0];
        expect(node.id).toBe("n1");
        expect(data).toEqual({
            nodes: [{ id: "I1", label: "Anna", group: "F" }],
            links: [],
            hubId: "I1",
        });
        expect(options.accent).toBe("var(--rose)");
    });

    test("dispatches a sequence-chain widget when data-widget=sequence-chain", () => {
        document.body.innerHTML = `
            <div id="c1"
                 data-widget="sequence-chain"
                 data-payload='{"items":[{"label":"Anna","group":"F"},{"label":"Bert","group":"M"}]}'
                 data-options='{"accent":"var(--rose)"}'></div>
        `;

        expect(() => renderWidgets(document.body)).not.toThrow();

        expect(sequenceChainDrawSpy).toHaveBeenCalledTimes(1);
        const [node, data, options] = sequenceChainDrawSpy.mock.calls[0];
        expect(node.id).toBe("c1");
        expect(data).toEqual({
            items: [
                { label: "Anna", group: "F" },
                { label: "Bert", group: "M" },
            ],
        });
        expect(options.accent).toBe("var(--rose)");
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
            <div data-widget="donut-chart" data-payload="not-json"></div>
            <div data-widget="donut-chart" data-payload='[{"label":"X","value":2}]'></div>
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
            <div data-widget="donut-chart"        data-payload='[]'></div>
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
            <div id="d1" data-widget="donut-chart" data-payload='[]'></div>
        `;

        const result = renderWidgets(document.body);

        // renderWidgets returns the rendered widget instances and a teardown.
        expect(result.widgets).toHaveLength(1);
        expect(typeof result.disconnect).toBe("function");

        // Eager: the widget is drawn up front (in the DOM) with its entrance held
        // via animateOnReveal — but playEntry has NOT fired yet.
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

        document.body.innerHTML = `<div id="d1" data-widget="donut-chart" data-payload='[]'></div>`;
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

        document.body.innerHTML = `<div id="d1" data-widget="donut-chart" data-payload='[]'></div>`;
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

        document.body.innerHTML = `<div id="d1" data-widget="donut-chart" data-payload='[]'></div>`;
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

        document.body.innerHTML = `<div id="d1" data-widget="donut-chart" data-payload='[]'></div>`;
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

        document.body.innerHTML = `<div id="d1" data-widget="donut-chart" data-payload='[]'></div>`;
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

        document.body.innerHTML = `<div id="d1" data-widget="donut-chart" data-payload='[]'></div>`;
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

describe("world-map GeoJSON URL", () => {
    beforeEach(() => {
        geoJsonFetchSpy.mockClear();
        document.body.innerHTML = "";
    });

    afterEach(() => {
        document.body.innerHTML = "";
    });

    // Each case imports the module afresh: `cachedGeoJson` is module-level and
    // never reset, so whichever case ran first would otherwise satisfy the
    // second before it ever resolves a URL.
    const freshDispatcher = async () => {
        jest.resetModules();

        return (await import("../modules/index.js")).renderWidgets;
    };

    test("fetches the URL the server put on the page root", async () => {
        const renderFresh = await freshDispatcher();
        const serverUrl =
            "/index.php?route=%2Fmodule%2Frenamed-install-dir%2FAsset&asset=js/world-map.geojson&hash=1234";

        document.body.innerHTML = `
            <div data-geojson-url="${serverUrl}">
                <div data-widget="world-map" data-payload='[]'></div>
            </div>
        `;

        renderFresh(document.body);
        await Promise.resolve();
        await Promise.resolve();

        // The route segment is the installation directory, which the admin
        // chooses — the hard-coded literal is wrong for any renamed install.
        expect(geoJsonFetchSpy).toHaveBeenCalledTimes(1);
        expect(geoJsonFetchSpy).toHaveBeenCalledWith(serverUrl);
    });

    test("falls back to the built-in route when the page root carries no URL", async () => {
        const renderFresh = await freshDispatcher();

        document.body.innerHTML = `<div data-widget="world-map" data-payload='[]'></div>`;

        renderFresh(document.body);
        await Promise.resolve();
        await Promise.resolve();

        expect(geoJsonFetchSpy).toHaveBeenCalledTimes(1);
        expect(geoJsonFetchSpy).toHaveBeenCalledWith(
            expect.stringContaining("_webtrees-statistics_"),
        );
    });

    test("treats an empty attribute as absent rather than fetching the current page", async () => {
        const renderFresh = await freshDispatcher();

        document.body.innerHTML = `
            <div data-geojson-url="">
                <div data-widget="world-map" data-payload='[]'></div>
            </div>
        `;

        renderFresh(document.body);
        await Promise.resolve();
        await Promise.resolve();

        expect(geoJsonFetchSpy).toHaveBeenCalledWith(
            expect.stringContaining("_webtrees-statistics_"),
        );
    });
});

describe("widget registry", () => {
    afterEach(() => {
        document.body.innerHTML = "";
    });

    /**
     * Collect the `data-widget` values found in the view templates.
     *
     * @param {string} directory Directory to scan recursively.
     *
     * @returns {Set<string>}
     */
    const widgetTypesInTemplates = (directory) => {
        const types = new Set();

        readdirSync(directory, { withFileTypes: true }).forEach((entry) => {
            const path = join(directory, entry.name);

            if (entry.isDirectory()) {
                widgetTypesInTemplates(path).forEach((type) => types.add(type));

                return;
            }

            if (entry.name.endsWith(".phtml") === false) {
                return;
            }

            // An attribute follows white space, which a mention in backticks inside a
            // template docblock does not.
            for (const match of readFileSync(path, "utf8").matchAll(/\sdata-widget="([^"]+)"/g)) {
                types.add(match[1]);
            }
        });

        return types;
    };

    const templateTypes = [
        ...widgetTypesInTemplates(fileURLToPath(new URL("../../views", import.meta.url))),
    ].sort();

    test("finds the widget types of the templates", () => {
        expect(templateTypes).toContain("donut-chart");
    });

    // An unknown type is skipped silently and leaves a blank card, so each
    // type found in the templates has to reach a widget.
    test.each(templateTypes)(
        "the dispatcher draws a widget for the template type %s",
        async (type) => {
            document.body.innerHTML = `<div data-widget="${type}" data-payload="[]"></div>`;

            const { widgets } = renderWidgets(document.body);

            // The world map joins `widgets` once its GeoJSON request has resolved, which
            // takes more than a microtask when the module cache is still empty. Give the
            // dispatch a bounded number of macrotasks to settle, so the case does not
            // depend on which test ran before it. A type without an entry in the dispatch
            // table does not join, so the loop ends with an empty list.
            for (let attempt = 0; attempt < 50 && widgets.length === 0; attempt += 1) {
                await new Promise((resolve) => setTimeout(resolve, 0));
            }

            expect(widgets).toHaveLength(1);
        },
    );
});

describe("places panel tabs", () => {
    const panelMarkup = `
        <div class="wt-stat-places" data-wt-stat-places data-view="birth">
            <button type="button" class="wt-stat-places-tab is-active" aria-selected="true" data-view="birth"></button>
            <button type="button" class="wt-stat-places-tab" aria-selected="false" data-view="death"></button>
            <button type="button" class="wt-stat-places-tab" aria-selected="false"></button>
            <div class="wt-stat-places-panel is-active" data-view="birth"></div>
            <div class="wt-stat-places-panel" data-view="death"></div>
        </div>
    `;

    beforeEach(() => {
        document.body.innerHTML = panelMarkup;
        renderWidgets(document.body);
    });

    afterEach(() => {
        document.body.innerHTML = "";
    });

    const tabFor = (view) => document.querySelector(`.wt-stat-places-tab[data-view="${view}"]`);
    const panelFor = (view) => document.querySelector(`.wt-stat-places-panel[data-view="${view}"]`);

    test("a click on a tab activates its panel and deactivates the other one", () => {
        tabFor("death").click();

        expect(panelFor("death").classList.contains("is-active")).toBe(true);
        expect(panelFor("birth").classList.contains("is-active")).toBe(false);
    });

    test("a click on a tab moves the active flag and the aria state to it", () => {
        tabFor("death").click();

        expect(tabFor("death").classList.contains("is-active")).toBe(true);
        expect(tabFor("death").getAttribute("aria-selected")).toBe("true");
        expect(tabFor("birth").classList.contains("is-active")).toBe(false);
        expect(tabFor("birth").getAttribute("aria-selected")).toBe("false");
    });

    test("a click on a tab hands the view to the wrapper for the accent colour", () => {
        tabFor("death").click();

        expect(document.querySelector("[data-wt-stat-places]").dataset.view).toBe("death");
    });

    test("a click on a tab without a view leaves the panel unchanged", () => {
        document.querySelectorAll(".wt-stat-places-tab")[2].click();

        expect(tabFor("birth").classList.contains("is-active")).toBe(true);
        expect(panelFor("birth").classList.contains("is-active")).toBe(true);
        expect(document.querySelector("[data-wt-stat-places]").dataset.view).toBe("birth");
    });
});
