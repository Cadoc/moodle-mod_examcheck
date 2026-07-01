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
 *  description here.
 *
 * @module     'mod_examcheck';   // Full name of the plugin (used for diagnostics)./confirm_modal
 * @copyright  2026  <>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import Modal from 'core/modal';

/**
 * @typedef {Object} ScanResultModalConfig
 * @property {Object} templateContext
 * @property {String} templateContext.step_name
 * @property {String} templateContext.user_fullname
 * @property {String} templateContext.user_picture
 * @property {String} templateContext.scan_field_name
 * @property {String} templateContext.scan_value
 * @property {String} templateContext.roster_link
 */

/**
 * @typedef {Object} InfoModalConfig
 * @extends {ScanResultModalConfig}
 * @property {Object} templateContext
 * @property {String} [templateContext.message]
 * @property {Boolean} [templateContext.message_is_warning]
 */

export default class InfoModal extends Modal {
    static TYPE = "mod_examcheck/info_modal";
    static TEMPLATE = "mod_examcheck/info_modal";

    /**
     * @param {InfoModalConfig} modalConfig
     */
    static async create(modalConfig) {
        return await super.create(modalConfig);
    }
}