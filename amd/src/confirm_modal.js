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
import ModalEvents from 'core/modal_events';

export default class ConfirmModal extends Modal {
    static TYPE = "mod_examcheck/confirm_modal";
    static TEMPLATE = "mod_examcheck/confirm_modal";

    #wasConfirmed = false;

    /**
     * Resolves once the modal is hidden, with whether "Confirm" was clicked
     * (as opposed to cancel, backdrop click, or Escape).
     *
     * @returns {Promise<Boolean>}
     */
    wasConfirmed() {
        return new Promise((resolve) => {
            this.getRoot().on(ModalEvents.hidden, () => resolve(this.#wasConfirmed));
        });
    }

    registerEventListeners() {
        super.registerEventListeners();

        this.getRoot().on('click', '[data-action="confirm"]', () => {
            this.#wasConfirmed = true;
            this.hide();
        });
    }
}