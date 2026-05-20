/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import { select } from "d3-selection";
import { scaleLinear, scaleOrdinal } from "d3-scale";
import { schemeTableau10 } from "d3-scale-chromatic";
import { axisBottom, axisLeft } from "d3-axis";
import { area, stack, stackOffsetSilhouette, stackOrderInsideOut, curveBasis } from "d3-shape";
import { extent, max, min } from "d3-array";
import "d3-transition";
import { easeCubicOut } from "d3-ease";
import { createChartTooltip, escapeHtml } from "./tooltip.js";

const DEFAULT_MARGIN = { top: 4, right: 16, bottom: 28, left: 16 };
const DEFAULT_HEIGHT = 240;

/**
 * Draw a silhouette stream-graph showing per-decade frequencies of
 * the top-N given names. Each band is one name; the band's vertical
 * thickness in any column shows how many individuals born in that
 * decade carried that name.
 *
 * @param {HTMLElement} node    Host element (`.wt-stream-graph-host`).
 * @param {Object}      data    Series payload.
 * @param {Array<Number>}                         data.decades The x-axis decades (sorted ascending).
 * @param {Array<String>}                         data.names   Top-N given names, sorted by total frequency.
 * @param {Object<String, Object<Number, Number>>} data.series One row per name with decade → count.
 * @param {Object}      options Optional overrides.
 *
 * @param {Number} [options.width]  Logical viewport width; defaults to the host's clientWidth.
 * @param {Number} [options.height] SVG viewport height in pixels.
 *
 * @returns {SVGElement|null}
 */
export default function drawStreamGraph(node, data, options) {
    node.replaceChildren();

    if (
        !data ||
        !Array.isArray(data.decades) ||
        data.decades.length === 0 ||
        !Array.isArray(data.names) ||
        data.names.length === 0
    ) {
        appendEmptyState(node);
        return null;
    }

    const margin = options?.margin ?? DEFAULT_MARGIN;
    const height = options?.height ?? DEFAULT_HEIGHT;
    // Render at the host's current pixel width so SVG content keeps
    // its natural aspect — no preserveAspectRatio="none" stretching.
    const width = Math.max(360, options?.width ?? node.clientWidth ?? 900);
    const innerWidth = width - margin.left - margin.right;
    const innerHeight = height - margin.top - margin.bottom;

    // Transform into the dense row-per-decade shape d3.stack expects.
    const rows = data.decades.map((decade) => {
        const row = { decade };
        data.names.forEach((name) => {
            row[name] = data.series[name]?.[decade] || 0;
        });
        return row;
    });

    const series = stack()
        .keys(data.names)
        .offset(stackOffsetSilhouette)
        .order(stackOrderInsideOut)(rows);

    const xScale = scaleLinear()
        .domain(extent(rows, (row) => row.decade))
        .range([0, innerWidth]);

    // Add a small headroom above the silhouette envelope so the
    // top/bottom-most bands don't touch the SVG edges.
    const yLower = min(series, (band) => min(band, (point) => point[0])) ?? 0;
    const yUpper = max(series, (band) => max(band, (point) => point[1])) ?? 0;
    const yPad = Math.max((yUpper - yLower) * 0.08, 1);
    const yScale = scaleLinear()
        .domain([yLower - yPad, yUpper + yPad])
        .range([innerHeight, 0]);

    const colour = scaleOrdinal().domain(data.names).range(schemeTableau10);

    const areaPath = area()
        .x((point) => xScale(point.data.decade))
        .y0((point) => yScale(point[0]))
        .y1((point) => yScale(point[1]))
        .curve(curveBasis);

    // Flat baseline path for the on-load animation.
    const yMid = yScale((yLower + yUpper) / 2);
    const flatPath = area()
        .x((point) => xScale(point.data.decade))
        .y0(yMid)
        .y1(yMid)
        .curve(curveBasis);

    const tooltip = createChartTooltip();

    const svg = select(node)
        .append("svg")
        .attr("class", "wt-stream-graph")
        .attr("viewBox", `0 0 ${width} ${height}`)
        .attr("role", "img")
        .attr("aria-label", "Given-name popularity across decades");

    // Centre the inner content vertically inside the SVG. The bottom
    // margin holds the x-axis tick labels (no top counterpart), so
    // a small upward shim lifts the bbox back to the SVG centre.
    const verticalCentringShim = Math.round((margin.bottom - margin.top) / 2);
    const inner = svg
        .append("g")
        .attr("transform", `translate(${margin.left}, ${margin.top - verticalCentringShim})`);

    const bandTotals = new Map(
        series.map((band) => [
            band.key,
            band.reduce((sum, point) => sum + (point[1] - point[0]), 0),
        ]),
    );

    const peakDecade = (band) => {
        let bestDecade = band[0]?.data?.decade ?? null;
        let bestSize = -Infinity;
        band.forEach((point) => {
            const size = point[1] - point[0];
            if (size > bestSize) {
                bestSize = size;
                bestDecade = point.data.decade;
            }
        });
        return bestDecade;
    };

    const bands = inner
        .selectAll("path.band")
        .data(series)
        .enter()
        .append("path")
        .attr("class", "band")
        .attr("data-name", (band) => band.key)
        .attr("fill", (band) => colour(band.key))
        .attr("opacity", 0)
        .attr("d", flatPath)
        .attr("tabindex", "0")
        .attr("aria-label", (band) => {
            const total = Math.round(bandTotals.get(band.key) ?? 0);
            return `${band.key}: ${total} individuals, peak in the ${peakDecade(band)}s`;
        });

    bands
        .transition("stream-graph-enter")
        .duration(900)
        .delay((_, index) => index * 40)
        .ease(easeCubicOut)
        .attr("opacity", 0.85)
        .attr("d", areaPath);

    const bandTooltipHtml = (band) => {
        const total = Math.round(bandTotals.get(band.key) ?? 0);
        const peak = peakDecade(band);
        return (
            `<strong>${escapeHtml(band.key)}</strong><br>` +
            `<span class="wt-chart-tooltip__stat">${total} individual${total === 1 ? "" : "s"}</span><br>` +
            `<span class="wt-chart-tooltip__meta">peak in the ${peak}s</span>`
        );
    };

    bands
        .on("mouseover", (event, band) => tooltip.show(event, bandTooltipHtml(band)))
        .on("mousemove", (event) => tooltip.move(event))
        .on("mouseleave", () => tooltip.hide())
        .on("focus", (event, band) => {
            // Keyboard focus has no cursor; pin to the band's left edge.
            const bbox = event.target.getBoundingClientRect();
            tooltip.show(
                { clientX: bbox.left + bbox.width / 2, clientY: bbox.top + 12 },
                bandTooltipHtml(band),
            );
        })
        .on("blur", () => tooltip.hide());

    inner
        .append("g")
        .attr("class", "x-axis")
        .attr("transform", `translate(0, ${innerHeight})`)
        .call(
            axisBottom(xScale)
                .ticks(Math.min(rows.length, 8))
                .tickFormat((decade) => `${decade}s`),
        );

    // Hide the y axis: a stream graph reads as relative magnitudes;
    // absolute counts live in the band tooltips.
    inner.append("g").attr("class", "y-axis").call(axisLeft(yScale).ticks(0).tickSize(0));

    return svg.node();
}

/**
 * Render the host's data-empty-message into a placeholder paragraph.
 *
 * @param {HTMLElement} node
 */
function appendEmptyState(node) {
    const empty = document.createElement("p");
    empty.className = "text-center text-muted my-4";
    empty.textContent = node.dataset.emptyMessage || "No data";
    node.append(empty);
}
