/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import { describe, expect, jest, test } from "@jest/globals";

import { DashboardBus } from "../modules/dashboard-bus.js";

describe("DashboardBus", () => {
    test("broadcasts a selection to every subscriber", () => {
        const bus = new DashboardBus();
        const widgetA = jest.fn();
        const widgetB = jest.fn();

        bus.onSelectionChanged(widgetA);
        bus.onSelectionChanged(widgetB);

        bus.emit({ source: "donut.births", predicate: { century: 1900 } });

        expect(widgetA).toHaveBeenCalledTimes(1);
        expect(widgetB).toHaveBeenCalledTimes(1);
        expect(widgetA.mock.calls[0][0]).toEqual({
            source: "donut.births",
            predicate: { century: 1900 },
        });
    });

    test("unsubscribe stops further broadcasts", () => {
        const bus = new DashboardBus();
        const widgetA = jest.fn();
        const widgetB = jest.fn();

        const unsubscribeA = bus.onSelectionChanged(widgetA);
        bus.onSelectionChanged(widgetB);

        unsubscribeA();
        bus.emit({ source: "donut.test", predicate: null });

        expect(widgetA).not.toHaveBeenCalled();
        expect(widgetB).toHaveBeenCalledTimes(1);
    });

    test("a null predicate clears the filter — subscribers see it as a normal event", () => {
        const bus = new DashboardBus();
        const widget = jest.fn();
        bus.onSelectionChanged(widget);

        bus.emit({ source: "donut.test", predicate: null });

        expect(widget).toHaveBeenCalledWith({ source: "donut.test", predicate: null });
    });

    test("multiple subscribers each receive their own namespace and are independent", () => {
        const bus = new DashboardBus();
        const subscribers = Array.from({ length: 5 }, () => jest.fn());

        for (const sub of subscribers) {
            bus.onSelectionChanged(sub);
        }

        bus.emit({ source: "tag-cloud.surnames", predicate: { surname: "Müller" } });

        for (const sub of subscribers) {
            expect(sub).toHaveBeenCalledTimes(1);
        }
    });

    test("the source identifier lets subscribers ignore their own emissions", () => {
        const bus = new DashboardBus();
        let myselfHandled = 0;
        let othersHandled = 0;

        bus.onSelectionChanged((selection) => {
            if (selection.source === "donut.self") {
                ++myselfHandled;
            } else {
                ++othersHandled;
            }
        });

        bus.emit({ source: "donut.self", predicate: { x: 1 } });
        bus.emit({ source: "donut.other", predicate: { y: 2 } });

        expect(myselfHandled).toBe(1);
        expect(othersHandled).toBe(1);
    });
});
