/**
 * This file is part of the package magicsunday/webtrees-statistics.
 *
 * For the full copyright and license information, please read the
 * LICENSE file distributed with this source code.
 */

import { dispatch } from "d3-dispatch";

/**
 * Shared selection bus for cross-widget filtering. Widgets emit a
 * `selectionChanged` event with a predicate describing the current
 * filter (or `null` to clear); the bus rebroadcasts to every
 * subscriber.
 *
 * The contract is intentionally minimal — the bus does not know
 * anything about the structure of the predicate, just that it's an
 * opaque value (or null). Each subscriber decides whether the
 * predicate is relevant to its own data and applies it (or
 * ignores it).
 *
 * @typedef {object} DashboardSelection
 * @property {string} source     Stable identifier of the widget that emitted the event (`"donut.births-century"`, `"name-bubbles.top-surnames"`, ...). Subscribers use this to ignore their own emissions.
 * @property {unknown} predicate Filter payload — shape is widget-specific. `null` means "clear filter".
 *
 * @author  Rico Sonntag <mail@ricosonntag.de>
 * @license https://opensource.org/licenses/GPL-3.0 GNU General Public License v3.0
 * @link    https://github.com/magicsunday/webtrees-statistics/
 */
export class DashboardBus {
    constructor() {
        this._dispatch = dispatch("selectionChanged");
    }

    /**
     * Broadcast a selection to every subscriber. Callers should
     * include a stable `source` identifier so subscribers can
     * ignore their own emissions.
     *
     * @param {DashboardSelection} selection
     *
     * @returns {void}
     */
    emit(selection) {
        this._dispatch.call("selectionChanged", null, selection);
    }

    /**
     * Subscribe to selection changes. Returns an `unsubscribe`
     * function so callers can disconnect cleanly when the host
     * widget is torn down.
     *
     * @param {(selection: DashboardSelection) => void} callback
     *
     * @returns {() => void}
     */
    onSelectionChanged(callback) {
        const namespace = `selectionChanged.sub-${Math.random().toString(36).slice(2)}`;
        this._dispatch.on(namespace, callback);

        return () => {
            this._dispatch.on(namespace, null);
        };
    }
}
