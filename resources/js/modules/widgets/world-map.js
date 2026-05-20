/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import { select, pointer } from "d3-selection";
import { json } from "d3-fetch";
import { geoMercator, geoPath } from "d3-geo";
import { scaleLinear } from "d3-scale";
import { max } from "d3-array";
import "d3-transition";

const GEOJSON_URL =
    "/index.php?route=%2Fmodule%2F_webtrees-statistics_%2FAsset&asset=js/world-map.geojson";

/**
 * Draw the choropleth world map into the host node. Country fill is
 * driven by the per-country counts in `data`; the colour gradient is
 * read from CSS custom properties on the host element (so light/dark
 * theme + birth-vs-death tinting come from CSS, not JS).
 *
 * @param {HTMLElement} node    Host element (`.world-map` container).
 * @param {Array<{countryCode: string, label: string, count: number}>} data Country counts.
 * @param {Object}      options Optional overrides.
 *
 * @param {Number}      [options.width=900]  Logical SVG viewport width.
 * @param {Number}      [options.height=510] Logical SVG viewport height.
 *
 * @returns {SVGElement|null}
 */
export default function drawWorldMap(node, data, options) {
    const host = node.querySelector(".world-map") ?? node;
    host.replaceChildren();

    const style = getComputedStyle(host);
    const rangeStart =
        style.getPropertyValue("--bs-progress-label-gradient-start").trim() || "#e5e5e5";
    const rangeEnd = style.getPropertyValue("--bs-progress-label-gradient-end").trim() || "#1f664a";
    const parentRect = host.getBoundingClientRect();
    const width = parentRect.width > 0 ? parentRect.width : (options?.width ?? 900);
    const height = parentRect.height > 0 ? parentRect.height : (options?.height ?? 510);

    const svg = select(host)
        .append("svg")
        .attr("class", "geoMap")
        .attr("width", width)
        .attr("height", height)
        .attr("viewBox", [0, 0, width, height])
        .attr("style", "max-width: 100%; height: auto;");

    const visual = svg.append("g");

    json(GEOJSON_URL).then((geoJson) => {
        const features = geoJson.features.filter((datum) => datum.properties.ISO_A2_EH !== "AQ");

        const population = Array.isArray(data) ? data : [];
        population.forEach((row) => {
            features
                .filter((datum) => datum.properties.ISO_A2_EH === row.countryCode)
                .forEach((country) => {
                    country.properties = row;
                });
        });

        const projection = geoMercator().fitSize([width, height], {
            type: "FeatureCollection",
            features,
        });
        const generator = geoPath().projection(projection);

        const scale = scaleLinear()
            .domain([0, max(population, (row) => row.count) ?? 1])
            .range([rangeStart, rangeEnd]);

        visual
            .selectAll(".country")
            .data(features)
            .enter()
            .append("path")
            .attr("class", "country")
            .attr("d", generator)
            .attr("fill", (datum) => {
                const count = datum.properties.count;
                return count ? scale(count) : "rgb(245, 245, 245)";
            })
            .on("pointerenter pointermove", (event, datum) => onPointerMove(visual, event, datum))
            .on("pointerleave", () => onPointerLeave(visual))
            .on("touchstart", (event) => event.preventDefault());

        visual.append("g").attr("class", "tooltip-geo-map");
    });

    return svg.node();
}

/**
 * Pointer handler — fade the other countries and surface a tooltip
 * with the count for the country under the cursor.
 *
 * @param {Selection} visual The `<g>` holding the countries + tooltip group.
 * @param {Event}     event  The DOM pointer event.
 * @param {Object}    datum  The bound GeoJSON feature.
 */
function onPointerMove(visual, event, datum) {
    if (datum.properties.count === undefined) {
        return;
    }

    visual.selectAll(".country").transition().duration(100).style("opacity", 0.5);
    select(event.target).transition().duration(100).style("opacity", 1);

    const coordinates = pointer(event);
    const tooltip = visual
        .select(".tooltip-geo-map")
        .style("display", null)
        .attr("transform", `translate(${coordinates[0]}, ${coordinates[1]})`);

    const rect = tooltip
        .selectAll("rect")
        .data([null])
        .join("rect")
        .attr("rx", 5)
        .attr("ry", 5)
        .attr("x", 20)
        .attr("y", 0)
        .attr("fill", "white")
        .attr("stroke", "#ccc")
        .attr("stroke-width", 1);

    const text = tooltip
        .selectAll("text")
        .data([null])
        .join("text")
        .call((selection) =>
            selection
                .selectAll("tspan")
                .data([datum.properties.label, `${datum.properties.count}`])
                .join("tspan")
                .attr("x", 25)
                .attr("y", 0)
                .attr("dy", (_, index) => `${index * 1.25}em`)
                .attr("font-weight", (_, index) => (index ? null : "bold"))
                .text((line) => line),
        );

    const { height: textHeight, width: textWidth } = text.node().getBBox();
    rect.attr("transform", `translate(0, ${-textHeight / 2})`)
        .attr("width", textWidth + 10)
        .attr("height", textHeight + 10);
}

/**
 * Pointer-leave handler — restore the country opacities and hide
 * the tooltip group.
 *
 * @param {Selection} visual The `<g>` holding the countries + tooltip group.
 */
function onPointerLeave(visual) {
    visual.selectAll(".country").transition().duration(100).style("opacity", 1);
    visual.select(".tooltip-geo-map").style("display", "none");
}
