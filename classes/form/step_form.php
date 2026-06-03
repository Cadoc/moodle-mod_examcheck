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

require_once($GLOBALS['CFG']->libdir . '/formslib.php');

/**
 * Add or rename a check step, with the optional "requires submitted quiz attempt" gate.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class step_form extends moodleform {
    /**
     * Form definition.
     */
    public function definition() {
        $mform = $this->_form;
        $courseid = (int) ($this->_customdata['courseid'] ?? 0);

        $mform->addElement('text', 'name', get_string('stepname', 'mod_examcheck'), ['size' => 48]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // The quiz-attempt gate: only the teacher needs to know it's optional; default off.
        $mform->addElement('advcheckbox', 'requirequizattempt',
            get_string('requirequizattempt', 'mod_examcheck'));
        $mform->setType('requirequizattempt', PARAM_BOOL);
        $mform->addHelpButton('requirequizattempt', 'requirequizattempt', 'mod_examcheck');

        // Course quiz picker for the gate. Only quizzes the current user can see are listed,
        // so a teacher restricted to one section doesn't see other sections' quizzes.
        $quizzes = self::list_course_quizzes($courseid);
        if (empty($quizzes)) {
            $mform->addElement('static', 'noquiznote', '',
                get_string('noquizinthiscourse', 'mod_examcheck'));
            $mform->hideIf('noquiznote', 'requirequizattempt', 'notchecked');
            // Still register the field so save logic stays uniform; just hidden.
            $mform->addElement('hidden', 'quizcmid', 0);
            $mform->setType('quizcmid', PARAM_INT);
        } else {
            $options = [0 => get_string('choosedots')] + $quizzes;
            $mform->addElement('select', 'quizcmid',
                get_string('quizactivity', 'mod_examcheck'), $options);
            $mform->setType('quizcmid', PARAM_INT);
            $mform->addHelpButton('quizcmid', 'quizactivity', 'mod_examcheck');
            $mform->hideIf('quizcmid', 'requirequizattempt', 'notchecked');
            $mform->disabledIf('quizcmid', 'requirequizattempt', 'notchecked');
        }

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'stepid');
        $mform->setType('stepid', PARAM_INT);
        $mform->addElement('hidden', 'action');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Server-side validation: when the gate is on, a real quiz must be picked.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files (unused).
     * @return array Field => error message.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['requirequizattempt']) && empty($data['quizcmid'])) {
            $errors['quizcmid'] = get_string('required');
        }
        return $errors;
    }

    /**
     * Build the quiz cmid -> formatted name list for the given course, restricted
     * to quizzes the current user is allowed to see.
     *
     * @param int $courseid The course id.
     * @return array<int,string> Ordered by quiz name.
     */
    protected static function list_course_quizzes(int $courseid): array {
        if ($courseid <= 0) {
            return [];
        }
        $modinfo = get_fast_modinfo($courseid);
        $quizcms = $modinfo->instances['quiz'] ?? [];

        $options = [];
        foreach ($quizcms as $cm) {
            if (!$cm->uservisible) {
                continue;
            }
            $options[(int) $cm->id] = $cm->get_formatted_name();
        }
        \core_collator::asort($options);
        return $options;
    }
}
