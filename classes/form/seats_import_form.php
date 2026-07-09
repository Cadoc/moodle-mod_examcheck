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

use csv_import_reader;
use moodleform;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');
require_once($CFG->libdir . '/csvlib.class.php');

/**
 * Upload form for the seat assignment CSV import.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seats_import_form extends moodleform {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;

        $mform->addElement('filepicker', 'seatsfile', get_string('seatsfile', 'mod_examcheck'), null, [
            'maxbytes'  => get_max_upload_file_size(),
            'accepted_types' => '.csv',
        ]);
        $mform->addRule('seatsfile', null, 'required');
        $mform->addHelpButton('seatsfile', 'seatsfile', 'mod_examcheck');

        $choices = csv_import_reader::get_delimiter_list();
        $mform->addElement('select', 'delimiter_name', get_string('csvdelimiter', 'mod_examcheck'), $choices);
        if (array_key_exists('cfg', $choices)) {
            $mform->setDefault('delimiter_name', 'cfg');
        } else if (get_string('listsep', 'langconfig') == ';') {
            $mform->setDefault('delimiter_name', 'semicolon');
        } else {
            $mform->setDefault('delimiter_name', 'comma');
        }

        $mform->addElement('select', 'encoding', get_string('csvencoding', 'mod_examcheck'), \core_text::get_encodings());
        $mform->setDefault('encoding', 'UTF-8');

        $mform->addElement('advcheckbox', 'overrideseats', get_string('overrideseats', 'mod_examcheck'));
        $mform->setType('overrideseats', PARAM_BOOL);
        $mform->addHelpButton('overrideseats', 'overrideseats', 'mod_examcheck');

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'action');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(true, get_string('import', 'mod_examcheck'));
    }
}
