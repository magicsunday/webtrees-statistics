/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import * as d3 from "./../../lib/d3";
import {BaseChart} from "../base-chart.js";

/**
 * The donut chart class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
export class DonutChart extends BaseChart
{
    /**
     * The width of the chart.
     *
     * @var {Number}
     */
    _width;

    /**
     * The height of the chart, the same as the width.
     *
     * @var {Number}
     */
    _height;

    /**
     * The margin around the chart.
     *
     * @var {Number}
     */
    _margin;

    /**
     * The radius of the chart is half the width or half the height (the smallest one) minus the margin.
     *
     * @var {Number}
     */
    _radius;

    /**
     * The size of the donut hole.
     *
     * @var {Number}
     */
    _holeSize;

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

        const dimensions = this.determineAvailableDimensions(250, 250);

        this._width    = Math.min(dimensions.width, dimensions.height);
        this._height   = this._width;
        this._margin   = this._options.margin ?? 1;
        this._radius   = (this._width >> 1) - this._margin;
        this._holeSize = this._options.holeSize ?? (this._radius - (this._radius / 10));
    }

    /**
     * Draws a donut chart and returns the created SVG node element.
     *
     * @param {Array} data
     *
     * @return {Node}
     */
    draw(data) {
        const arc = d3.arc()
            .innerRadius(this._holeSize)
            .outerRadius(this._radius);

        const pie = d3.pie()
            .padAngle(1 / this._radius)
            .sort(null)
            .value(d => d.value);

        const svg = this._parent
            .append("svg")
            .attr("class", "donutChart")
            .attr("width", this._width)
            .attr("height", this._height)
            .attr("viewBox", [-this._width / 2, -this._height / 2, this._width, this._height])
            .attr("style", "max-width: 100%; height: auto;");

        const chart = svg.append("g")
            .selectAll()
            .data(pie(data))
            .join("path")
            .attr("d", arc)
            .attr("class", (d) => {
                return "slice" + (d.data.class ? (" " + d.data.class) : "");
            })
            .attr("fill", (d) => {
                return d.data.fill ? d.data.fill : null;
            });

        chart
            .append("title")
            .text(d => `${d.data.label}: ${d.data.value.toLocaleString()}`);

        return svg.node();
    }
}
