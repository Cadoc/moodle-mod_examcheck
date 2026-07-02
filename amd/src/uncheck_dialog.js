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
 * Modal dialog shown when an invigilator unchecks a student on a step that
 * requires or offers reason documentation (uncheckmode > 0).
 *
 * The dialog presents:
 *   - A dropdown of predefined reasons (if any are configured for the activity)
 *   - An optional free-text area (if the step has uncheckfreetext = 1 and the
 *     current user has mod/examcheck:uncheckfreetext)
 *
 * Resolves with {reasonkey, reasontext} when confirmed, or rejects when
 * cancelled. The caller is responsible for calling the unmark web service.
 *
 * Uses ModalSaveCancel.create() directly rather than the removed
 * core/modal_factory module (ModalFactory.create({type: ...}) no longer
 * exists in current Moodle core; each modal subclass now exposes its own
 * static create()).
 *
 * @module     mod_examcheck/uncheck_dialog
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

import ModalSaveCancel from 'core/modal_save_cancel';
import ModalEvents from 'core/modal_events';
import Templates from 'core/templates';
import {get_strings as getStrings} from 'core/str';
import Notification from 'core/notification';

/**
 * Show the uncheck reason dialog and return the invigilator's choice.
 *
 * @param {Array}   reasons      Array of {key, label} objects from the activity settings.
 * @param {boolean} allowFreetext Whether the free-text area should be shown.
 * @param {boolean} mandatory    Whether a reason is required before confirming.
 * @returns {Promise<{reasonkey: string, reasontext: string}>} Resolves on confirm, rejects on cancel.
 */
export const show = async (reasons, allowFreetext, mandatory) => {
    const [title, confirmLabel, mandatoryError] = await getStrings([
        {key: 'uncheckdialog_title',         component: 'mod_examcheck'},
        {key: 'uncheckdialog_confirm',        component: 'mod_examcheck'},
        {key: 'uncheckdialog_reasonrequired', component: 'mod_examcheck'},
    ]);

    const templateContext = {
        hasreasons:    reasons.length > 0,
        reasons,
        allowfreetext: allowFreetext,
        mandatory,
    };

    const bodyHtml = await Templates.render('mod_examcheck/uncheck_dialog', templateContext);

    return new Promise((resolve, reject) => {
        ModalSaveCancel.create({
            title,
            body: bodyHtml,
        })
            .then((modal) => {
                modal.setSaveButtonText(confirmLabel);

                modal.getRoot().on(ModalEvents.save, (e) => {
                    e.preventDefault();

                    const body       = modal.getRoot()[0].querySelector('.modal-body');
                    const select     = body.querySelector('[data-region="examcheck-reason-select"]');
                    const textarea   = body.querySelector('[data-region="examcheck-reason-text"]');
                    const reasonkey  = select ? select.value : '';
                    const reasontext = textarea ? textarea.value.trim() : '';

                    if (mandatory && !reasonkey && !reasontext) {
                        showError(body, mandatoryError);
                        return;
                    }

                    modal.hide();
                    resolve({reasonkey, reasontext});
                });

                modal.getRoot().on(ModalEvents.cancel, () => {
                    reject(new Error('cancelled'));
                });

                modal.getRoot().on(ModalEvents.hidden, () => {
                    modal.destroy();
                });

                modal.show();
                return modal;
            })
            .catch(Notification.exception);
    });
};

/**
 * Show an inline error message inside the dialog body.
 *
 * @param {HTMLElement} body    The dialog body element.
 * @param {string}      message The error message to display.
 */
const showError = (body, message) => {
    let alert = body.querySelector('.examcheck-dialog-error');
    if (!alert) {
        alert = document.createElement('div');
        alert.className = 'alert alert-danger examcheck-dialog-error mt-2';
        body.appendChild(alert);
    }
    alert.textContent = message;
};
