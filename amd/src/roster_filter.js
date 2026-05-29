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
 * Wire the roster datafilter bar to the roster dynamic table.
 *
 * Modelled on core_user/participants_filter, without the course id filter: the
 * roster table carries its course module id in its unique id instead.
 *
 * @module     mod_examcheck/roster_filter
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import CoreFilter from 'core/datafilter';
import * as DynamicTable from 'core_table/dynamic';
import Selectors from 'core/datafilter/selectors';
import Notification from 'core/notification';

/**
 * Initialise the roster filter on the element with the given id.
 *
 * @param {String} filterRegionId The id of the filter element.
 */
export const init = (filterRegionId) => {
    const filterSet = document.getElementById(filterRegionId);
    if (!filterSet) {
        return;
    }

    const coreFilter = new CoreFilter(filterSet, (filters, pendingPromise) => {
        DynamicTable.setFilters(
            DynamicTable.getTableFromId(filterSet.dataset.tableRegion),
            {
                jointype: parseInt(filterSet.querySelector(Selectors.filterset.fields.join).value, 10),
                filters,
            }
        )
            .then((result) => {
                pendingPromise.resolve();
                return result;
            })
            .catch(Notification.exception);
    });

    coreFilter.init();
};
