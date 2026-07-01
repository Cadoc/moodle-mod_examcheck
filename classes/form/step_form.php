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

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Add or rename a check step, with the optional "requirements for checking" gate:
 * either a submitted quiz attempt, or completion of another course activity.
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
        $examcheckcmid = (int) ($this->_customdata['cmid'] ?? 0);

        $mform->addElement('text', 'name', get_string('stepname', 'mod_examcheck'), ['size' => 48]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', get_string('required'), 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        // The requirement type: only the teacher needs to know it's optional; default off.
        $mform->addElement('select', 'requirementtype', get_string('requirementtype', 'mod_examcheck'), [
            'none'       => get_string('requirementtype_none', 'mod_examcheck'),
            'quiz'       => get_string('requirementtype_quiz', 'mod_examcheck'),
            'completion' => get_string('requirementtype_completion', 'mod_examcheck'),
        ]);
        $mform->setType('requirementtype', PARAM_ALPHA);
        $mform->addHelpButton('requirementtype', 'requirementtype', 'mod_examcheck');

        // Quiz picker, shown only when requirementtype is "quiz". Only quizzes the
        // current user can see are listed, so a teacher restricted to one section
        // doesn't see other sections' quizzes.
        $quizzes = self::list_course_quizzes($courseid);
        if (empty($quizzes)) {
            $mform->addElement(
                'static',
                'noquiznote',
                '',
                get_string('noquizinthiscourse', 'mod_examcheck')
            );
            $mform->hideIf('noquiznote', 'requirementtype', 'neq', 'quiz');
            // Still register the field so save logic stays uniform; just hidden.
            $mform->addElement('hidden', 'quizcmid', 0);
            $mform->setType('quizcmid', PARAM_INT);
        } else {
            $options = [0 => get_string('choosedots')] + $quizzes;
            $mform->addElement(
                'select',
                'quizcmid',
                get_string('quizactivity', 'mod_examcheck'),
                $options
            );
            $mform->setType('quizcmid', PARAM_INT);
            $mform->addHelpButton('quizcmid', 'quizactivity', 'mod_examcheck');
            $mform->hideIf('quizcmid', 'requirementtype', 'neq', 'quiz');
            $mform->disabledIf('quizcmid', 'requirementtype', 'neq', 'quiz');
        }

        // Activity picker, shown only when requirementtype is "completion". Excludes
        // this examcheck's own course module so a step can never gate on its own
        // completion (which could depend on this very step being checked).
        $activities = self::list_course_completion_activities($courseid, $examcheckcmid);
        if (empty($activities)) {
            $mform->addElement(
                'static',
                'nocompletionnote',
                '',
                get_string('nocompletionactivitiesincourse', 'mod_examcheck')
            );
            $mform->hideIf('nocompletionnote', 'requirementtype', 'neq', 'completion');
            // Still register the field so save logic stays uniform; just hidden.
            $mform->addElement('hidden', 'completioncmid', 0);
            $mform->setType('completioncmid', PARAM_INT);
        } else {
            $options = [0 => get_string('choosedots')] + $activities;
            $mform->addElement(
                'select',
                'completioncmid',
                get_string('completionactivity', 'mod_examcheck'),
                $options
            );
            $mform->setType('completioncmid', PARAM_INT);
            $mform->addHelpButton('completioncmid', 'completionactivity', 'mod_examcheck');
            $mform->hideIf('completioncmid', 'requirementtype', 'neq', 'completion');
            $mform->disabledIf('completioncmid', 'requirementtype', 'neq', 'completion');
        }


        // Uncheck documentation mode for this step.
        $mform->addElement('select', 'uncheckmode', get_string('uncheckmode', 'mod_examcheck'), [
            0 => get_string('uncheckmode_none',      'mod_examcheck'),
            1 => get_string('uncheckmode_optional',  'mod_examcheck'),
            2 => get_string('uncheckmode_mandatory', 'mod_examcheck'),
        ]);
        $mform->setType('uncheckmode', PARAM_INT);
        $mform->setDefault('uncheckmode', 0);
        $mform->addHelpButton('uncheckmode', 'uncheckmode', 'mod_examcheck');

        $mform->addElement('selectyesno', 'uncheckfreetext', get_string('uncheckfreetext', 'mod_examcheck'));
        $mform->setType('uncheckfreetext', PARAM_INT);
        $mform->setDefault('uncheckfreetext', 1);
        $mform->addHelpButton('uncheckfreetext', 'uncheckfreetext', 'mod_examcheck');
        $mform->hideIf('uncheckfreetext', 'uncheckmode', 'eq', 0);

        $mform->addElement('hidden', 'id');
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'stepid');
        $mform->setType('stepid', PARAM_INT);
        $mform->addElement('hidden', 'action');
        $mform->setType('action', PARAM_ALPHA);

        $this->add_action_buttons(true, get_string('savechanges'));
    }

    /**
     * Server-side validation: when a requirement type is chosen, a real activity must
     * be picked, and the submitted cmid must belong to the current course. Without
     * that check the form would accept any cmid; the requirement is harmless in
     * practice but it shouldn't silently store a cross-course reference.
     *
     * @param array $data Submitted values.
     * @param array $files Submitted files (unused).
     * @return array Field => error message.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $type = $data['requirementtype'] ?? 'none';

        if ($type === 'quiz') {
            $quizcmid = (int) ($data['quizcmid'] ?? 0);
            if ($quizcmid <= 0) {
                $errors['quizcmid'] = get_string('required');
            } else {
                $allowed = self::list_course_quizzes((int) ($this->_customdata['courseid'] ?? 0));
                if (!isset($allowed[$quizcmid])) {
                    $errors['quizcmid'] = get_string('invalidcoursemodule', 'error');
                }
            }
        } else if ($type === 'completion') {
            $completioncmid = (int) ($data['completioncmid'] ?? 0);
            if ($completioncmid <= 0) {
                $errors['completioncmid'] = get_string('required');
            } else {
                $excludecmid = (int) ($this->_customdata['cmid'] ?? 0);
                $allowed = self::list_course_completion_activities(
                    (int) ($this->_customdata['courseid'] ?? 0),
                    $excludecmid
                );
                if (!isset($allowed[$completioncmid])) {
                    $errors['completioncmid'] = get_string('invalidcoursemodule', 'error');
                }
            }
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

    /**
     * Build the cmid -> formatted name list of activities in the course that track
     * completion, restricted to activities the current user is allowed to see, and
     * excluding the given course module (this examcheck instance itself).
     *
     * @param int $courseid The course id.
     * @param int $excludecmid Course module id to leave out (the examcheck instance itself).
     * @return array<int,string> Ordered by activity name, each labelled with its module type.
     */
    protected static function list_course_completion_activities(int $courseid, int $excludecmid = 0): array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if ($courseid <= 0) {
            return [];
        }
        $modinfo = get_fast_modinfo($courseid);

        $options = [];
        foreach ($modinfo->get_cms() as $cm) {
            if (!$cm->uservisible || (int) $cm->id === $excludecmid) {
                continue;
            }
            if ($cm->completion == COMPLETION_TRACKING_NONE) {
                continue;
            }
            $options[(int) $cm->id] = $cm->get_formatted_name() . ' (' . $cm->get_module_type_name() . ')';
        }
        \core_collator::asort($options);
        return $options;
    }
}
