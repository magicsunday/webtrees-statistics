/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import * as d3 from "./../../lib/d3";
import {BaseChart} from "../base-chart.js";

/**
 * The world map chart class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
export class WorldMap extends BaseChart
{
    /**
     * The width of the chart.
     *
     * @var {Number}
     */
    _width;

    /**
     * The height of the chart.
     *
     * @var {Number}
     */
    _height;

    /**
     * @var {Selection}
     */
    #svg;

    /**
     * @var {Selection}
     */
    #visual;

    /**
     * Constructor.
     *
     * @param {String} identifier
     * @param {Object} options    A list of options passed from outside to the application
     *
     * @param {Number} options.holeSize The size of the donut hole
     * @param {Number} options.margin   The margin around the donut
     */
    constructor(identifier, options) {
        super(identifier, options);

        const dimensions = this.determineAvailableDimensions(900, 510);

        this._width  = dimensions.width;
        this._height = dimensions.height;

        this.#svg = this._parent
            .append("svg")
            .attr("class", "geoMap")
            .attr("width", this._width)
            .attr("height", this._height)
            .attr("viewBox", [0, 0, this._width, this._height])
            .attr("style", "max-width: 100%; this._height: auto;");

        this.#visual = this.#svg.append("g");
    }

    /**
     * Draws a donut chart and returns the created SVG node element.
     *
     * @param {Array} data
     *
     * @return {Node}
     */
    draw(data) {
        // Source https://github.com/nvkelso/natural-earth-vector/blob/master/geojson/ne_110m_admin_0_countries_lakes.geojson
        d3.json('/index.php?route=%2Fmodule%2F_webtrees-statistics_%2FAsset&asset=js/world-map.geojson')
            .then(geoJson => this.update(geoJson, data));

        return this.#svg.node();
    }

    /**
     *
     * @param geoJson
     * @param populationData
     */
    update(geoJson, populationData) {
        const projection   = d3.geoMercator();
        const geoGenerator = d3.geoPath().projection(projection);

        // Remove antarctica from feature set
        geoJson.features = geoJson.features
            .filter(d => d.properties.ISO_A2_EH !== "AQ");

        // Filter the list of countries by country code and assign the population data
        // to each matching country.
        populationData.forEach(row => {
            geoJson.features
                .filter(d => d.properties.ISO_A2_EH === row.countryCode)
                .forEach(country => country.properties = row);
        });

        const scale = d3.scaleLinear()
            .domain([0, d3.max(populationData, d => d.count)])
            .range([
                this._options.color.range.start,
                this._options.color.range.end
            ])

        // Fit the projection to the size of the map
        projection
            .fitSize(
                [
                    this._width,
                    this._height
                ],
                geoJson
            );

        this.#visual
            .selectAll('.country')
            .data(geoJson.features)
            .enter()
            .append('path')
            .attr('class', 'country')
            .attr('d', geoGenerator)
            .attr("fill", d => {
                const data = d.properties.count;

                return data ? scale(data) : 'rgb(245, 245, 245)';
            })
            // Bind event listeners for tooltip
            .on("pointerenter pointermove", this.onPointerMove.bind(this))
            .on("pointerleave", this.onPointerLeave.bind(this))
            .on("touchstart", event => event.preventDefault());

        // Create the tooltip container
        this.#visual
            .append("g")
            .attr("class", "tooltip-geo-map");
    }

    /**
     *
     * @param {Event}  event
     * @param {Object} datum
     */
    onPointerMove(event, datum) {
        if (datum.properties.count === undefined) {
            return;
        }

        this.#visual
            .selectAll(".country")
            .transition()
            .duration(100)
            .style("opacity", .5);

        d3.select(event.target)
            .transition()
            .duration(100)
            .style("opacity", 1);

        // Convert the coordinates of the event relative to the current target
        const coordinates = d3.pointer(event);

        const tooltip = this.#visual
            .select(".tooltip-geo-map");

        tooltip
            .style("display", null)
            .attr("transform", `translate(${coordinates[0]}, ${coordinates[1]})`);

        const rect = tooltip
            .selectAll("rect")
            .data([,])
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
            .data([,])
            .join("text")
            .call(
                text => text
                    .selectAll("tspan")
                    .data([
                        datum.properties.label,
                        "Insgesamt: " + datum.properties.count
                    ])
                    .join("tspan")
                    .attr("x", 25)
                    .attr("y", 0)
                    .attr("dy", (_, i) => `${i * 1.25}em`)
                    .attr("font-weight", (_, i) => i ? null : "bold")
                    .text(d => d)
            );

        // Get dimensions of the bounding box around the text
        const {x, y, width: w, height: h} = text.node().getBBox();

        // Size rectangle around the text to fit the size of the text
        rect
            .attr("transform", `translate(0, ${-h / 2})`)
            .attr("width", w + 10)
            .attr("height", h + 10);
    }

    /**
     *
     */
    onPointerLeave() {
        // Reset styles
        this.#visual
            .selectAll(".country")
            .transition()
            .duration(100)
            .style("opacity", 1);

        // Hide tooltip
        this.#visual
            .select(".tooltip-geo-map")
            .style("display", "none");
    }
}
