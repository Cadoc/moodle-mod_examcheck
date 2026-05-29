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

/**
 * The main configuration form for mod_examcheck.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

/**
 * Instance settings form for the examcheck activity.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_examcheck_mod_form extends moodleform_mod {
    /**
     * Define the form fields.
     */
    public function definition() {
        $mform = $this->_form;

        // General section.
        $mform->addElement('header', 'general', get_string('general', 'form'));

        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');
        $mform->addRule('name', get_string('maximumchars', '', 255), 'maxlength', 255, 'client');

        $this->standard_intro_elements();

        // Scanning defaults section.
        $mform->addElement('header', 'scanningheader', get_string('scanningsettings', 'mod_examcheck'));

        $options = \mod_examcheck\local\scanfield::get_field_menu();
        $mform->addElement('select', 'scanfield', get_string('scanfield', 'mod_examcheck'), $options);
        $mform->setDefault('scanfield', get_config('mod_examcheck', 'defaultscanfield') ?: 'idnumber');
        $mform->addHelpButton('scanfield', 'scanfield', 'mod_examcheck');

        $mform->addElement('text', 'scanregex', get_string('scanregex', 'mod_examcheck'), ['size' => 48]);
        $mform->setType('scanregex', PARAM_RAW);
        $mform->setDefault('scanregex', (string) get_config('mod_examcheck', 'defaultscanregex'));
        $mform->addHelpButton('scanregex', 'scanregex', 'mod_examcheck');

        $mform->addElement('selectyesno', 'requireconfirm', get_string('requireconfirm', 'mod_examcheck'));
        $mform->setDefault('requireconfirm', (int) get_config('mod_examcheck', 'defaultrequireconfirm'));
        $mform->addHelpButton('requireconfirm', 'requireconfirm', 'mod_examcheck');

        // Standard course module elements (visibility, groups, etc.).
        $this->standard_coursemodule_elements();

        $this->add_action_buttons();
    }

    /**
     * Validate the form: ensure the extraction regex compiles.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Validation errors keyed by element name.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['scanregex']) && !\mod_examcheck\local\scanfield::is_valid_regex($data['scanregex'])) {
            $errors['scanregex'] = get_string('error_invalidregex', 'mod_examcheck');
        }
        return $errors;
    }

    /**
     * Add the custom completion rule controls.
     *
     * @return string[] The names of the added rule elements.
     */
    public function add_completion_rules() {
        $mform = $this->_form;
        $suffix = $this->get_suffix();

        $checkbox = 'completionchecked' . $suffix;
        $mform->addElement('checkbox', $checkbox, '', get_string('completionchecked', 'mod_examcheck'));

        // Offer "All steps" plus one option per existing step, so the teacher
        // can choose whether all steps or a single step completes the activity.
        $options = [0 => get_string('completionallsteps', 'mod_examcheck')];
        if (!empty($this->_instance)) {
            foreach (\mod_examcheck\local\steps::get_steps($this->_instance) as $step) {
                $options[(int) $step->id] = format_string($step->name);
            }
        }

        $stepsel = 'completionstep' . $suffix;
        $mform->addElement('select', $stepsel, get_string('completionstepsel', 'mod_examcheck'), $options);
        $mform->setType($stepsel, PARAM_INT);
        $mform->setDefault($stepsel, 0);
        $mform->addHelpButton($stepsel, 'completionstepsel', 'mod_examcheck');
        $mform->hideIf($stepsel, $checkbox, 'notchecked');

        return [$checkbox, $stepsel];
    }

    /**
     * Whether the custom completion rule is enabled in the submitted data.
     *
     * @param array $data Submitted form data.
     * @return bool
     */
    public function completion_rule_enabled($data) {
        $suffix = $this->get_suffix();
        return !empty($data['completionchecked' . $suffix]);
    }

    /**
     * Ensure the completion checkbox has a stored value when unlocked.
     *
     * @param stdClass $data Submitted form data, modified in place.
     */
    public function data_postprocessing($data) {
        parent::data_postprocessing($data);
        if (!empty($data->completionunlocked)) {
            $suffix = $this->get_suffix();
            $checkbox = 'completionchecked' . $suffix;
            if (empty($data->$checkbox)) {
                $data->$checkbox = 0;
            }
        }
    }
}
