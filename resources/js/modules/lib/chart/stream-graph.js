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
            margin: { top: 12, right: 16, bottom: 28, left: 16 },
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
        const yLower = min(series, (band) => min(band, (point) => point[0])) ?? 0;
        const yUpper = max(series, (band) => max(band, (point) => point[1])) ?? 0;
        const yScale = scaleLinear()
            .domain([yLower, yUpper])
            .range([innerHeight, 0]);

        const colour = scaleOrdinal()
            .domain(data.names)
            .range(schemeTableau10);

        const areaPath = area()
            .x((point)  => xScale(point.data.decade))
            .y0((point) => yScale(point[0]))
            .y1((point) => yScale(point[1]))
            .curve(curveBasis);

        // Detail-on-demand tooltip — a single absolutely-positioned div
        // attached to the host. mouseover swaps the contents to the band
        // under the cursor and mousemove keeps it tracking the pointer.
        let tooltip = host.querySelector(".wt-stream-graph-tooltip");
        if (tooltip === null) {
            tooltip = document.createElement("div");
            tooltip.className = "wt-stream-graph-tooltip";
            host.appendChild(tooltip);
        }

        const svg = select(host)
            .append("svg")
            .attr("class", "wt-stream-graph")
            .attr("viewBox", `0 0 ${width} ${height}`)
            .attr("role", "img")
            .attr("aria-label", "Given-name popularity across decades");

        const inner = svg.append("g").attr("transform", `translate(${margin.left}, ${margin.top})`);

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

        const positionTooltip = (event) => {
            const hostRect = host.getBoundingClientRect();
            const offsetX  = event.clientX - hostRect.left + 12;
            const offsetY  = event.clientY - hostRect.top + 12;
            tooltip.style.left = `${offsetX}px`;
            tooltip.style.top  = `${offsetY}px`;
        };

        inner.selectAll("path.band")
            .data(series)
            .enter()
            .append("path")
            .attr("class", "band")
            .attr("data-name", (band) => band.key)
            .attr("fill", (band) => colour(band.key))
            .attr("opacity", 0.85)
            .attr("d", areaPath)
            .attr("tabindex", "0")
            .attr("aria-label", (band) => {
                const total = Math.round(bandTotals.get(band.key) ?? 0);
                return `${band.key}: ${total} individuals, peak in the ${peakDecade(band)}s`;
            })
            .on("mouseover", (event, band) => {
                const total = Math.round(bandTotals.get(band.key) ?? 0);
                const peak  = peakDecade(band);
                tooltip.innerHTML = `<strong>${band.key}</strong><br>` +
                    `<span class="wt-stream-graph-tooltip-stat">${total} individual${total === 1 ? "" : "s"}</span><br>` +
                    `<span class="wt-stream-graph-tooltip-meta">peak in the ${peak}s</span>`;
                tooltip.classList.add("is-visible");
                positionTooltip(event);
            })
            .on("mousemove", (event) => positionTooltip(event))
            .on("mouseleave", () => tooltip.classList.remove("is-visible"))
            .on("focus", (event, band) => {
                const total = Math.round(bandTotals.get(band.key) ?? 0);
                const peak  = peakDecade(band);
                tooltip.innerHTML = `<strong>${band.key}</strong><br>` +
                    `<span class="wt-stream-graph-tooltip-stat">${total} individual${total === 1 ? "" : "s"}</span><br>` +
                    `<span class="wt-stream-graph-tooltip-meta">peak in the ${peak}s</span>`;
                tooltip.classList.add("is-visible");
                const bbox     = event.target.getBoundingClientRect();
                const hostRect = host.getBoundingClientRect();
                tooltip.style.left = `${bbox.left - hostRect.left + bbox.width / 2}px`;
                tooltip.style.top  = `${bbox.top - hostRect.top + 12}px`;
            })
            .on("blur", () => tooltip.classList.remove("is-visible"));

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
