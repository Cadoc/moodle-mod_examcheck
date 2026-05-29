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
 * Restore structure step for mod_examcheck.
 *
 * @package    mod_examcheck
 * @subpackage backup-moodle2
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Structure step to restore one examcheck activity.
 */
class restore_examcheck_activity_structure_step extends restore_activity_structure_step {

    /**
     * Define the restore paths.
     *
     * @return array The wrapped activity paths.
     */
    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('examcheck', '/activity/examcheck');
        $paths[] = new restore_path_element('examcheck_step', '/activity/examcheck/steps/step');
        if ($userinfo) {
            $paths[] = new restore_path_element('examcheck_mark', '/activity/examcheck/marks/mark');
        }

        return $this->prepare_activity_structure($paths);
    }

    /**
     * Restore the examcheck instance record.
     *
     * @param array $data The instance data.
     */
    protected function process_examcheck($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();

        $newitemid = $DB->insert_record('examcheck', $data);
        $this->apply_activity_instance($newitemid);
    }

    /**
     * Restore a step and remember the id mapping for its marks and completion.
     *
     * @param array $data The step data.
     */
    protected function process_examcheck_step($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->examcheckid = $this->get_new_parentid('examcheck');

        $newitemid = $DB->insert_record('examcheck_steps', $data);
        $this->set_mapping('examcheck_step', $oldid, $newitemid);
    }

    /**
     * Restore a recorded check, remapping the step and users.
     *
     * @param array $data The mark data.
     */
    protected function process_examcheck_mark($data) {
        global $DB;

        $data = (object) $data;
        $data->examcheckid = $this->get_new_parentid('examcheck');
        $data->stepid = $this->get_mappingid('examcheck_step', $data->stepid);
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->checkedby = $this->get_mappingid('user', $data->checkedby);

        $DB->insert_record('examcheck_marks', $data);
    }

    /**
     * Re-map the single completion step and add related files after restore.
     */
    protected function after_execute() {
        global $DB;

        // Re-map the configured completion step id to its restored counterpart.
        $examcheckid = $this->get_new_parentid('examcheck');
        if ($examcheckid) {
            $examcheck = $DB->get_record('examcheck', ['id' => $examcheckid], 'id, completionstep');
            if ($examcheck && !empty($examcheck->completionstep)) {
                $newstepid = $this->get_mappingid('examcheck_step', $examcheck->completionstep);
                $DB->set_field('examcheck', 'completionstep', (int) $newstepid, ['id' => $examcheckid]);
            }
        }

        $this->add_related_files('mod_examcheck', 'intro', null);
    }
}
