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
 * Seat assignment page: one student autocomplete per seat row, saving instantly
 * on change with toast feedback. On a conflict (the student was seated elsewhere
 * by someone else meanwhile) the row is reverted to its previous occupant.
 *
 * The rows live in a core dynamic table, whose body is replaced wholesale when the
 * user sorts or pages. That destroys the enhanced autocompletes, so we re-enhance
 * on the table's tableContentRefreshed event.
 *
 * Note: core/ajax returns jQuery promises without .finally, so the handlers use
 * async/await with try/finally instead.
 *
 * @module     mod_examcheck/seat_assign
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import AutoComplete from 'core/form-autocomplete';
import Notification from 'core/notification';
import TableEvents from 'core_table/local/dynamic/events';
import {getString} from 'core/str';
import {add as addToast} from 'core/toast';

/** @type {Number} Counter to keep rebuilt select ids unique. */
let rebuildCount = 0;

/** @type {String} Marks a cell whose seat is taken, hiding its search field (see styles.css). */
const TAKEN_CLASS = 'examcheck-seat-taken';

/**
 * Initialise every seat row, and every row the dynamic table renders later.
 *
 * @param {Number} cmid Course module id.
 */
export const init = async(cmid) => {
    const root = document.querySelector('[data-region="examcheck-seat-assign"]');
    if (!root) {
        return;
    }

    const placeholder = await getString('seatsearchstudent', 'mod_examcheck');
    const noSelection = await getString('seatnoassignment', 'mod_examcheck');

    enhanceAll(root, cmid, placeholder, noSelection);

    // Sorting and paging replace the table body, dropping every enhanced autocomplete.
    // The event bubbles from the table root, which lives inside our root.
    root.addEventListener(TableEvents.tableContentRefreshed, () => {
        enhanceAll(root, cmid, placeholder, noSelection);
    });
};

/**
 * Enhance every seat cell below the root that is not enhanced yet.
 *
 * @param {HTMLElement} root The page root.
 * @param {Number} cmid Course module id.
 * @param {String} placeholder Autocomplete placeholder text.
 * @param {String} noSelection Text shown when the seat has no assignment.
 */
const enhanceAll = (root, cmid, placeholder, noSelection) => {
    root.querySelectorAll('[data-region="examcheck-seat-cell"]').forEach((cell) => {
        if (cell.dataset.enhanced === '1') {
            return;
        }
        enhanceCell(cell, cmid, placeholder, noSelection).catch(Notification.exception);
    });
};

/**
 * Enhance the cell's select into an autocomplete and wire the change handler.
 *
 * @param {HTMLElement} cell The seat cell.
 * @param {Number} cmid Course module id.
 * @param {String} placeholder Autocomplete placeholder text.
 * @param {String} noSelection Text shown when the seat has no assignment.
 * @returns {Promise<void>}
 */
const enhanceCell = async(cell, cmid, placeholder, noSelection) => {
    cell.dataset.enhanced = '1';
    const select = cell.querySelector('[data-region="examcheck-seat-select"]');
    select.addEventListener('change', () => {
        onChange(cell, select, cmid, placeholder, noSelection).catch(Notification.exception);
    });
    await AutoComplete.enhance(
        '#' + select.id,
        false,
        'mod_examcheck/seat_candidates',
        placeholder,
        false,
        true,
        noSelection,
        true
    );
    syncCellState(cell);
};

/**
 * Show the student search field only while the seat is free.
 *
 * A taken seat already shows its occupant plus the autocomplete's remove control, so the
 * search field is noise; removing the occupant brings it straight back.
 *
 * @param {HTMLElement} cell The seat cell.
 */
const syncCellState = (cell) => {
    const taken = parseInt(cell.dataset.previousid || '0', 10) > 0;
    cell.classList.toggle(TAKEN_CLASS, taken);
};

/**
 * Persist a selection change: assign the chosen student, or unassign on clear.
 *
 * @param {HTMLElement} cell The seat cell.
 * @param {HTMLSelectElement} select The underlying (hidden) select.
 * @param {Number} cmid Course module id.
 * @param {String} placeholder Autocomplete placeholder text.
 * @param {String} noSelection Text shown when the seat has no assignment.
 * @returns {Promise<void>}
 */
const onChange = async(cell, select, cmid, placeholder, noSelection) => {
    if (cell.dataset.busy === '1') {
        return;
    }

    const seatid = parseInt(cell.dataset.seatid, 10);
    const previousid = parseInt(cell.dataset.previousid || '0', 10);
    const value = parseInt(select.value || '0', 10);
    if (value === previousid) {
        return;
    }

    cell.dataset.busy = '1';
    try {
        let result;
        if (value === 0) {
            result = await Ajax.call([{
                methodname: 'mod_examcheck_unassign_seat',
                args: {cmid, seatid},
            }])[0];
        } else {
            result = await Ajax.call([{
                methodname: 'mod_examcheck_assign_seat',
                args: {cmid, seatid, userid: value},
            }])[0];
        }

        if (result.status === 'conflict') {
            // The student was seated elsewhere in the meantime: warn and revert.
            addToast(result.message, {type: 'warning'});
            await rebuildCell(cell, cmid, placeholder, noSelection);
            return;
        }

        cell.dataset.previousid = String(value);
        cell.dataset.previouslabel = value === 0 ? '' : result.userlabel;
        addToast(result.message, {type: result.status === 'assigned' ? 'success' : 'info'});
    } catch (err) {
        await rebuildCell(cell, cmid, placeholder, noSelection);
        Notification.exception(err);
    } finally {
        cell.dataset.busy = '0';
        syncCellState(cell);
    }
};

/**
 * Revert a cell to its last committed occupant by rebuilding its select and
 * re-enhancing it (core/form-autocomplete offers no API to refresh an enhanced
 * field after a programmatic value change).
 *
 * @param {HTMLElement} cell The seat cell.
 * @param {Number} cmid Course module id.
 * @param {String} placeholder Autocomplete placeholder text.
 * @param {String} noSelection Text shown when the seat has no assignment.
 * @returns {Promise<void>}
 */
const rebuildCell = async(cell, cmid, placeholder, noSelection) => {
    const seatid = parseInt(cell.dataset.seatid, 10);
    const previousid = parseInt(cell.dataset.previousid || '0', 10);
    const previouslabel = cell.dataset.previouslabel || '';

    rebuildCount++;
    const select = document.createElement('select');
    select.id = `examcheck-seat-select-${seatid}-r${rebuildCount}`;
    select.dataset.region = 'examcheck-seat-select';
    select.dataset.seatid = String(seatid);
    select.appendChild(new Option('', ''));
    if (previousid > 0) {
        select.appendChild(new Option(previouslabel, String(previousid), true, true));
    }

    // Keep the hidden accessibility label pointing at the fresh select.
    const label = cell.querySelector('label');
    while (cell.lastChild !== label && cell.lastChild !== null) {
        cell.removeChild(cell.lastChild);
    }
    if (label) {
        label.setAttribute('for', select.id);
    }
    cell.appendChild(select);

    await enhanceCell(cell, cmid, placeholder, noSelection);
};
