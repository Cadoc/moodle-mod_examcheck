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
 * Quick view-switch tabs for the checking roster.
 *
 * Adds three buttons above the roster table:
 *
 *  - "Not yet checked" — re-applies whatever checkstatus filter the invigilator
 *    has set in the datafilter bar above (the default working view). The live
 *    auto-update removes rows as students are checked in.
 *
 *  - "Checked" — applies the inverse of the active checkstatus filter so the
 *    invigilator can review or correct already-checked students. Live update
 *    keeps running in this view.
 *
 *  - "All students" — suspends the checkstatus filter, showing everyone.
 *    Keywords and group filters from the datafilter bar are preserved. Live
 *    update keeps running.
 *
 * Switching between tabs calls DynamicTable.setFilters() so all three views
 * benefit from the full server-side live-update cycle. Keywords and group
 * filters set in the datafilter bar are always preserved — only the checkstatus
 * part of the filterset is swapped.
 *
 * The module tracks the current filterset by listening for the
 * {@see mod_examcheck/roster_filter~Events.filtersetChanged} custom event that
 * roster_filter.js dispatches after every successful filter apply.
 *
 * @module     mod_examcheck/roster_quickfilter
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import {get_strings as getStrings} from 'core/str';
import * as DynamicTable from 'core_table/dynamic';
import Notification from 'core/notification';
import {Events as FilterEvents} from 'mod_examcheck/roster_filter';

/**
 * The last filterset applied by the datafilter bar.
 * Starts empty (no filters) which matches the table's initial server-side state.
 *
 * @type {{jointype: Number, filters: Array}}
 */
let storedFilterset = {jointype: 0, filters: []};

/** @type {string} The currently active tab value: 'notchecked' | 'checked' | 'all'. */
let activeTab = 'all';

/**
 * Initialise the quick-filter tab bar for the given activity.
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
        const [labelNotChecked, labelChecked, labelAll, labelGroup] = await getStrings([
            {key: 'quickfilternotchecked', component: 'mod_examcheck'},
            {key: 'quickfilterchecked',    component: 'mod_examcheck'},
            {key: 'quickfilterall',        component: 'mod_examcheck'},
            {key: 'quickfiltergroup',      component: 'mod_examcheck'},
        ]);

        buildButtonGroup(container, labelNotChecked, labelChecked, labelAll, labelGroup);
        bindButtons(container, root, cmid);

        // Track every filterset change made via the datafilter chip bar above.
        root.addEventListener(FilterEvents.filtersetChanged, (e) => {
            storedFilterset = {jointype: e.detail.jointype, filters: e.detail.filters};

            // When the invigilator applies or changes a checkstatus chip,
            // sync the active tab to "Not yet checked" so the UI reflects reality.
            const hasCheckstatus = e.detail.filters.some((f) => f.name === 'checkstatus');
            if (hasCheckstatus) {
                setActiveTab('notchecked', container);
                activeTab = 'notchecked';
            } else {
                setActiveTab('all', container);
                activeTab = 'all';
            }
        });
    } catch (e) {
        Notification.exception(e);
    }
};

/**
 * Build and insert the Bootstrap button group into the container.
 *
 * @param {HTMLElement} container       The target div.
 * @param {String}      labelNotChecked Localised label for "Not yet checked".
 * @param {String}      labelChecked    Localised label for "Checked".
 * @param {String}      labelAll        Localised label for "All students".
 * @param {String}      labelGroup      Localised accessible name for the button group.
 */
const buildButtonGroup = (container, labelNotChecked, labelChecked, labelAll, labelGroup) => {
    const group = document.createElement('div');
    group.className = 'btn-group';
    group.setAttribute('role', 'group');
    group.setAttribute('aria-label', labelGroup);

    const buttons = [
        {value: 'notchecked', label: labelNotChecked, active: false},
        {value: 'checked',    label: labelChecked,    active: false},
        {value: 'all',        label: labelAll,        active: true},
    ];

    buttons.forEach(({value, label, active}) => {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'btn ' + (active ? 'btn-secondary' : 'btn-outline-secondary');
        btn.dataset.quickfilter = value;
        btn.textContent = label;
        btn.setAttribute('aria-pressed', active ? 'true' : 'false');
        group.appendChild(btn);
    });

    container.appendChild(group);
};

/**
 * Bind click events on the button group.
 *
 * @param {HTMLElement} container The container holding the button group.
 * @param {HTMLElement} root      The dashboard region.
 * @param {Number}      cmid      Course module id.
 */
const bindButtons = (container, root, cmid) => {
    container.addEventListener('click', (e) => {
        const btn = e.target.closest('[data-quickfilter]');
        if (!btn) {
            return;
        }

        const tab = btn.dataset.quickfilter;
        if (tab === activeTab) {
            return;
        }

        activeTab = tab;
        setActiveTab(tab, container);
        applyTab(tab, cmid);
    });
};

/**
 * Update the visual active state of the tab buttons.
 *
 * @param {String}      tab       The tab value to mark active.
 * @param {HTMLElement} container The container holding the button group.
 */
const setActiveTab = (tab, container) => {
    container.querySelectorAll('[data-quickfilter]').forEach((b) => {
        const isActive = b.dataset.quickfilter === tab;
        b.classList.toggle('btn-secondary', isActive);
        b.classList.toggle('btn-outline-secondary', !isActive);
        b.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });
};

/**
 * Apply the filterset corresponding to the given tab by calling
 * DynamicTable.setFilters(). Keywords and group filters from the datafilter
 * bar are always preserved; only the checkstatus part changes.
 *
 * @param {String} tab  The tab value: 'notchecked' | 'checked' | 'all'.
 * @param {Number} cmid Course module id.
 */
const applyTab = (tab, cmid) => {
    const table = DynamicTable.getTableFromId(`examcheck-roster-${cmid}`);
    if (!table) {
        return;
    }

    let filterset;
    switch (tab) {
        case 'notchecked':
            // Restore whatever the invigilator set in the datafilter bar.
            filterset = storedFilterset;
            break;
        case 'checked':
            // Invert the checkstatus values: notchecked ↔ checked.
            filterset = invertCheckstatus(storedFilterset);
            break;
        default: // 'all'
            // Remove the checkstatus filter; keep keywords and groups.
            filterset = withoutCheckstatus(storedFilterset);
    }

    DynamicTable.setFilters(table, filterset).catch(Notification.exception);
};

/**
 * Return a copy of the filterset with checkstatus values inverted.
 * "notchecked" becomes "checked" and vice versa; other filters are unchanged.
 *
 * @param {{jointype: Number, filters: Array}} filterset The source filterset.
 * @returns {{jointype: Number, filters: Array}} The inverted filterset.
 */
const invertCheckstatus = (filterset) => ({
    ...filterset,
    filters: filterset.filters.map((f) => {
        if (f.name !== 'checkstatus') {
            return f;
        }
        return {
            ...f,
            values: f.values.map((v) => {
                if (v.endsWith(':notchecked')) {
                    return v.replace(':notchecked', ':checked');
                }
                if (v.endsWith(':checked')) {
                    return v.replace(':checked', ':notchecked');
                }
                return v;
            }),
        };
    }),
});

/**
 * Return a copy of the filterset with the checkstatus filter removed.
 * Keywords, groups and any other filters are preserved unchanged.
 *
 * @param {{jointype: Number, filters: Array}} filterset The source filterset.
 * @returns {{jointype: Number, filters: Array}} The filterset without checkstatus.
 */
const withoutCheckstatus = (filterset) => ({
    ...filterset,
    filters: filterset.filters.filter((f) => f.name !== 'checkstatus'),
});
