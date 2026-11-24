/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import { readFileSync } from "node:fs";
import { fileURLToPath } from "node:url";

import { describe, expect, test } from "@jest/globals";

import { KEPT_PROPERTIES, reduceWorldMap } from "../../../dev/reduce-world-map.mjs";

const assetPath = fileURLToPath(new URL("../world-map.geojson", import.meta.url));

/**
 * Reads the committed world-map asset the way the browser receives it.
 *
 * @returns {{type: string, features: Array<{properties: Record<string, unknown>}>}} The parsed FeatureCollection.
 */
function readAsset() {
    const asset = JSON.parse(readFileSync(assetPath, "utf8"));

    // A truncated or mis-generated asset without features passes every scan of
    // its features and still draws an empty map.
    expect(asset.features.length).toBeGreaterThan(0);

    return asset;
}

describe("reduceWorldMap", () => {
    test("keeps only the listed properties of every feature", () => {
        const reduced = reduceWorldMap({
            type: "FeatureCollection",
            features: [
                {
                    type: "Feature",
                    properties: {
                        ISO_A2: "DE",
                        ISO_A2_EH: "DE",
                        NAME: "Germany",
                        NAME_LONG: "Germany",
                        POP_EST: 83132799,
                        ADMIN: "Germany",
                    },
                    geometry: { type: "Polygon", coordinates: [[[1, 2]]] },
                },
            ],
        });

        expect(reduced.features[0].properties).toStrictEqual({
            ISO_A2: "DE",
            ISO_A2_EH: "DE",
            NAME: "Germany",
            NAME_LONG: "Germany",
        });
    });

    test("drops the excluded regions and judges a feature by ISO_A2_EH before ISO_A2", () => {
        const feature = (properties) => ({
            type: "Feature",
            properties,
            geometry: { type: "Polygon", coordinates: [[[1, 2]]] },
        });

        const reduced = reduceWorldMap({
            type: "FeatureCollection",
            features: [
                feature({ ISO_A2: "AQ", ISO_A2_EH: "AQ", NAME: "Antarctica" }),
                feature({ ISO_A2: "-99", ISO_A2_EH: "AQ", NAME: "Sentinel Antarctica" }),
                feature({ ISO_A2: "-99", ISO_A2_EH: "FR", NAME: "France" }),
                feature({ ISO_A2: "AQ", NAME: "Antarctica without the EH key" }),
                feature({ ISO_A2: "DE", ISO_A2_EH: "DE", NAME: "Germany" }),
            ],
        });

        expect(reduced.features.map((entry) => entry.properties.NAME)).toStrictEqual([
            "France",
            "Germany",
        ]);
    });

    test("does not add a key for a listed property that a feature lacks", () => {
        const reduced = reduceWorldMap({
            type: "FeatureCollection",
            features: [
                {
                    type: "Feature",
                    properties: { NAME: "Nowhere", ADMIN: "Nowhere" },
                    geometry: { type: "Polygon", coordinates: [[[1, 2]]] },
                },
            ],
        });

        expect(reduced.features[0].properties).toStrictEqual({ NAME: "Nowhere" });
    });

    test("rounds the coordinates of a Polygon and of a MultiPolygon", () => {
        const reduced = reduceWorldMap({
            type: "FeatureCollection",
            features: [
                {
                    type: "Feature",
                    properties: {},
                    geometry: {
                        type: "Polygon",
                        coordinates: [
                            [
                                [1.23456, 2.34444],
                                [10.12351, 20.99949],
                            ],
                        ],
                    },
                },
                {
                    type: "Feature",
                    properties: {},
                    geometry: {
                        type: "MultiPolygon",
                        coordinates: [
                            [
                                [
                                    [5.55555, 6.66666],
                                    [7, 8.1],
                                ],
                            ],
                        ],
                    },
                },
            ],
        });

        expect(reduced.features[0].geometry.coordinates).toStrictEqual([
            [
                [1.235, 2.344],
                [10.124, 20.999],
            ],
        ]);
        expect(reduced.features[1].geometry.coordinates).toStrictEqual([
            [
                [
                    [5.556, 6.667],
                    [7, 8.1],
                ],
            ],
        ]);
    });

    test("does not touch its input, keeps the geometry type and drops the top-level bbox", () => {
        const input = {
            type: "FeatureCollection",
            name: "ne_110m_admin_0_countries",
            bbox: [-180, -90, 180, 83.6],
            features: [
                {
                    type: "Feature",
                    properties: { NAME: "Fiji", ADMIN: "Fiji" },
                    bbox: [177.28504, -18.28799, 180, -16.02088],
                    geometry: { type: "Polygon", coordinates: [[[177.28504, -17.72465]]] },
                },
            ],
        };
        const before = JSON.stringify(input);

        const reduced = reduceWorldMap(input);

        expect(JSON.stringify(input)).toBe(before);
        expect(reduced.bbox).toBeUndefined();
        expect(reduced.features[0].geometry.type).toBe("Polygon");
    });
});

describe("the committed world-map asset", () => {
    test("keeps a name for every feature whose ISO_A2 is the -99 sentinel", () => {
        // The chart-lib WorldMap falls back to the names when an ISO value is
        // the -99 sentinel, so such a feature without a name draws unlabelled.
        const sentinelFeatures = readAsset().features.filter(
            (feature) => feature.properties.ISO_A2 === "-99",
        );

        expect(sentinelFeatures.length).toBeGreaterThan(0);

        for (const feature of sentinelFeatures) {
            expect(typeof feature.properties.NAME).toBe("string");
            expect(typeof feature.properties.NAME_LONG).toBe("string");
        }
    });

    test("carries no property outside the kept list", () => {
        const surplus = readAsset().features.flatMap((feature) =>
            Object.keys(feature.properties).filter((key) => !KEPT_PROPERTIES.includes(key)),
        );

        expect([...new Set(surplus)]).toStrictEqual([]);
    });

    test("holds the countries that the map draws", () => {
        // The 110m file holds well above this count, so a file cut down to a few
        // countries fails here even when it keeps DE, FR and US.
        expect(readAsset().features.length).toBeGreaterThan(150);

        const codes = new Set(readAsset().features.map((feature) => feature.properties.ISO_A2_EH));

        expect(["DE", "FR", "US"].filter((code) => codes.has(code))).toStrictEqual([
            "DE",
            "FR",
            "US",
        ]);
    });

    test("holds no region that the map does not draw", () => {
        const codes = readAsset().features.map((feature) => feature.properties.ISO_A2_EH);

        expect(codes).not.toContain("AQ");
    });

    test("gives every feature the ISO key that the map joins its rows on", () => {
        const withoutIso = readAsset()
            .features.filter((feature) => typeof feature.properties.ISO_A2_EH !== "string")
            .map((feature) => feature.properties.NAME ?? "(unnamed)");

        expect(withoutIso).toStrictEqual([]);
    });

    test("is a fixed point of the reduction", () => {
        const asset = readAsset();

        expect(reduceWorldMap(asset)).toStrictEqual(asset);
    });
});
