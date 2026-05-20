/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import { select } from "d3-selection";
import { scaleOrdinal } from "d3-scale";
import { schemeTableau10 } from "d3-scale-chromatic";
import { sankey, sankeyLinkHorizontal, sankeyJustify } from "d3-sankey";
import "d3-transition";
import { easeCubicOut } from "d3-ease";
import { createChartTooltip, escapeHtml } from "./tooltip.js";

const DEFAULT_OPTIONS = {
    height: 320,
    margin: { top: 8, right: 130, bottom: 8, left: 130 },
    nodeWidth: 14,
    nodePad: 10,
};

/**
 * Draw a Sankey diagram showing birth → death country flows. Source
 * and target columns are expected to come pre-split from the
 * backend (disjoint node-index ranges) so d3-sankey's DAG invariant
 * holds for trees with counter-flows.
 *
 * @param {HTMLElement} node    Host element (`.wt-sankey-host`).
 * @param {Object}      data    Sankey payload.
 * @param {Array<{name: string}>}                                   data.nodes Country nodes.
 * @param {Array<{source: number, target: number, value: number}>}  data.links Weighted flows.
 * @param {Object}      options Optional overrides.
 *
 * @param {Number} [options.height]    SVG viewport height in pixels.
 * @param {Number} [options.width]     Logical viewport width.
 * @param {Number} [options.nodeWidth] d3-sankey nodeWidth.
 * @param {Number} [options.nodePad]   d3-sankey nodePadding.
 *
 * @returns {SVGElement|null}
 */
export default function drawSankeyFlow(node, data, options) {
    node.replaceChildren();

    if (
        !data ||
        !Array.isArray(data.nodes) ||
        data.nodes.length === 0 ||
        !Array.isArray(data.links) ||
        data.links.length === 0
    ) {
        appendEmptyState(node);
        return null;
    }

    const merged = { ...DEFAULT_OPTIONS, ...(options ?? {}) };
    const { height, margin, nodeWidth, nodePad } = merged;
    const width = Math.max(360, merged.width ?? node.clientWidth ?? 900);
    const innerWidth = width - margin.left - margin.right;
    const innerHeight = height - margin.top - margin.bottom;

    const tooltip = createChartTooltip();

    const colour = scaleOrdinal()
        .domain(data.nodes.map((entry) => entry.name))
        .range(schemeTableau10);

    const sankeyLayout = sankey()
        .nodeWidth(nodeWidth)
        .nodePadding(nodePad)
        .nodeAlign(sankeyJustify)
        .extent([
            [margin.left, margin.top],
            [margin.left + innerWidth, margin.top + innerHeight],
        ]);

    // d3-sankey throws "circular link" the moment the input resolves
    // to a directed cycle. Treat that as "no usable data" rather than
    // letting the whole tab break.
    let graph;
    try {
        graph = sankeyLayout({
            nodes: data.nodes.map((entry) => ({ ...entry })),
            links: data.links.map((link) => ({ ...link })),
        });
    } catch (_error) {
        appendEmptyState(node);
        return null;
    }

    const svg = select(node)
        .append("svg")
        .attr("class", "wt-sankey")
        .attr("viewBox", `0 0 ${width} ${height}`)
        .attr("role", "img")
        .attr("aria-label", "Birth-to-death country flows");

    const linkPath = sankeyLinkHorizontal();

    const links = svg
        .append("g")
        .attr("class", "links")
        .selectAll("path.link")
        .data(graph.links)
        .enter()
        .append("path")
        .attr("class", "link")
        .attr("d", linkPath)
        .attr("fill", "none")
        .attr("stroke", (link) => colour(link.source.name))
        .attr("stroke-opacity", 0)
        .attr("stroke-width", 0)
        .attr("tabindex", "0")
        .attr("aria-label", (link) => `${link.source.name} → ${link.target.name}: ${link.value}`);

    links
        .transition("sankey-enter")
        .duration(900)
        .delay((_, index) => index * 40)
        .ease(easeCubicOut)
        .attr("stroke-opacity", 0.45)
        .attr("stroke-width", (link) => Math.max(1, link.width));

    links
        .on("mouseover", (event, link) => {
            tooltip.show(
                event,
                `<strong>${escapeHtml(link.source.name)} → ${escapeHtml(link.target.name)}</strong><br>` +
                    `<span class="wt-chart-tooltip__stat">${link.value} individual${link.value === 1 ? "" : "s"}</span>`,
            );
        })
        .on("mousemove", (event) => tooltip.move(event))
        .on("mouseleave", () => tooltip.hide());

    const nodes = svg
        .append("g")
        .attr("class", "nodes")
        .selectAll("g.node")
        .data(graph.nodes)
        .enter()
        .append("g")
        .attr("class", "node");

    nodes
        .append("rect")
        .attr("x", (entry) => entry.x0)
        .attr("y", (entry) => entry.y0)
        .attr("width", (entry) => Math.max(0, entry.x1 - entry.x0))
        .attr("height", (entry) => Math.max(0, entry.y1 - entry.y0))
        .attr("fill", (entry) => colour(entry.name))
        .attr("opacity", 0)
        .transition("sankey-nodes")
        .duration(600)
        .delay(450)
        .ease(easeCubicOut)
        .attr("opacity", 0.9);

    nodes
        .append("text")
        .attr("class", "node-label")
        .attr("x", (entry) => (entry.x0 < width / 2 ? entry.x1 + 6 : entry.x0 - 6))
        .attr("y", (entry) => (entry.y0 + entry.y1) / 2)
        .attr("dominant-baseline", "middle")
        .attr("text-anchor", (entry) => (entry.x0 < width / 2 ? "start" : "end"))
        .attr("opacity", 0)
        .text((entry) => entry.name)
        .transition("sankey-labels")
        .duration(600)
        .delay(600)
        .ease(easeCubicOut)
        .attr("opacity", 1);

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
