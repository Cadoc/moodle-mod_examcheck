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
 * Checking dashboard: inline toggle marking, live refresh and roster filtering.
 *
 * @module     mod_examcheck/checker
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import {add as addToast} from 'core/toast';
import {getString} from 'core/str';
import Notification from 'core/notification';

/**
 * Initialise the dashboard behaviour.
 *
 * @param {Number} cmid Course module id.
 * @param {Number} groupid Selected group id.
 * @param {Number} pollInterval Live refresh interval in seconds (0 disables it).
 */
export const init = (cmid, groupid, pollInterval) => {
    const root = document.querySelector('[data-region="examcheck-dashboard"]');
    if (!root) {
        return;
    }

    registerToggles(root, cmid, groupid);
    registerFilter(root);
    updateVisibleCount(root);

    if (pollInterval > 0) {
        window.setInterval(() => refresh(root, cmid, groupid), pollInterval * 1000);
    }
};

/**
 * Wire up click handling for the per-step toggle buttons.
 *
 * @param {HTMLElement} root The dashboard root element.
 * @param {Number} cmid Course module id.
 * @param {Number} groupid Selected group id.
 */
const registerToggles = (root, cmid, groupid) => {
    root.addEventListener('click', (e) => {
        const button = e.target.closest('[data-action="toggle"]');
        if (!button || button.disabled) {
            return;
        }
        e.preventDefault();
        toggle(root, button, cmid, groupid);
    });
};

/**
 * Mark or unmark a single student/step on click.
 *
 * @param {HTMLElement} root The dashboard root element.
 * @param {HTMLElement} button The toggle button.
 * @param {Number} cmid Course module id.
 * @param {Number} groupid Selected group id.
 */
const toggle = (root, button, cmid, groupid) => {
    const wasChecked = button.dataset.checked === '1';
    const stepid = parseInt(button.dataset.stepid, 10);
    const userid = parseInt(button.dataset.userid, 10);

    const methodname = wasChecked ? 'mod_examcheck_unmark_user' : 'mod_examcheck_mark_user';
    const args = wasChecked
        ? {cmid, stepid, userid}
        : {cmid, stepid, userid, groupid, method: 'list'};

    button.disabled = true;
    Ajax.call([{methodname, args}])[0]
        .then((outcome) => {
            applyOutcome(root, button, outcome);
            return outcome;
        })
        .catch(Notification.exception)
        .finally(() => {
            button.disabled = false;
        });
};

/**
 * Apply a web service outcome to a cell and show a toast where useful.
 *
 * @param {HTMLElement} root The dashboard root element.
 * @param {HTMLElement} button The toggle button acted on.
 * @param {Object} outcome The web service outcome.
 */
const applyOutcome = (root, button, outcome) => {
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
    recomputeProgress(root, parseInt(button.dataset.stepid, 10));
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
 * Recompute and display the checked count for a step from the current DOM.
 *
 * @param {HTMLElement} root The dashboard root element.
 * @param {Number} stepid The step whose progress should be recomputed.
 */
const recomputeProgress = (root, stepid) => {
    const badge = root.querySelector(`[data-region="progress"][data-stepid="${stepid}"] [data-region="count"]`);
    if (!badge) {
        return;
    }
    const checked = root.querySelectorAll(
        `[data-action="toggle"][data-stepid="${stepid}"][data-checked="1"]`).length;
    badge.textContent = checked;
};

/**
 * Refresh the whole grid from the server so marks made by other teachers show up.
 *
 * @param {HTMLElement} root The dashboard root element.
 * @param {Number} cmid Course module id.
 * @param {Number} groupid Selected group id.
 * @returns {Promise} Resolves when the refresh completes.
 */
const refresh = (root, cmid, groupid) => {
    return Ajax.call([{methodname: 'mod_examcheck_get_marks', args: {cmid, groupid}}])[0]
        .then((data) => {
            const marks = new Map();
            data.marks.forEach((m) => {
                marks.set(`${m.stepid}:${m.userid}`, m);
            });

            root.querySelectorAll('[data-action="toggle"]').forEach((button) => {
                // Do not stomp on a button mid-request.
                if (button.disabled) {
                    return;
                }
                const key = `${button.dataset.stepid}:${button.dataset.userid}`;
                const mark = marks.get(key);
                setCellChecked(button, Boolean(mark), mark ? mark.ago : '');
            });

            data.progress.forEach((p) => {
                const badge = root.querySelector(
                    `[data-region="progress"][data-stepid="${p.stepid}"] [data-region="count"]`);
                if (badge) {
                    badge.textContent = p.count;
                }
            });
            root.querySelectorAll('[data-region="progress"] [data-region="total"]').forEach((el) => {
                el.textContent = data.total;
            });
            return data;
        })
        .catch(() => {
            // Stay quiet on transient refresh failures; the next tick will retry.
        });
};

/**
 * Wire up the search box and "only unchecked" toggle.
 *
 * @param {HTMLElement} root The dashboard root element.
 */
const registerFilter = (root) => {
    const search = root.querySelector('[data-action="filter"]');
    const onlyUnchecked = root.querySelector('[data-action="onlyunchecked"]');
    const apply = () => filterRows(root);
    if (search) {
        search.addEventListener('input', apply);
    }
    if (onlyUnchecked) {
        onlyUnchecked.addEventListener('change', apply);
    }
};

/**
 * Show or hide roster rows according to the current search and toggle.
 *
 * @param {HTMLElement} root The dashboard root element.
 */
const filterRows = (root) => {
    const search = root.querySelector('[data-action="filter"]');
    const onlyUnchecked = root.querySelector('[data-action="onlyunchecked"]');
    const term = (search ? search.value : '').trim().toLowerCase();
    const uncheckedOnly = onlyUnchecked ? onlyUnchecked.checked : false;

    root.querySelectorAll('[data-region="student"]').forEach((row) => {
        const name = (row.querySelector('.examcheck-studentname')?.textContent || '').toLowerCase();
        const idnumber = (row.querySelector('.text-muted')?.textContent || '').toLowerCase();
        const matchesTerm = !term || name.includes(term) || idnumber.includes(term);

        const cells = row.querySelectorAll('[data-action="toggle"]');
        const hasUnchecked = Array.from(cells).some((c) => c.dataset.checked !== '1');
        const matchesToggle = !uncheckedOnly || hasUnchecked;

        row.classList.toggle('d-none', !(matchesTerm && matchesToggle));
    });

    updateVisibleCount(root);
};

/**
 * Update the "x of y shown" counter and the no-results notice.
 *
 * @param {HTMLElement} root The dashboard root element.
 */
const updateVisibleCount = (root) => {
    const rows = root.querySelectorAll('[data-region="student"]');
    const visible = root.querySelectorAll('[data-region="student"]:not(.d-none)').length;
    const norows = root.querySelector('[data-region="norows"]');
    if (norows) {
        norows.classList.toggle('d-none', visible !== 0 || rows.length === 0);
    }
    const counter = root.querySelector('[data-region="visiblecount"]');
    if (counter) {
        getString('visiblecount', 'mod_examcheck', {visible, total: rows.length})
            .then((s) => {
                counter.textContent = s;
                return s;
            })
            .catch(() => {
                counter.textContent = `${visible} / ${rows.length}`;
            });
    }
};
