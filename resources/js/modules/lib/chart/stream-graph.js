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
import { createHostTooltip, escapeHtml } from "./tooltip.js";

/**
 * Stream-graph renderer used by the Names tab to show the per-decade
 * frequency of the top-N given names. Each band is one given name; the
 * area shows how its popularity rises and falls across decades.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
export class StreamGraph
{
    /**
     * @param {String} identifier The host element id (created in the partial)
     * @param {Object} options    Optional renderer overrides (width / height / margins)
     */
    constructor(identifier, options) {
        this.identifier = identifier;
        this.options    = Object.assign({
            width:  null,
            height: 240,
            // Asymmetric top/bottom margins: top only needs a couple
            // of pixels for the y-pad, bottom carries the x-axis tick
            // labels too. The vertical-centring shim below offsets
            // the inner-G translate so the asymmetry doesn't bias
            // the content downward in the SVG box.
            margin: { top: 4, right: 16, bottom: 28, left: 16 },
        }, options || {});
    }

    /**
     * Render the stream-graph SVG into the host element.
     *
     * @param {Object} data
     * @param {Array<Number>}                 data.decades The x-axis decades (sorted ascending)
     * @param {Array<String>}                 data.names   Top-N given names, sorted by total frequency
     * @param {Object<String, Object<Number, Number>>} data.series One row per name with decade → count
     *
     * @return {SVGElement|null}
     */
    draw(data) {
        const host = document.getElementById(this.identifier);

        if (host === null) {
            return null;
        }

        host.replaceChildren();

        if (!data || !Array.isArray(data.decades) || data.decades.length === 0 || !Array.isArray(data.names) || data.names.length === 0) {
            const empty       = document.createElement("p");
            empty.className   = "text-center text-muted my-4";
            empty.textContent = host.dataset.emptyMessage || "No data";
            host.append(empty);
            return null;
        }

        // Transform into the dense row-per-decade shape d3.stack expects.
        const rows = data.decades.map((decade) => {
            const row = { decade };
            data.names.forEach((name) => {
                row[name] = (data.series[name] || {})[decade] || 0;
            });
            return row;
        });

        const { height, margin } = this.options;
        // Render at the host's current pixel width so SVG content keeps
        // its natural aspect — no preserveAspectRatio="none" stretching
        // of axes and band edges. Falls back to a 900px viewport when
        // the host has not laid out yet (e.g. tab not visible).
        const width       = Math.max(360, this.options.width ?? host.clientWidth ?? 900);
        const innerWidth  = width - margin.left - margin.right;
        const innerHeight = height - margin.top - margin.bottom;

        const series = stack()
            .keys(data.names)
            .offset(stackOffsetSilhouette)
            .order(stackOrderInsideOut)(rows);

        const xScale = scaleLinear()
            .domain(extent(rows, (row) => row.decade))
            .range([0, innerWidth]);

        // The silhouette layout centres the stack around zero; the envelope
        // therefore spans [min(y0), max(y1)] across every band at every x.
        // A small headroom on both sides keeps the curves from touching
        // the x-axis baseline (or the top of the plot area), so even the
        // thinnest band still reads as a band.
        const yLower = min(series, (band) => min(band, (point) => point[0])) ?? 0;
        const yUpper = max(series, (band) => max(band, (point) => point[1])) ?? 0;
        const yPad   = Math.max((yUpper - yLower) * 0.08, 1);
        const yScale = scaleLinear()
            .domain([yLower - yPad, yUpper + yPad])
            .range([innerHeight, 0]);

        const colour = scaleOrdinal()
            .domain(data.names)
            .range(schemeTableau10);

        const areaPath = area()
            .x((point)  => xScale(point.data.decade))
            .y0((point) => yScale(point[0]))
            .y1((point) => yScale(point[1]))
            .curve(curveBasis);

        // Flat baseline path used as the entry state of the on-load
        // animation: every band collapses to the silhouette midline
        // and then expands into its real shape over one ease-out beat.
        const yMid = yScale((yLower + yUpper) / 2);
        const flatPath = area()
            .x((point) => xScale(point.data.decade))
            .y0(yMid)
            .y1(yMid)
            .curve(curveBasis);

        const tooltip = createHostTooltip(host, "wt-stream-graph-tooltip");

        const svg = select(host)
            .append("svg")
            .attr("class", "wt-stream-graph")
            .attr("viewBox", `0 0 ${width} ${height}`)
            .attr("role", "img")
            .attr("aria-label", "Given-name popularity across decades");

        // Centre the inner content vertically inside the SVG. The bottom
        // margin holds the x-axis tick labels (no top counterpart), so
        // the rendered <g> bounding box otherwise lands biased downward
        // by half the asymmetry. Subtracting half of (bottom − top)
        // from the translate produces a slightly negative shift on
        // common settings (e.g. -8 with the default 4/28 margins),
        // which lifts the bbox back to the SVG centre.
        const verticalCentringShim = Math.round((margin.bottom - margin.top) / 2);
        const inner = svg.append("g")
            .attr("transform", `translate(${margin.left}, ${margin.top - verticalCentringShim})`);

        const bandTotals = new Map(series.map((band) => [
            band.key,
            band.reduce((sum, point) => sum + (point[1] - point[0]), 0),
        ]));

        const peakDecade = (band) => {
            let bestDecade = band[0]?.data?.decade ?? null;
            let bestSize   = -Infinity;
            band.forEach((point) => {
                const size = point[1] - point[0];
                if (size > bestSize) {
                    bestSize   = size;
                    bestDecade = point.data.decade;
                }
            });
            return bestDecade;
        };

        const bands = inner.selectAll("path.band")
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

        // Stagger the expansion slightly per band: inner bands grow
        // first, outer ones fan out after — the stackOrderInsideOut
        // layout already orders the array from centre outward, so a
        // small index-based delay reads as a natural fan-out.
        bands.transition("stream-graph-enter")
            .duration(900)
            .delay((_, index) => index * 40)
            .ease(easeCubicOut)
            .attr("opacity", 0.85)
            .attr("d", areaPath);

        const bandTooltipHtml = (band) => {
            // Given names come from raw GEDCOM — escape before innerHTML.
            const total = Math.round(bandTotals.get(band.key) ?? 0);
            const peak  = peakDecade(band);
            return `<strong>${escapeHtml(band.key)}</strong><br>` +
                `<span class="wt-stream-graph-tooltip-stat">${total} individual${total === 1 ? "" : "s"}</span><br>` +
                `<span class="wt-stream-graph-tooltip-meta">peak in the ${peak}s</span>`;
        };

        bands
            .on("mouseover", (event, band) => tooltip.show(event, bandTooltipHtml(band)))
            .on("mousemove", (event) => tooltip.move(event))
            .on("mouseleave", () => tooltip.hide())
            .on("focus", (event, band) => {
                // Keyboard focus has no cursor; pin to the band's left edge.
                const bbox = event.target.getBoundingClientRect();
                tooltip.show({ clientX: bbox.left + bbox.width / 2, clientY: bbox.top + 12 }, bandTooltipHtml(band));
            })
            .on("blur", () => tooltip.hide());

        inner.append("g")
            .attr("class", "x-axis")
            .attr("transform", `translate(0, ${innerHeight})`)
            .call(axisBottom(xScale).ticks(Math.min(rows.length, 8)).tickFormat((decade) => `${decade}s`));

        // Hide the y axis: a stream graph reads as relative magnitudes, the
        // absolute counts live in the band tooltips.
        inner.append("g")
            .attr("class", "y-axis")
            .call(axisLeft(yScale).ticks(0).tickSize(0));

        return svg.node();
    }
}
