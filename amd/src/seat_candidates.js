// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Ajax handler for the seat assignment autocompletes: searches the roster for
 * students who can still be assigned to a seat.
 *
 * @module     mod_examcheck/seat_candidates
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {getString} from 'core/str';

/**
 * HTML-escape a plain-text label: the autocomplete renders suggestion labels
 * as raw HTML, so server-supplied text must be escaped here.
 *
 * @param {String} text The plain text.
 * @returns {String} The escaped HTML.
 */
const escapeHtml = (text) => {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
};

/**
 * Load the students matching the query for the seat select identified by selector.
 *
 * @param {String} selector The selector of the auto complete element.
 * @param {String} query The query string.
 * @param {Function} callback A callback function receiving an array of results.
 * @param {Function} failure A function to call in case of failure, receiving the error message.
 */
export async function transport(selector, query, callback, failure) {
    const select = document.querySelector(selector);
    const root = select ? select.closest('[data-region="examcheck-seat-assign"]') : null;
    if (!root) {
        failure(`Seat assign region not found for ${selector}`);
        return;
    }

    try {
        const response = await Ajax.call([{
            methodname: 'mod_examcheck_search_seat_candidates',
            args: {
                cmid: parseInt(root.dataset.cmid, 10),
                query: query,
            },
        }])[0];

        if (response.overflow) {
            callback(await getString('seattoomanyresults', 'mod_examcheck'));
            return;
        }

        callback(response.list.map((user) => ({
            id: user.id,
            label: escapeHtml(user.label),
        })));
    } catch (e) {
        failure(e);
    }
}

/**
 * Process the results for auto complete elements.
 *
 * @param {String} selector The selector of the auto complete element.
 * @param {Array} results An array or results returned by {@see transport()}.
 * @return {Array} New array of the selector options.
 */
export function processResults(selector, results) {
    if (!Array.isArray(results)) {
        return results;
    }
    return results.map((result) => ({value: result.id, label: result.label}));
}
