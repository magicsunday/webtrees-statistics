/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import * as d3 from "./../../lib/d3";
import {BaseChart} from "../base-chart.js";
import * as topojson from "../topojson.js";

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

        this._width    = dimensions.width;
        this._height   = dimensions.height;
        // this._margin   = options.margin ?? 1;
        // this._radius   = (this._width >> 1) - this._margin;
        // this._holeSize = options.holeSize ?? (this._radius - (this._radius / 10));
    }

    /**
     * Draws a donut chart and returns the created SVG node element.
     *
     * @param {Array} data
     *
     * @return {Node}
     */
    draw(data) {
        const svg = this._parent
            .append("svg")
            .attr("class", "geoMap")
            .attr("width", this._width)
            .attr("height", this._height)
            .attr("viewBox", [0, 0, this._width, this._height])
            .attr("style", "max-width: 100%; this._height: auto;");

        const visual = svg.append("g");

        const projection = d3.geoMercator()
            .center([0, 0])
            .translate([this._width >> 1, ((this._height + 210) >> 1)])
            .scale((this._height + 270) / (2 * Math.PI));

        const geoGenerator = d3.geoPath()
            .projection(projection);

        // Event listeners for tooltip
        function onPointerMove(event, datum) {
            if (datum.properties.count === undefined) {
                return;
            }

            visual.selectAll(".country")
                .transition()
                .duration(100)
                .style("opacity", .5);

            d3.select(this)
                .transition()
                .duration(100)
                .style("opacity", 1);

            const tooltip = visual
                .select(".tooltip-geo-map");

            // Convert the coordinates of the event relative to the current target
            const coordinates = d3.pointer(event);

            tooltip
                .style("display", null)
                .attr("transform", `translate(${coordinates[0]}, ${coordinates[1]})`);

            const rect = tooltip.selectAll("rect")
                .data([,])
                .join("rect")
                .attr("rx", 5)
                .attr("ry", 5)
                .attr("x", 20)
                .attr("y", 0)
                .attr("fill", "white")
                .attr("stroke", "#ccc")
                .attr("stroke-width", 1);

            const text = tooltip.selectAll("text")
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

        function onPointerLeave(event, datum) {
            // Reset styles
            visual
                .selectAll(".country")
                .transition()
                .duration(100)
                .style("opacity", 1);

            // Hide tooltip
            visual
                .select(".tooltip-geo-map")
                .style("display", "none");
        }

        const that = this;

        function update(mapData, populationData) {
            const geoJson = topojson.feature(mapData, mapData.objects.countries).features;

            // Filter the list of countries by country code and assign the population data
            // to each matching country.
            populationData.forEach(row => {
                geoJson
                    .filter(d => d.properties.countryCode === row.countryCode)
                    .forEach(country => country.properties = row);
            });

            const scale = d3.scaleLinear()
                .domain([0, d3.max(populationData, d => d.count)])
                .range([
                    that._options.color.range.start,
                    that._options.color.range.end
                ])

            visual
                .selectAll('.country')
                .data(geoJson)
                .enter()
                .append('path')
                .attr('class', 'country')
                .attr('d', geoGenerator)
                .attr("fill", d => {
                    const data = d.properties.count;

                    return data ? scale(data) : 'rgb(245, 245, 245)';
                })
                .on("pointerenter pointermove", onPointerMove)
                .on("pointerleave", onPointerLeave)
                .on("touchstart", event => event.preventDefault());

            // Create the tooltip container
            visual
                .append("g")
                .attr("class", "tooltip-geo-map");
        }

        d3.json('/index.php?route=%2Fmodule%2F_webtrees-statistics_%2FAsset&asset=js/world-map-topo.json')
            .then(function (json) {
                update(json, data)
            });

        return svg.node();
    }
}
