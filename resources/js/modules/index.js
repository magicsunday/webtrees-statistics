/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import * as d3 from "./lib/d3";

/**
 * The application class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
export class Statistic
{
    /**
     * Constructor.
     */
    constructor()
    {
    }

    /**
     * Draws a donut chart and returns the created SVG node element.
     *
     * @param {Array}  data
     * @param {number} width
     * @param {number} height
     *
     * @return {Node}
     */
    drawDonutChart(data, width= 250, height = 250)
    {
        const radius = Math.min(width, height) / 2;

        const arc = d3.arc()
            .innerRadius(radius - 35)
            .outerRadius(radius);

        const pie = d3.pie()
            .padAngle(1 / radius)
            .sort(null)
            .value(d => d.value);

        const svg = d3.create("svg")
            .attr("class", "donutChart")
            .attr("width", width)
            .attr("height", height)
            .attr("viewBox", [-width / 2, -height / 2, width, height])
            .attr("style", "max-width: 100%; height: auto;");

        const chart = svg.append("g")
            .selectAll()
            .data(pie(data))
            .join("path")
            .attr("class", (d) => {
                return "slice" + (d.data.class ? (" " + d.data.class) : "");
            })
            .attr("fill", (d) => {
                return d.data.fill ? d.data.fill : null;
            })
            .attr("d", arc);

        chart
            .append("title")
            .text(d => `${d.data.label}: ${d.data.value.toLocaleString()}`);

        return svg.node();
    }
}
