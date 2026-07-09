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
        $paths[] = new restore_path_element('examcheck_seat', '/activity/examcheck/seats/seat');
        if ($userinfo) {
            $paths[] = new restore_path_element('examcheck_mark', '/activity/examcheck/marks/mark');
            $paths[] = new restore_path_element(
                'examcheck_seatassignment',
                '/activity/examcheck/seatassignments/seatassignment'
            );
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

        $data->requirementtype = $data->requirementtype ?? 'none';
        $data->requirementcmid = $data->requirementcmid ?? null;
        $data->requirementstepid = $data->requirementstepid ?? null;

        if (in_array($data->requirementtype, ['quiz', 'completion'], true)) {
            if (!empty($data->requirementcmid)) {
                // Remap the linked cmid to its restored counterpart. Clear the requirement
                // when the target activity is not part of this restore so we never carry a
                // dangling cmid.
                $newcmid = $this->get_mappingid('course_module', (int) $data->requirementcmid);
                $data->requirementcmid = $newcmid ?: null;
            }
            if (empty($data->requirementcmid)) {
                $data->requirementtype = 'none';
            }
        } else if ($data->requirementtype === 'step') {
            // The prerequisite step id is remapped in after_execute(): the step it
            // points at may not have been restored yet at this point (forward
            // reference), so its mapping is only guaranteed once every step is in.
            if (empty($data->requirementstepid)) {
                $data->requirementtype = 'none';
            }
        } else {
            $data->requirementtype = 'none';
        }

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
     * Restore a seat and remember the id mapping for its assignment.
     *
     * @param array $data The seat data.
     */
    protected function process_examcheck_seat($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->examcheckid = $this->get_new_parentid('examcheck');

        $newitemid = $DB->insert_record('examcheck_seats', $data);
        $this->set_mapping('examcheck_seat', $oldid, $newitemid);
    }

    /**
     * Restore a seat assignment, remapping the seat and users.
     *
     * The seat is always restored before its assignment (document order), so no
     * after_execute pass is needed. Rows whose seat or student cannot be mapped
     * are skipped; a missing assigner degrades to 0 (anonymised), matching the
     * privacy handling.
     *
     * @param array $data The seat assignment data.
     */
    protected function process_examcheck_seatassignment($data) {
        global $DB;

        $data = (object) $data;
        $data->examcheckid = $this->get_new_parentid('examcheck');
        $data->seatid = $this->get_mappingid('examcheck_seat', $data->seatid);
        $data->userid = $this->get_mappingid('user', $data->userid);
        if (empty($data->seatid) || empty($data->userid)) {
            return;
        }
        $data->assignedby = (int) $this->get_mappingid('user', $data->assignedby);

        $DB->insert_record('examcheck_seat_users', $data);
    }

    /**
     * Re-map the single completion step and add related files after restore.
     */
    protected function after_execute() {
        global $DB;

        // Re-map the configured completion step id to its restored counterpart.
        $examcheckid = $this->get_task()->get_activityid();
        if ($examcheckid) {
            $examcheck = $DB->get_record('examcheck', ['id' => $examcheckid], 'id, completionstep');
            if ($examcheck && !empty($examcheck->completionstep)) {
                $newstepid = $this->get_mappingid('examcheck_step', $examcheck->completionstep);
                $DB->set_field('examcheck', 'completionstep', (int) $newstepid, ['id' => $examcheckid]);
            }

            // Re-map each "another step checked" prerequisite to its restored step.
            // Deferred to here (not process_examcheck_step) because a step may depend on
            // a step restored after it, whose mapping only exists once every step is in.
            $stepgates = $DB->get_records(
                'examcheck_steps',
                ['examcheckid' => $examcheckid, 'requirementtype' => 'step'],
                '',
                'id, requirementstepid'
            );
            foreach ($stepgates as $gate) {
                $newstepid = empty($gate->requirementstepid)
                    ? 0
                    : (int) $this->get_mappingid('examcheck_step', (int) $gate->requirementstepid);
                if ($newstepid) {
                    $DB->set_field('examcheck_steps', 'requirementstepid', $newstepid, ['id' => $gate->id]);
                } else {
                    // The prerequisite wasn't part of this restore (e.g. a partial
                    // restore): drop the gate rather than keep a dangling reference.
                    $DB->update_record('examcheck_steps', (object) [
                        'id'                => $gate->id,
                        'requirementtype'   => 'none',
                        'requirementstepid' => null,
                    ]);
                }
            }
        }

        $this->add_related_files('mod_examcheck', 'intro', null);
    }
}
