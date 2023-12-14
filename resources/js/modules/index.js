/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import {Chart} from "./lib/chart";

/**
 * The application class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
export class Statistic {
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
        this.chart = new Chart();

        return this.chart.draw(identifier, data, options);
    }
}
