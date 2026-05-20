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

// Mock every widget so the dispatcher's wiring (parse, lookup, call)
// can be observed without dragging d3 into the test bundle.
const drawDonut = jest.fn();
const drawWorldMap = jest.fn();
const drawStreamGraph = jest.fn();
const drawSankeyFlow = jest.fn();

jest.unstable_mockModule("../modules/widgets/donut.js", () => ({ default: drawDonut }));
jest.unstable_mockModule("../modules/widgets/world-map.js", () => ({ default: drawWorldMap }));
jest.unstable_mockModule("../modules/widgets/stream-graph.js", () => ({
    default: drawStreamGraph,
}));
jest.unstable_mockModule("../modules/widgets/sankey-flow.js", () => ({ default: drawSankeyFlow }));

const { renderWidgets } = await import("../modules/index.js");

describe("renderWidgets", () => {
    beforeEach(() => {
        drawDonut.mockClear();
        drawWorldMap.mockClear();
        drawStreamGraph.mockClear();
        drawSankeyFlow.mockClear();
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

        expect(drawDonut).toHaveBeenCalledTimes(1);
        const [node, data, options] = drawDonut.mock.calls[0];
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

        expect(drawStreamGraph).toHaveBeenCalledTimes(1);
        const [, , options] = drawStreamGraph.mock.calls[0];
        expect(options).toEqual({ height: 280 });
    });

    test("ignores nodes without data-widget attribute", () => {
        document.body.innerHTML = '<div id="x1"></div>';

        renderWidgets(document.body);

        expect(drawDonut).not.toHaveBeenCalled();
        expect(drawWorldMap).not.toHaveBeenCalled();
        expect(drawStreamGraph).not.toHaveBeenCalled();
        expect(drawSankeyFlow).not.toHaveBeenCalled();
    });

    test("skips unknown widget types without throwing", () => {
        document.body.innerHTML = '<div data-widget="unknown-widget" data-payload="[]"></div>';

        expect(() => renderWidgets(document.body)).not.toThrow();
        expect(drawDonut).not.toHaveBeenCalled();
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
        expect(drawDonut).toHaveBeenCalledTimes(2);
        expect(drawDonut.mock.calls[0][1]).toBeNull();
        expect(drawDonut.mock.calls[1][1]).toEqual([{ label: "X", value: 2 }]);

        errorSpy.mockRestore();
    });

    test("dispatches every widget type in a multi-card tab", () => {
        document.body.innerHTML = `
            <div data-widget="donut"        data-payload='[]'></div>
            <div data-widget="world-map"    data-payload='[]'></div>
            <div data-widget="stream-graph" data-payload='{}'></div>
            <div data-widget="sankey-flow"  data-payload='{}'></div>
        `;

        renderWidgets(document.body);

        expect(drawDonut).toHaveBeenCalledTimes(1);
        expect(drawWorldMap).toHaveBeenCalledTimes(1);
        expect(drawStreamGraph).toHaveBeenCalledTimes(1);
        expect(drawSankeyFlow).toHaveBeenCalledTimes(1);
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
