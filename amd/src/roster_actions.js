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
 * Bottom "With selected students" bar: export, and per-step bulk check/uncheck.
 *
 * @module     mod_examcheck/roster_actions
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Ajax from 'core/ajax';
import Notification from 'core/notification';
import {add as addToast} from 'core/toast';
import * as DynamicTable from 'core_table/dynamic';
import * as CheckboxToggleAll from 'core/checkbox-toggleall';

const SELECTED = "[data-togglegroup='examcheck-roster'][data-toggle='target']:checked";

/**
 * Initialise the bulk-action bar.
 *
 * @param {Number} cmid Course module id.
 */
export const init = (cmid) => {
    const root = document.querySelector('[data-region="examcheck-dashboard"]');
    const form = document.getElementById('examcheck-rosterform');
    const select = document.getElementById('examcheck-bulkaction');
    if (!root || !form || !select) {
        return;
    }

    select.addEventListener('change', () => handle(root, form, select, cmid));

    // After the dynamic table reloads, the new checkboxes are unselected: re-sync so the
    // action menu disables itself again.
    root.addEventListener(DynamicTable.Events.tableContentRefreshed, () => {
        CheckboxToggleAll.updateTargetsFromTogglerState(root, 'examcheck-roster');
    });
};

/**
 * Run the chosen action against the currently selected students.
 *
 * @param {HTMLElement} root The dashboard region.
 * @param {HTMLFormElement} form The roster form.
 * @param {HTMLSelectElement} select The action select.
 * @param {Number} cmid Course module id.
 */
const handle = async (root, form, select, cmid) => {
    const value = select.value;
    if (!value) {
        return;
    }

    const userids = Array.from(root.querySelectorAll(SELECTED)).map((cb) => parseInt(cb.value, 10));
    if (!userids.length) {
        select.value = '';
        return;
    }

    // Export: hand the selection to export.php via the form.
    if (value.indexOf('export:') === 0) {
        form.querySelector('[data-region="dataformat"]').value = value.substring('export:'.length);
        form.submit();
        select.value = '';
        return;
    }

    // Otherwise a "mark:<stepid>" or "unmark:<stepid>" bulk action.
    const [action, stepidStr] = value.split(':');
    const stepid = parseInt(stepidStr, 10);
    const first = root.querySelector('[data-action="toggle"]');
    const groupid = first ? parseInt(first.dataset.groupid || '0', 10) : 0;

    select.disabled = true;
    try {
        const result = await Ajax.call([{
            methodname: 'mod_examcheck_bulk_action',
            args: {cmid, stepid, userids, action, groupid},
        }])[0];
        addToast(result.message, {type: 'info'});
        await DynamicTable.refreshTableContent(DynamicTable.getTableFromId(`examcheck-roster-${cmid}`));
    } catch (err) {
        Notification.exception(err);
    } finally {
        select.disabled = false;
        select.value = '';
    }
};
