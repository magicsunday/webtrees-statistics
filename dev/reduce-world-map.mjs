/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

/**
 * Reduces a Natural Earth admin-0 GeoJSON to what the world map reads.
 *
 * The chart-lib `WorldMap` reads the two ISO keys of a feature and, when an ISO
 * value is the -99 sentinel, its names. Every other property
 * only adds payload, so it is dropped here, and the coordinates are rounded to a
 * precision that a chart widget cannot show. Antarctica is dropped as well: no
 * genealogy data lands there and the continent would take a large share of the
 * vertical space of the map.
 *
 * Run it through `make world-map SOURCE=<path of the Natural Earth file>`, which
 * writes `resources/js/world-map.geojson`. The committed file is the 110m
 * admin-0 countries file with lakes of Natural Earth release 5.1.2, published in
 * May 2022. Its borders are those of that release, whatever the download date.
 */

import { readFileSync, writeFileSync } from "node:fs";
import { resolve } from "node:path";
import { pathToFileURL } from "node:url";

/**
 * The feature properties the map code and the chart-lib `WorldMap` read.
 *
 * @type {string[]}
 */
export const KEPT_PROPERTIES = ["ISO_A2", "ISO_A2_EH", "NAME", "NAME_LONG"];

/**
 * The ISO codes of the regions that the map does not draw.
 *
 * @type {string[]}
 */
export const EXCLUDED_REGIONS = ["AQ"];

/**
 * Decimals kept per coordinate, about a hundred metres.
 *
 * @type {number}
 */
export const COORDINATE_DECIMALS = 3;

/**
 * Rounds a number, or every number of a nested coordinate array.
 *
 * @param {number | unknown[]} coordinates A coordinate, a ring or a list of rings.
 * @param {number}             decimals    Decimals to keep.
 *
 * @returns {number | unknown[]} The rounded structure, a new array for an array input.
 */
function roundCoordinates(coordinates, decimals) {
    if (typeof coordinates === "number") {
        return Number(coordinates.toFixed(decimals));
    }

    return coordinates.map((entry) => roundCoordinates(entry, decimals));
}

/**
 * Tells whether a feature is one of the regions that the map does not draw.
 *
 * `ISO_A2_EH` wins over `ISO_A2`, because `ISO_A2` holds the -99 sentinel for
 * some countries.
 *
 * @param {Record<string, any>} feature A Natural Earth feature.
 *
 * @returns {boolean} True when the feature is excluded.
 */
function isExcluded(feature) {
    const iso = feature.properties?.ISO_A2_EH ?? feature.properties?.ISO_A2;

    return EXCLUDED_REGIONS.includes(iso);
}

/**
 * Reduces a FeatureCollection without touching its input.
 *
 * @param {{features: Array<Record<string, any>>}} collection The Natural Earth FeatureCollection.
 * @param {string[]}                               properties The feature properties to keep.
 * @param {number}                                 decimals   Decimals to keep per coordinate.
 *
 * @returns {Record<string, any>} A new FeatureCollection with the reduced features.
 */
export function reduceWorldMap(
    collection,
    properties = KEPT_PROPERTIES,
    decimals = COORDINATE_DECIMALS,
) {
    return {
        ...collection,
        features: collection.features.filter((feature) => !isExcluded(feature)).map((feature) => ({
            ...feature,
            properties: Object.fromEntries(
                properties
                    .filter((key) => Object.hasOwn(feature.properties ?? {}, key))
                    .map((key) => [key, feature.properties[key]]),
            ),
            geometry: {
                ...feature.geometry,
                coordinates: roundCoordinates(feature.geometry.coordinates, decimals),
            },
        })),
    };
}

/**
 * Command line entry: reads the source file and writes the reduced file as one
 * compact JSON line.
 *
 * @param {string[]} argv The arguments after the script name: source path, target path.
 *
 * @returns {void}
 */
function main(argv) {
    const [source, target] = argv;

    if (source === undefined || target === undefined) {
        console.error("Usage: node dev/reduce-world-map.mjs <source.geojson> <target.geojson>");
        process.exitCode = 1;

        return;
    }

    const reduced = reduceWorldMap(JSON.parse(readFileSync(source, "utf8")));

    writeFileSync(target, JSON.stringify(reduced));
}

if (process.argv[1] !== undefined && import.meta.url === pathToFileURL(resolve(process.argv[1])).href) {
    main(process.argv.slice(2));
}
