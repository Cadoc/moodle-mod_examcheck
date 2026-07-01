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
 * Quick search input for the checking roster.
 *
 * Adds an always-visible text input next to the quick-filter buttons (see
 * roster_quickfilter) that narrows the visible rows as the invigilator types,
 * without requiring a page reload or interaction with the datafilter chip bar.
 *
 * Matching is client-side and case-insensitive. The search term is tested
 * against the text content of every non-step cell in a row (student name,
 * match field, identity fields, groups), so it works for both name and ID
 * number lookups.
 *
 * Non-matching rows receive the CSS class `examcheck-search-hidden` (hidden
 * via styles.css). Using a class rather than the `hidden` attribute ensures
 * this module composes independently with roster_quickfilter, which uses the
 * `hidden` attribute: a row is invisible if either module hides it.
 *
 * The search re-applies automatically after every dynamic-table AJAX reload
 * (live poll, sort, page turn) so the overlay stays consistent.
 *
 * @module     mod_examcheck/roster_quicksearch
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_string as getString} from 'core/str';
import * as DynamicTable from 'core_table/dynamic';
import Notification from 'core/notification';

/** @type {string} The current search term (lower-cased). */
let currentTerm = '';

/**
 * Initialise the quick-search input for the given activity.
 *
 * @param {Number} cmid Course module id.
 */
export const init = async (cmid) => {
    const root = document.querySelector(`[data-region="examcheck-dashboard"][data-cmid="${cmid}"]`);
    const container = root ? root.querySelector('[data-region="examcheck-quickfilter"]') : null;
    if (!root || !container) {
        return;
    }

    try {
        const placeholder = await getString('quicksearchplaceholder', 'mod_examcheck');
        buildInput(container, placeholder);
        bindInput(container, root);

        // Re-apply after every dynamic-table AJAX reload (live poll, sort, page turn).
        root.addEventListener(DynamicTable.Events.tableContentRefreshed, () => {
            applySearch(root);
        });
    } catch (e) {
        Notification.exception(e);
    }
};

/**
 * Build and append the search input to the container.
 *
 * @param {HTMLElement} container   The quickfilter container div.
 * @param {String}      placeholder Localised placeholder text.
 */
const buildInput = (container, placeholder) => {
    const wrap = document.createElement('div');
    wrap.className = 'ms-3 flex-grow-1';

    const input = document.createElement('input');
    input.type = 'search';
    input.className = 'form-control form-control-sm';
    input.dataset.region = 'examcheck-quicksearch';
    input.placeholder = placeholder;
    input.setAttribute('aria-label', placeholder);
    input.autocomplete = 'off';

    wrap.appendChild(input);
    container.appendChild(wrap);
};

/**
 * Bind the input event (debounced) to the search input.
 *
 * @param {HTMLElement} container The quickfilter container div.
 * @param {HTMLElement} root      The dashboard region.
 */
const bindInput = (container, root) => {
    const input = container.querySelector('[data-region="examcheck-quicksearch"]');
    if (!input) {
        return;
    }

    const debouncedSearch = debounce((value) => {
        currentTerm = value.toLowerCase().trim();
        applySearch(root);
    }, 300);

    input.addEventListener('input', (e) => {
        debouncedSearch(e.target.value);
    });
};

/**
 * Apply the current search term to all roster rows.
 *
 * Non-matching rows receive the `examcheck-search-hidden` CSS class.
 * Rows without any step toggle (e.g. a "no students" notice row) are always
 * kept visible regardless of the search term.
 *
 * @param {HTMLElement} root The dashboard region.
 */
const applySearch = (root) => {
    root.querySelectorAll('.examcheck-roster tbody tr').forEach((row) => {
        // Rows without a toggle are structural (e.g. empty-state notice): always visible.
        if (!row.querySelector('[data-action="examcheck-toggle"]')) {
            row.classList.remove('examcheck-search-hidden');
            return;
        }

        const hide = currentTerm !== '' && !getRowText(row).includes(currentTerm);
        row.classList.toggle('examcheck-search-hidden', hide);
    });
};

/**
 * Collect the searchable text from a roster row.
 *
 * Only non-step cells are included: step cells contain toggle buttons whose
 * sr-only text ("Checked" / "Not checked") would create misleading matches.
 * The remaining cells cover the student name, scan match field, identity
 * fields, and group column.
 *
 * @param {HTMLTableRowElement} row A roster table row.
 * @returns {String} Lower-cased concatenated text of searchable cells.
 */
const getRowText = (row) => {
    return Array.from(row.cells)
        .filter((cell) => !cell.querySelector('[data-action="examcheck-toggle"]'))
        .map((cell) => cell.textContent.trim())
        .join(' ')
        .toLowerCase();
};

/**
 * Return a debounced version of fn that fires after delay milliseconds of
 * inactivity.
 *
 * @param {Function} fn    The function to debounce.
 * @param {Number}   delay Delay in milliseconds.
 * @returns {Function} The debounced function.
 */
const debounce = (fn, delay) => {
    let timer;
    return (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), delay);
    };
};
