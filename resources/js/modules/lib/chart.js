/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import { DonutChart } from "./chart/donut-chart.js";
import { StreamGraph } from "./chart/stream-graph.js";
import { WorldMap } from "./chart/world-map.js";

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
     * Dispatch table mapping `options.type` to the renderer factory that
     * produces the actual chart. Adding a new chart variant is a
     * two-line change here plus the renderer class itself.
     *
     * @returns {Object<String, function(String, Object, *): Node|null>}
     */
    renderers() {
        return {
            "donut":        (id, opts, data) => new DonutChart(id, opts).draw(data),
            "world-map":    (id, opts, data) => new WorldMap(id, opts).draw(data),
            "stream-graph": (id, opts, data) => new StreamGraph(id, opts).draw(data),
        };
    }

    /**
     * Render the chart whose key matches `options.type`. Returns null
     * when no renderer is registered for the requested type.
     *
     * @param {String} identifier
     * @param {Array}  data
     * @param {Object} options    A list of options passed from outside to the application
     *
     * @param {String} options.type
     * @param {Margin} [options.margin]
     *
     * @returns {Node|null}
     */
    draw(identifier, data, options) {
        const renderer = this.renderers()[options.type] || null;

        if (renderer === null) {
            return null;
        }

        return renderer(identifier, options, data);
    }
}
