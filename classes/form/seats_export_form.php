<?php
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

namespace mod_examcheck\form;

use moodleform;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Scope picker for the seat export: the seat list alone, or the seat list plus the roster.
 *
 * Both scopes produce CSV, so there is no data format to choose: the second one is a zip
 * of two CSV files, and the first would gain nothing from a spreadsheet format it cannot
 * be re-imported from.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seats_export_form extends moodleform {
    /** @var string Download the seat list as a single CSV file. */
    const SCOPE_SEATS = 'seats';

    /** @var string Download the seat list and the roster as two CSV files in a zip. */
    const SCOPE_SEATS_AND_STUDENTS = 'seatsandstudents';

    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addGroup([
            $mform->createElement(
                'radio',
                'scope',
                '',
                get_string('exportscope_seats', 'mod_examcheck'),
                self::SCOPE_SEATS
            ),
            $mform->createElement(
                'radio',
                'scope',
                '',
                get_string('exportscope_seatsandstudents', 'mod_examcheck'),
                self::SCOPE_SEATS_AND_STUDENTS
            ),
        ], 'scopegroup', get_string('exportscope', 'mod_examcheck'), ['<br/>'], false);
        $mform->addHelpButton('scopegroup', 'exportscope', 'mod_examcheck');
        $mform->setDefault('scope', self::SCOPE_SEATS);
        $mform->setType('scope', PARAM_ALPHA);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(true, get_string('download'));
    }

    /**
     * Reject a scope that is not one of the two we know how to stream.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        $scope = $data['scope'] ?? '';
        if (!in_array($scope, [self::SCOPE_SEATS, self::SCOPE_SEATS_AND_STUDENTS], true)) {
            $errors['scopegroup'] = get_string('required');
        }

        return $errors;
    }
}
