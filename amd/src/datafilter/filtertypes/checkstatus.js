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
 * Datafilter filter type: the per-step "Check status" chip.
 *
 * The base filter type at core/datafilter/filtertype hardcodes
 * `parseInt(option, 10)` on every selected value, which would mangle our
 * "{stepid}:{checked|notchecked}" tokens into bare ints and trip the strict-
 * typed core_table\local\filter\string_filter on the server. We override
 * `values` to send the raw strings, mirroring the keyword filter precedent.
 *
 * @module     mod_examcheck/datafilter/filtertypes/checkstatus
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Filter from 'core/datafilter/filtertype';

export default class extends Filter {
    /**
     * Return the raw selected values as-is so our "stepid:status" tokens reach
     * the server intact as strings.
     *
     * @returns {String[]}
     */
    get values() {
        return this.rawValues;
    }
}
