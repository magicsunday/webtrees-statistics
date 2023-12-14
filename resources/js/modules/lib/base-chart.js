/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import * as d3 from "./../lib/d3";

/**
 * The base chart class.
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
export class BaseChart
{
    /**
     * The identifier of the parent container.
     *
     * @var {String}
     */
    _identifier;

    /**
     * The parent container selection.
     *
     * @var {Selection}
     */
    _parent;

    /**
     * The options used to construct/configure the chart.
     *
     * @var {Object}
     */
    _options;

    /**
     * Constructor.
     *
     * @param {String} identifier
     * @param {Object} options
     */
    constructor(identifier, options) {
        this._identifier = identifier;
        this._parent     = d3.select('#' + this._identifier);
        this._options    = options;
    }

    /**
     * Returns the available width and height of the chart either from passed options or
     * derived from the parent container.
     *
     * @param {Number} width
     * @param {Number} height
     *
     * @returns {{width: number, height: number}}
     */
    determineAvailableDimensions( width, height) {
        const parentRect = this._parent.node().getBoundingClientRect();

        if (this._options.width && (this._options.width > 0)) {
            width = this._options.width;
        }

        if (this._options.height && (this._options.height > 0)) {
            height = this._options.height;
        }

        // If container dimensions are available, these take precedence over the specified values.
        if (parentRect.width > 0) {
            width = parentRect.width;
        }

        if (parentRect.height > 0) {
            height = parentRect.height;
        }

        return {
            width: width,
            height: height
        };
    }
}
