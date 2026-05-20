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
import { createHostTooltip, escapeHtml } from "./tooltip.js";

/**
 * Sankey renderer used by the Places tab to show birth → death
 * country flows. Each link's stroke width encodes the number of
 * individuals who moved between the two countries; nodes are stacked
 * by total throughput on the source / target side.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
export class SankeyFlow {
    /**
     * @param {String} identifier The host element id (created in the partial)
     * @param {Object} options    Optional renderer overrides (width / height / margins)
     */
    constructor(identifier, options) {
        this.identifier = identifier;
        this.options    = Object.assign(
            {
                width:     null,
                height:    320,
                margin:    { top: 8, right: 130, bottom: 8, left: 130 },
                nodeWidth: 14,
                nodePad:   10,
            },
            options || {},
        );
    }

    /**
     * Render the Sankey SVG into the host element.
     *
     * @param {Object} data
     * @param {Array<{name: string}>}                                     data.nodes Country nodes
     * @param {Array<{source: number, target: number, value: number}>}   data.links Weighted flows
     *
     * @returns {SVGElement|null}
     */
    draw(data) {
        const host = document.getElementById(this.identifier);

        if (host === null) {
            return null;
        }

        host.replaceChildren();

        if (
            data === null ||
            data === undefined ||
            !Array.isArray(data.nodes) ||
            data.nodes.length === 0 ||
            !Array.isArray(data.links) ||
            data.links.length === 0
        ) {
            const empty       = document.createElement("p");
            empty.className   = "text-center text-muted my-4";
            empty.textContent = host.dataset.emptyMessage || "No data";
            host.append(empty);
            return null;
        }

        const { height, margin, nodeWidth, nodePad } = this.options;
        const width       = Math.max(360, this.options.width ?? host.clientWidth ?? 900);
        const innerWidth  = width - margin.left - margin.right;
        const innerHeight = height - margin.top - margin.bottom;

        const tooltip = createHostTooltip(host, "wt-sankey-tooltip");

        const colour = scaleOrdinal()
            .domain(data.nodes.map((node) => node.name))
            .range(schemeTableau10);

        // d3-sankey mutates its input — feed it deep copies so the
        // partial's serialised payload stays clean across re-renders.
        const sankeyLayout = sankey()
            .nodeWidth(nodeWidth)
            .nodePadding(nodePad)
            .nodeAlign(sankeyJustify)
            .extent([
                [margin.left, margin.top],
                [margin.left + innerWidth, margin.top + innerHeight],
            ]);

        // d3-sankey throws "circular link" the moment the input
        // resolves to a directed cycle (e.g. a self-loop slipped past
        // the bipartite invariant from a future caller). Treat that as
        // "no usable data" rather than letting the whole tab break.
        let graph;
        try {
            graph = sankeyLayout({
                nodes: data.nodes.map((node) => ({ ...node })),
                links: data.links.map((link) => ({ ...link })),
            });
        } catch (_error) {
            const empty       = document.createElement("p");
            empty.className   = "text-center text-muted my-4";
            empty.textContent = host.dataset.emptyMessage || "No data";
            host.append(empty);
            return null;
        }

        const svg = select(host)
            .append("svg")
            .attr("class", "wt-sankey")
            .attr("viewBox", `0 0 ${width} ${height}`)
            .attr("role", "img")
            .attr("aria-label", "Birth-to-death country flows");

        const linkPath = sankeyLinkHorizontal();

        const links = svg.append("g")
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

        // Stagger the link expansion: high-value flows grow first,
        // small ones fade in afterwards. The animation reads as the
        // diagram "filling up" along the dominant migration paths.
        links.transition("sankey-enter")
            .duration(900)
            .delay((_, index) => index * 40)
            .ease(easeCubicOut)
            .attr("stroke-opacity", 0.45)
            .attr("stroke-width", (link) => Math.max(1, link.width));

        links
            .on("mouseover", (event, link) => {
                // Place names come from raw GEDCOM and must be escaped
                // before reaching innerHTML — a hand-edited tree can
                // contain literal `<script>` or stray quotes.
                tooltip.show(
                    event,
                    `<strong>${escapeHtml(link.source.name)} → ${escapeHtml(link.target.name)}</strong><br>` +
                        `<span class="wt-sankey-tooltip-stat">${link.value} individual${link.value === 1 ? "" : "s"}</span>`,
                );
            })
            .on("mousemove", (event) => tooltip.move(event))
            .on("mouseleave", () => tooltip.hide());

        const nodes = svg.append("g")
            .attr("class", "nodes")
            .selectAll("g.node")
            .data(graph.nodes)
            .enter()
            .append("g")
            .attr("class", "node");

        nodes.append("rect")
            .attr("x", (node) => node.x0)
            .attr("y", (node) => node.y0)
            .attr("width", (node) => Math.max(0, node.x1 - node.x0))
            .attr("height", (node) => Math.max(0, node.y1 - node.y0))
            .attr("fill", (node) => colour(node.name))
            .attr("opacity", 0)
            .transition("sankey-nodes")
            .duration(600)
            .delay(450)
            .ease(easeCubicOut)
            .attr("opacity", 0.9);

        nodes.append("text")
            .attr("class", "node-label")
            .attr("x", (node) => (node.x0 < width / 2 ? node.x1 + 6 : node.x0 - 6))
            .attr("y", (node) => (node.y0 + node.y1) / 2)
            .attr("dominant-baseline", "middle")
            .attr("text-anchor", (node) => (node.x0 < width / 2 ? "start" : "end"))
            .attr("opacity", 0)
            .text((node) => node.name)
            .transition("sankey-labels")
            .duration(600)
            .delay(600)
            .ease(easeCubicOut)
            .attr("opacity", 1);

        return svg.node();
    }
}
