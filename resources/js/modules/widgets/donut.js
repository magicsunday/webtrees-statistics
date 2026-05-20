/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import { select } from "d3-selection";
import { arc, pie } from "d3-shape";
import "d3-transition";
import { easeCubicOut } from "d3-ease";
import { interpolate } from "d3-interpolate";
import { createChartTooltip, escapeHtml } from "./tooltip.js";

/**
 * Draw a donut chart into the given host node.
 *
 * The host must carry a `.donut-chart` child (created by the partial)
 * that owns the SVG. The element itself is used for the host-anchored
 * tooltip so the bounds-clamping has the right context.
 *
 * @param {HTMLElement} node    Host element with the donut payload.
 * @param {Array<{label: string, value: number, class?: string, fill?: string}>} data Slice payload.
 * @param {Object}      options Optional overrides.
 *
 * @param {Number}      [options.margin=1]  Margin around the donut, in pixels.
 * @param {Number}      [options.holeSize]  Inner radius. Defaults to 90 % of the outer radius.
 *
 * @returns {SVGElement|null} The created SVG node, or null when empty.
 */
export default function drawDonut(node, data, options) {
    const host = node.querySelector(".donut-chart") ?? node;
    host.replaceChildren();

    if (!Array.isArray(data) || data.length === 0) {
        return null;
    }

    const margin = options?.margin ?? 1;
    const parentRect = host.getBoundingClientRect();
    const baseSize = Math.min(parentRect.width > 0 ? parentRect.width : 250, 250);
    const radius = (baseSize >> 1) - margin;
    const holeSize = options?.holeSize ?? radius - radius / 10;

    const tooltip = createChartTooltip();

    const total = data.reduce((sum, entry) => sum + (entry.value || 0), 0);

    const arcGenerator = arc().innerRadius(holeSize).outerRadius(radius);

    const pieGenerator = pie()
        .padAngle(1 / radius)
        .sort(null)
        .value((datum) => datum.value);

    const svg = select(host)
        .append("svg")
        .attr("class", "donutChart")
        .attr("width", baseSize)
        .attr("height", baseSize)
        .attr("viewBox", [-baseSize / 2, -baseSize / 2, baseSize, baseSize])
        .attr("style", "max-width: 100%; height: auto;");

    const slices = svg
        .append("g")
        .selectAll("path")
        .data(pieGenerator(data))
        .enter()
        .append("path")
        .attr("class", (slice) => `slice${slice.data.class ? ` ${slice.data.class}` : ""}`)
        .attr("fill", (slice) => slice.data.fill ?? null);

    // Grow each slice from zero sweep to its final angle for a quick
    // on-load animation — same easing beat as the other widgets so
    // multi-card pages feel coherent.
    slices
        .each(function setInitialAngle(slice) {
            this._current = { startAngle: slice.startAngle, endAngle: slice.startAngle };
        })
        .transition("donut-enter")
        .duration(600)
        .ease(easeCubicOut)
        .attrTween("d", function tweenSlice(slice) {
            const interp = interpolate(this._current, slice);
            this._current = slice;
            return (timing) => arcGenerator(interp(timing));
        });

    const tooltipHtml = (entry) => {
        const value = entry.value || 0;
        const share = total > 0 ? Math.round((value / total) * 100) : 0;
        const valueLabel = value.toLocaleString();
        return (
            `<strong>${escapeHtml(entry.label)}</strong><br>` +
            `<span class="wt-chart-tooltip__stat">${valueLabel}</span>` +
            (total > 0 ? `<span class="wt-chart-tooltip__meta"> · ${share}%</span>` : "")
        );
    };

    slices
        .on("mouseover", (event, slice) => tooltip.show(event, tooltipHtml(slice.data)))
        .on("mousemove", (event) => tooltip.move(event))
        .on("mouseleave", () => tooltip.hide());

    return svg.node();
}
