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
const streamGraphDrawSpy = jest.fn();
const sankeyFlowDrawSpy = jest.fn();
const worldMapDrawSpy = jest.fn();

class DonutChart {
    constructor(node, options) {
        this.node = node;
        this.options = options;
    }
    draw(data) {
        donutDrawSpy(this.node, data, this.options);
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
}

jest.unstable_mockModule("@magicsunday/webtrees-chart-lib", () => ({
    DonutChart,
    StreamGraph,
    SankeyFlow,
    WorldMap,
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
        worldMapDrawSpy.mockClear();
        streamGraphDrawSpy.mockClear();
        sankeyFlowDrawSpy.mockClear();
        document.body.innerHTML = "";
    });

    afterEach(() => {
        document.body.innerHTML = "";
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
});
