/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import { Chart } from "./lib/chart.js";

/**
 * The application class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
export class Statistic {
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

        const node = this.chart.draw(identifier, data, options);

        // Initialise any Bootstrap popovers that are still pending
        // (e.g. the card-header info button). This is the only point
        // in the page lifecycle where the consumer is guaranteed to
        // have rendered all of its partials AND the global webtrees
        // vendor bundle has run — earlier hook points (the inline
        // bootstrap script in page.phtml, DOMContentLoaded) ran
        // before this module's bundle was available.
        if (typeof window.bootstrap !== "undefined" && window.bootstrap.Popover) {
            document
                .querySelectorAll('.wt-statistics-chart [data-bs-toggle="popover"]')
                .forEach((el) => {
                    window.bootstrap.Popover.getOrCreateInstance(el, { container: "body" });
                });
        }

        return node;
    }
}
