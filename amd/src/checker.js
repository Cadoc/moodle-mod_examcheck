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
 * Checking dashboard: inline toggle marking and live polling.
 *
 * The roster is a core dynamic table whose body is replaced over AJAX on filter,
 * sort and column hide. All handlers are delegated on the (stable) dashboard
 * region so they survive those refreshes. When the live poll detects a state
 * change AND the server-side check-status filter is active, we trigger a table
 * refresh so rows that no longer match the filter drop out (or reappear).
 *
 * @module     mod_examcheck/checker
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {show as showUncheckDialog} from 'mod_examcheck/uncheck_dialog';
import {add as addToast} from 'core/toast';
import Notification from 'core/notification';
import * as DynamicTable from 'core_table/dynamic';

// Cache the previous poll's mark key set so we can detect changes affecting
// rows that aren't currently in the DOM (e.g. a now-unmarked student whose
// row was filtered out by the check-status chip and so has no toggle button
// to compare against). Null until the first poll completes.
let lastMarkKeySet = null;

/**
 * Initialise the dashboard behaviour.
 *
 * @param {Number} cmid Course module id.
 * @param {Number} pollInterval Live refresh interval in seconds (0 disables it).
 */
export const init = (cmid, pollInterval) => {
    const root = document.querySelector('[data-region="examcheck-dashboard"]');
    if (!root) {
        return;
    }

    registerToggles(root, cmid);

    if (pollInterval > 0) {
        window.setInterval(() => refresh(root, cmid), pollInterval * 1000);
    }
};

/**
 * Delegate clicks on the per-step toggle buttons (survives dynamic-table refreshes).
 *
 * @param {HTMLElement} root The dashboard region.
 * @param {Number} cmid Course module id.
 */
const registerToggles = (root, cmid) => {
    root.addEventListener('click', (e) => {
        // Plugin-namespaced action: avoids clashing with core checkbox-toggleall,
        // which also emits data-action="toggle" on its master/slave checkboxes.
        const button = e.target.closest('[data-action="examcheck-toggle"]');
        if (!button || button.disabled) {
            return;
        }
        e.preventDefault();
        toggle(root, button, cmid);
    });
};

/**
 * Mark or unmark a single student/step on click.
 *
 * @param {HTMLElement} root The dashboard region.
 * @param {HTMLElement} button The toggle button.
 * @param {Number} cmid Course module id.
 */
const toggle = async (root, button, cmid) => {
    const wasChecked = button.dataset.checked === '1';
    const stepid = parseInt(button.dataset.stepid, 10);
    const userid = parseInt(button.dataset.userid, 10);
    const groupid = parseInt(button.dataset.groupid || '0', 10);

    // Belt-and-braces: a button without a valid stepid/userid would serialise NaN as
    // null over the wire and trip the typed PHP signature. Skip silently.
    if (!Number.isFinite(stepid) || !Number.isFinite(userid)) {
        return;
    }

    // When unchecking, check whether this step requires or offers reason documentation.
    let reasonkey = '';
    let reasontext = '';
    if (wasChecked) {
        const uncheckmode = parseInt(button.dataset.uncheckmode || '0', 10);
        if (uncheckmode > 0) {
            const dashboardRoot = button.closest('[data-region="examcheck-dashboard"]');
            const reasons = JSON.parse(dashboardRoot?.dataset.uncheckreasons || '[]');
            const allowFreetext = button.dataset.uncheckfreetext === '1';
            const mandatory = uncheckmode === 2;
            try {
                const result = await showUncheckDialog(reasons, allowFreetext, mandatory);
                reasonkey = result.reasonkey;
                reasontext = result.reasontext;
            } catch {
                // User cancelled the dialog — abort the uncheck.
                return;
            }
        }
    }

    const methodname = wasChecked ? 'mod_examcheck_unmark_user' : 'mod_examcheck_mark_user';
    const args = wasChecked
        ? {cmid, stepid, userid, reasonkey, reasontext}
        : {cmid, stepid, userid, groupid, method: 'list'};

    // core/ajax returns jQuery promises (no .finally), so re-enable from try/finally.
    button.disabled = true;
    try {
        const outcome = await Ajax.call([{methodname, args}])[0];
        const changed = applyOutcome(button, outcome);
        if (changed && isCheckStatusFilterActive(root)) {
            // The row may no longer satisfy the active filter — re-query server-side.
            refreshTable(cmid);
        }
    } catch (err) {
        Notification.exception(err);
    } finally {
        button.disabled = false;
    }
};

/**
 * Apply a web service outcome to a cell and toast where useful.
 *
 * @param {HTMLElement} button The toggle button acted on.
 * @param {Object} outcome The web service outcome.
 * @returns {Boolean} Whether the cell's checked state actually changed.
 */
const applyOutcome = (button, outcome) => {
    const before = button.dataset.checked === '1';
    let after = before;
    switch (outcome.status) {
        case 'marked':
            after = true;
            setCellChecked(button, true, outcome.message);
            break;
        case 'conflict':
            // Someone else already checked this student: reflect reality and warn.
            after = true;
            setCellChecked(button, true, outcome.message);
            addToast(outcome.message, {type: 'warning'});
            break;
        case 'unmarked':
        case 'notchecked':
            after = false;
            setCellChecked(button, false, '');
            break;
        case 'notinroster':
            addToast(outcome.message, {type: 'danger'});
            break;
        case 'requirementnotmet':
            // Step's requirement gate refused: keep the cell untouched and warn.
            addToast(outcome.message, {type: 'danger'});
            break;
        default:
            if (outcome.message) {
                addToast(outcome.message, {type: 'info'});
            }
    }
    return before !== after;
};

/**
 * Update a cell's visual checked state.
 *
 * @param {HTMLElement} button The toggle button.
 * @param {Boolean} checked Whether the cell is now checked.
 * @param {String} title Tooltip text to set.
 */
const setCellChecked = (button, checked, title) => {
    button.dataset.checked = checked ? '1' : '0';
    button.setAttribute('aria-pressed', checked ? 'true' : 'false');
    button.classList.toggle('btn-success', checked);
    button.classList.toggle('btn-outline-secondary', !checked);
    if (title) {
        button.setAttribute('title', title);
    } else {
        button.removeAttribute('title');
    }
    const icon = button.querySelector('i.fa');
    if (icon) {
        icon.classList.toggle('fa-check', checked);
        icon.classList.toggle('fa-square-o', !checked);
    }
};

/**
 * Poll the server so marks made by other teachers appear. If the active filter
 * may now exclude (or include) some rows, follow up with a full table refresh.
 *
 * @param {HTMLElement} root The dashboard region.
 * @param {Number} cmid Course module id.
 * @returns {Promise} Resolves when the refresh completes.
 */
const refresh = (root, cmid) => {
    const first = root.querySelector('[data-action="examcheck-toggle"]');
    if (!first) {
        return Promise.resolve();
    }
    const groupid = parseInt(first.dataset.groupid || '0', 10);

    return Ajax.call([{methodname: 'mod_examcheck_get_marks', args: {cmid, groupid}}])[0]
        .then((data) => {
            const marks = new Map();
            const currentKeys = new Set();
            data.marks.forEach((m) => {
                const key = `${m.stepid}:${m.userid}`;
                marks.set(key, m);
                currentKeys.add(key);
            });

            // Patch any visible toggle whose state drifted from the server.
            let visibleChanged = false;
            root.querySelectorAll('[data-action="examcheck-toggle"]').forEach((button) => {
                if (button.disabled) {
                    return;
                }
                const key = `${button.dataset.stepid}:${button.dataset.userid}`;
                const mark = marks.get(key);
                const ischecked = Boolean(mark);
                if (ischecked !== (button.dataset.checked === '1')) {
                    visibleChanged = true;
                }
                setCellChecked(button, ischecked, mark ? mark.ago : '');
            });

            // Detect set-level deltas independent of the DOM. This catches the case
            // where a row is currently filtered out (no toggle to compare against)
            // but a mark elsewhere changed and the filtered view should redraw.
            const setChanged = lastMarkKeySet !== null && hasSetDelta(lastMarkKeySet, currentKeys);
            lastMarkKeySet = currentKeys;

            if ((visibleChanged || setChanged) && isCheckStatusFilterActive(root)) {
                refreshTable(cmid);
            }
            return data;
        })
        .catch(() => {
            // Stay quiet on transient refresh failures; the next tick will retry.
        });
};

/**
 * Whether two Sets of mark keys differ (added, removed, or swapped entries).
 *
 * @param {Set<String>} previous The previous poll's keys.
 * @param {Set<String>} current The current poll's keys.
 * @returns {Boolean}
 */
const hasSetDelta = (previous, current) => {
    if (previous.size !== current.size) {
        return true;
    }
    for (const key of current) {
        if (!previous.has(key)) {
            return true;
        }
    }
    return false;
};

/**
 * Whether the server-side "check status" datafilter currently has any chip selected.
 *
 * Reads the rendered datafilter UI rather than parsing the filterset, because the
 * filter chips live on the page and there's no public JS hook to inspect the
 * filterset directly.
 *
 * @param {HTMLElement} root The dashboard region.
 * @returns {Boolean}
 */
const isCheckStatusFilterActive = (root) => {
    return Boolean(root.querySelector('[data-filterregion="filter"][data-filter-type="checkstatus"]'));
};

/**
 * Re-query the roster dynamic table over AJAX so the server-side filterset is reapplied.
 *
 * @param {Number} cmid Course module id.
 */
const refreshTable = (cmid) => {
    const table = DynamicTable.getTableFromId(`examcheck-roster-${cmid}`);
    if (table) {
        DynamicTable.refreshTableContent(table).catch(() => {
            // The next poll tick or user action will retry.
        });
    }
};
