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
 * Checking dashboard: inline toggle marking, live polling and the "only unchecked" view.
 *
 * The roster is a core dynamic table whose body is replaced over AJAX on filter, sort
 * and column hide. All handlers are delegated on the (stable) dashboard region so they
 * survive those refreshes, and the "only unchecked" view is re-applied afterwards.
 *
 * @module     mod_examcheck/checker
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {add as addToast} from 'core/toast';
import Notification from 'core/notification';
import * as DynamicTable from 'core_table/dynamic';

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
    registerOnlyUnchecked(root);

    // The dynamic table replaces its body on filter/sort/column-hide; re-apply our view.
    root.addEventListener(DynamicTable.Events.tableContentRefreshed, () => applyOnlyUnchecked(root));

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
        const button = e.target.closest('[data-action="toggle"]');
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

    const methodname = wasChecked ? 'mod_examcheck_unmark_user' : 'mod_examcheck_mark_user';
    const args = wasChecked
        ? {cmid, stepid, userid}
        : {cmid, stepid, userid, groupid, method: 'list'};

    // core/ajax returns jQuery promises (no .finally), so re-enable from try/finally.
    button.disabled = true;
    try {
        const outcome = await Ajax.call([{methodname, args}])[0];
        applyOutcome(button, outcome);
        applyOnlyUnchecked(root);
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
 */
const applyOutcome = (button, outcome) => {
    switch (outcome.status) {
        case 'marked':
            setCellChecked(button, true, outcome.message);
            break;
        case 'conflict':
            // Someone else already checked this student: reflect reality and warn.
            setCellChecked(button, true, outcome.message);
            addToast(outcome.message, {type: 'warning'});
            break;
        case 'unmarked':
        case 'notchecked':
            setCellChecked(button, false, '');
            break;
        case 'notinroster':
            addToast(outcome.message, {type: 'danger'});
            break;
        default:
            if (outcome.message) {
                addToast(outcome.message, {type: 'info'});
            }
    }
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
 * Poll the server so marks made by other teachers appear, then re-apply the view.
 *
 * @param {HTMLElement} root The dashboard region.
 * @param {Number} cmid Course module id.
 * @returns {Promise} Resolves when the refresh completes.
 */
const refresh = (root, cmid) => {
    const first = root.querySelector('[data-action="toggle"]');
    if (!first) {
        return Promise.resolve();
    }
    const groupid = parseInt(first.dataset.groupid || '0', 10);

    return Ajax.call([{methodname: 'mod_examcheck_get_marks', args: {cmid, groupid}}])[0]
        .then((data) => {
            const marks = new Map();
            data.marks.forEach((m) => {
                marks.set(`${m.stepid}:${m.userid}`, m);
            });

            root.querySelectorAll('[data-action="toggle"]').forEach((button) => {
                if (button.disabled) {
                    return;
                }
                const mark = marks.get(`${button.dataset.stepid}:${button.dataset.userid}`);
                setCellChecked(button, Boolean(mark), mark ? mark.ago : '');
            });

            applyOnlyUnchecked(root);
            return data;
        })
        .catch(() => {
            // Stay quiet on transient refresh failures; the next tick will retry.
        });
};

/**
 * Wire the "only not-yet-checked" switch.
 *
 * @param {HTMLElement} root The dashboard region.
 */
const registerOnlyUnchecked = (root) => {
    const onlyUnchecked = root.querySelector('[data-action="onlyunchecked"]');
    if (onlyUnchecked) {
        onlyUnchecked.addEventListener('change', () => applyOnlyUnchecked(root));
    }
};

/**
 * Hide rows that are fully checked across the visible columns, when the switch is on.
 *
 * Hidden (collapsed) columns render no toggle button, so they are naturally ignored.
 *
 * @param {HTMLElement} root The dashboard region.
 */
const applyOnlyUnchecked = (root) => {
    const onlyUnchecked = root.querySelector('[data-action="onlyunchecked"]');
    const active = onlyUnchecked ? onlyUnchecked.checked : false;

    root.querySelectorAll('table.examcheck-roster tbody tr').forEach((row) => {
        if (!active) {
            row.classList.remove('d-none');
            return;
        }
        const cells = row.querySelectorAll('[data-action="toggle"]');
        const hasUnchecked = Array.from(cells).some((c) => c.dataset.checked !== '1');
        row.classList.toggle('d-none', cells.length > 0 && !hasUnchecked);
    });
};
