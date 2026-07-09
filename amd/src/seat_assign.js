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
 * on change with toast feedback, plus an auto-assign action that seats every
 * remaining student at random. On a conflict (the student was seated elsewhere
 * by someone else meanwhile) the row is reverted to its previous occupant.
 *
 * The rows live in a core dynamic table, whose body is replaced wholesale when the
 * user sorts or pages. That destroys the enhanced autocompletes, so we re-enhance
 * on the table's tableContentRefreshed event.
 *
 * The free-seat and unseated-student counts live on the page root, alongside the two
 * totals (seats, and the caller's reachable roster) which never change while the page
 * is open. Assigning a student consumes exactly one free seat and one unseated student,
 * and unassigning returns one of each, so the counts stay honest without asking the
 * server: they keep the "N of M seats / X of Y students" line live and size the
 * auto-assign confirmation. Only the server decides what is actually seated.
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
import Notification, {saveCancelPromise} from 'core/notification';
import * as DynamicTable from 'core_table/dynamic';
import {getString} from 'core/str';
import {add as addToast} from 'core/toast';

/** @type {Number} Counter to keep rebuilt select ids unique. */
let rebuildCount = 0;

/** @type {String} Marks a cell whose seat is taken, hiding its search field (see styles.css). */
const TAKEN_CLASS = 'examcheck-seat-taken';

const SELECTORS = {
    root: '[data-region="examcheck-seat-assign"]',
    cell: '[data-region="examcheck-seat-cell"]',
    select: '[data-region="examcheck-seat-select"]',
    count: '[data-region="examcheck-seat-count"]',
    autoAssign: '[data-region="examcheck-autoassign"]',
    autoAssignButton: '[data-action="examcheck-autoassign"]',
};

/**
 * Initialise every seat row, every row the dynamic table renders later, and the
 * auto-assign trigger.
 *
 * @param {Number} cmid Course module id.
 */
export const init = async(cmid) => {
    const root = document.querySelector(SELECTORS.root);
    if (!root) {
        return;
    }

    const placeholder = await getString('seatsearchstudent', 'mod_examcheck');
    const noSelection = await getString('seatnoassignment', 'mod_examcheck');

    enhanceAll(root, cmid, placeholder, noSelection);

    // Sorting and paging replace the table body, dropping every enhanced autocomplete.
    // The event bubbles from the table root, which lives inside our root.
    root.addEventListener(DynamicTable.Events.tableContentRefreshed, () => {
        enhanceAll(root, cmid, placeholder, noSelection);
    });

    // The trigger sits outside the table, so it survives every refresh and is wired once.
    const button = root.querySelector(SELECTORS.autoAssignButton);
    if (button) {
        button.addEventListener('click', () => {
            autoAssign(root, button, cmid).catch(Notification.exception);
        });
    }
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
    root.querySelectorAll(SELECTORS.cell).forEach((cell) => {
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
    const select = cell.querySelector(SELECTORS.select);
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
 * Move both page counters by the same step, then repaint the count line and the trigger.
 *
 * Seating a student always costs one free seat and one unseated student; freeing a seat
 * always returns one of each. Passing 0 just repaints from the current values.
 *
 * @param {HTMLElement} root The page root.
 * @param {Number} step -1 when a student was just seated, +1 when one was freed.
 * @returns {Promise<void>}
 */
const shiftCounts = async(root, step) => {
    const free = parseInt(root.dataset.freeseats || '0', 10) + step;
    const unseated = parseInt(root.dataset.unseatedstudents || '0', 10) + step;
    await setCounts(root, free, unseated);
};

/**
 * Store the counters on the root, repaint the "N of M seats / X of Y students" line, and
 * show the trigger only while there is both a free seat and a student to put on it.
 *
 * @param {HTMLElement} root The page root.
 * @param {Number} freeseats Seats with no student.
 * @param {Number} unseatedstudents Reachable students with no seat.
 * @returns {Promise<void>}
 */
const setCounts = async(root, freeseats, unseatedstudents) => {
    // Both totals are fixed for the page's life; only the two "assigned" halves move.
    const seatcount = parseInt(root.dataset.seatcount || '0', 10);
    const rostercount = parseInt(root.dataset.rostercount || '0', 10);
    root.dataset.freeseats = String(freeseats);
    root.dataset.unseatedstudents = String(unseatedstudents);

    const count = root.querySelector(SELECTORS.count);
    if (count) {
        count.textContent = await getString('seatandstudentcount', 'mod_examcheck', {
            seats: seatcount - freeseats,
            seatstotal: seatcount,
            students: rostercount - unseatedstudents,
            studentstotal: rostercount,
        });
    }

    const trigger = root.querySelector(SELECTORS.autoAssign);
    if (trigger) {
        trigger.classList.toggle('d-none', freeseats <= 0 || unseatedstudents <= 0);
    }
};

/**
 * Confirm, then ask the server to seat every remaining student at random.
 *
 * The confirmation warns when the students outnumber the free seats: the server seats as
 * many as fit, so the teacher must know some will be left standing before committing.
 *
 * @param {HTMLElement} root The page root.
 * @param {HTMLButtonElement} button The trigger.
 * @param {Number} cmid Course module id.
 * @returns {Promise<void>}
 */
const autoAssign = async(root, button, cmid) => {
    const freeseats = parseInt(root.dataset.freeseats || '0', 10);
    const unseated = parseInt(root.dataset.unseatedstudents || '0', 10);
    if (freeseats <= 0 || unseated <= 0) {
        return;
    }

    const short = unseated > freeseats;
    const [title, body, label] = await Promise.all([
        getString(short ? 'autoassignshorttitle' : 'autoassignconfirmtitle', 'mod_examcheck'),
        getString(short ? 'autoassignshortconfirm' : 'autoassignconfirm', 'mod_examcheck', {
            students: unseated,
            seats: freeseats,
            left: unseated - freeseats,
        }),
        getString(short ? 'autoassignshortbutton' : 'autoassignconfirmbutton', 'mod_examcheck'),
    ]);

    // saveCancelPromise rejects on Cancel and on dismiss (Escape, backdrop) alike. Any
    // rejection means "do not write": that is the safe direction for a bulk action.
    let confirmed = true;
    await saveCancelPromise(title, body, label, {triggerElement: button}).catch(() => {
        confirmed = false;
    });
    if (!confirmed) {
        return;
    }

    button.disabled = true;
    try {
        const result = await Ajax.call([{
            methodname: 'mod_examcheck_auto_assign_seats',
            args: {cmid},
        }])[0];

        addToast(result.message, {type: result.assigned > 0 ? 'success' : 'info'});
        await DynamicTable.refreshTableContent(DynamicTable.getTableFromId(`examcheck-seatassign-${cmid}`));
        await setCounts(root, result.freeseats, result.unseatedstudents);
    } finally {
        button.disabled = false;
    }
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

        // Only a real seating or freeing moves the counters. "notassigned" means the seat
        // was already empty, so nothing changed hands.
        if (result.status === 'assigned') {
            await shiftCounts(cell.closest(SELECTORS.root), -1);
        } else if (result.status === 'unassigned') {
            await shiftCounts(cell.closest(SELECTORS.root), 1);
        }
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
