/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import * as d3 from "./../lib/d3";
import * as topojson from "./../lib/topojson";
import {DonutChart} from "./chart/donut-chart.js";
import {WorldMap} from "./chart/world-map.js";

/**
 * The margin object.
 *
 * @typedef {object} Margin
 * @property {Number} top
 * @property {Number} left
 * @property {Number} bottom
 * @property {Number} right
 */

/**
 * The chart class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
export class Chart
{
    /**
     * Constructor.
     */
    constructor() {
    }

    /**
     * @param {String} identifier
     * @param {Array}  data
     * @param {Object} options  A list of options passed from outside to the application
     *
     * @param {String} options.type
     * @param {Margin} options.margin
     *
     * @returns {HTMLElement}
     */
    draw(identifier, data, options) {
        // let figure = document.createElement("figure");
        let chart = null;

        if (options.type === "donut") {
            chart = this.drawDonutChart(identifier, options, data);
        }

        if (options.type === "world-map") {
            chart = this.drawGeoMap(identifier, options, data);
        }

        return chart;
        // figure.append(chart);

        // return figure;
    }

    /**
     * Draws a donut chart and returns the created SVG node element.
     *
     * @param {String} identifier
     * @param {Object} options    A list of options passed from outside to the application
     * @param {Array}  data
     *
     * @return {Node}
     */
    drawDonutChart(identifier, options, data) {
        const donutChart = new DonutChart(identifier, options);
        return donutChart.draw(data);
    }

    /**
     * Draws a geo map and returns the created SVG node element.
     *
     * @param {String} identifier
     * @param {Object} options  A list of options passed from outside to the application
     * @param {Array}  data
     *
     * @return {Node}
     */
    drawGeoMap(identifier, options, data) {
        const worldMap = new WorldMap(identifier, options);
        return worldMap.draw(data);
    }
}
