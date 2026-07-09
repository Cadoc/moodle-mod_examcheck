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

use mod_examcheck\local\seats;
use moodleform;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Edit the seat list of an instance: one free-text seat label per line.
 *
 * Because changing the list resets every seat assignment, the form demands an
 * explicit acknowledgement checkbox whenever assignments exist and the list
 * actually changed (a redirect-confirm could not carry the textarea payload).
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seats_edit_form extends moodleform {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $hasassignments = !empty($this->_customdata['hasassignments']);
        $assignmentcount = (int) ($this->_customdata['assignmentcount'] ?? 0);

        $mform->addElement(
            'textarea',
            'seatlist',
            get_string('seatlist', 'mod_examcheck'),
            ['rows' => 12, 'cols' => 40]
        );
        $mform->setType('seatlist', PARAM_TEXT);
        $mform->addHelpButton('seatlist', 'seatlist', 'mod_examcheck');

        if ($hasassignments) {
            $mform->addElement(
                'static',
                'resetwarning',
                '',
                get_string('editseatswarning', 'mod_examcheck', $assignmentcount)
            );
            $mform->addElement(
                'advcheckbox',
                'confirmreset',
                get_string('confirmresetseats', 'mod_examcheck')
            );
            $mform->setType('confirmreset', PARAM_BOOL);
        }

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Server-side validation: reject overlong or duplicate labels, and require the
     * reset acknowledgement when assignments exist and the list actually changed.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files (unused).
     * @return array Field => error message.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        try {
            $labels = seats::clean_labels(self::split_lines((string) ($data['seatlist'] ?? '')));
        } catch (\moodle_exception $e) {
            $errors['seatlist'] = $e->getMessage();
            return $errors;
        }

        $current = array_map('strval', (array) ($this->_customdata['currentlabels'] ?? []));
        $changed = $labels !== $current;

        if ($changed && !empty($this->_customdata['hasassignments']) && empty($data['confirmreset'])) {
            $errors['confirmreset'] = get_string('confirmresetseatsrequired', 'mod_examcheck');
        }

        return $errors;
    }

    /**
     * Split a textarea payload into one raw label per line.
     *
     * @param string $text The textarea content.
     * @return string[] Raw lines (not yet trimmed or filtered).
     */
    public static function split_lines(string $text): array {
        return preg_split('/\R/u', $text) ?: [];
    }
}
