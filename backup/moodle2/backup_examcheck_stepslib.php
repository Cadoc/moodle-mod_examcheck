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
 * Backup structure step for mod_examcheck.
 *
 * @package    mod_examcheck
 * @subpackage backup-moodle2
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Defines the complete examcheck structure for backup, with annotations.
 */
class backup_examcheck_activity_structure_step extends backup_activity_structure_step {
    /**
     * Define the backup structure.
     *
     * @return backup_nested_element The wrapped activity structure.
     */
    protected function define_structure() {
        // Marks and seat assignments are user-specific data.
        $userinfo = $this->get_setting_value('userinfo');

        $examcheck = new backup_nested_element('examcheck', ['id'], [
            'name', 'intro', 'introformat',
            'scanfield', 'requireconfirm', 'enablescanner', 'showcameraswitcher',
            'completionchecked', 'completionstep', 'requiresequential',
            'timecreated', 'timemodified',
        ]);

        $steps = new backup_nested_element('steps');
        $step = new backup_nested_element('step', ['id'], [
            'name', 'requirementtype', 'requirementcmid', 'requirementstepid',
            'sortorder', 'timecreated', 'timemodified',
        ]);

        // The seat list is structure (always backed up); assignments are user
        // data. Seats must precede seatassignments in document order so the
        // seat id mapping exists before assignments are restored.
        $seats = new backup_nested_element('seats');
        $seat = new backup_nested_element('seat', ['id'], [
            'label', 'sortorder', 'timecreated', 'timemodified',
        ]);

        $marks = new backup_nested_element('marks');
        $mark = new backup_nested_element('mark', ['id'], [
            'stepid', 'userid', 'checkedby', 'method', 'timecreated',
        ]);

        $seatassignments = new backup_nested_element('seatassignments');
        $seatassignment = new backup_nested_element('seatassignment', ['id'], [
            'seatid', 'userid', 'assignedby', 'timecreated',
        ]);

        // Build the tree.
        $examcheck->add_child($steps);
        $steps->add_child($step);
        $examcheck->add_child($seats);
        $seats->add_child($seat);
        $examcheck->add_child($marks);
        $marks->add_child($mark);
        $examcheck->add_child($seatassignments);
        $seatassignments->add_child($seatassignment);

        // Define sources.
        $examcheck->set_source_table('examcheck', ['id' => backup::VAR_ACTIVITYID]);
        $step->set_source_table('examcheck_steps', ['examcheckid' => backup::VAR_PARENTID], 'sortorder ASC, id ASC');
        $seat->set_source_table('examcheck_seats', ['examcheckid' => backup::VAR_PARENTID], 'sortorder ASC, id ASC');
        if ($userinfo) {
            $mark->set_source_table('examcheck_marks', ['examcheckid' => '../../id']);
            $seatassignment->set_source_table('examcheck_seat_users', ['examcheckid' => '../../id']);
        }

        // Define id annotations.
        // requirementcmid references another course module; let the restore framework remap it.
        // requirementstepid references another step of THIS activity, so it is not a
        // course_module and is instead remapped in the restore step's after_execute().
        $step->annotate_ids('course_module', 'requirementcmid');
        $mark->annotate_ids('user', 'userid');
        $mark->annotate_ids('user', 'checkedby');
        $seatassignment->annotate_ids('user', 'userid');
        $seatassignment->annotate_ids('user', 'assignedby');

        // Define file annotations.
        $examcheck->annotate_files('mod_examcheck', 'intro', null);

        return $this->prepare_activity_structure($examcheck);
    }
}
