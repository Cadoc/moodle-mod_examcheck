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
        // Marks are user-specific data.
        $userinfo = $this->get_setting_value('userinfo');

        $examcheck = new backup_nested_element('examcheck', ['id'], [
            'name', 'intro', 'introformat', 'scanfield', 'requireconfirm',
            'completionchecked', 'completionstep', 'timecreated', 'timemodified',
        ]);

        $steps = new backup_nested_element('steps');
        $step = new backup_nested_element('step', ['id'], [
            'name', 'sortorder', 'timecreated', 'timemodified',
        ]);

        $marks = new backup_nested_element('marks');
        $mark = new backup_nested_element('mark', ['id'], [
            'stepid', 'userid', 'checkedby', 'method', 'timecreated',
        ]);

        // Build the tree.
        $examcheck->add_child($steps);
        $steps->add_child($step);
        $examcheck->add_child($marks);
        $marks->add_child($mark);

        // Define sources.
        $examcheck->set_source_table('examcheck', ['id' => backup::VAR_ACTIVITYID]);
        $step->set_source_table('examcheck_steps', ['examcheckid' => backup::VAR_PARENTID], 'sortorder ASC, id ASC');
        if ($userinfo) {
            $mark->set_source_table('examcheck_marks', ['examcheckid' => '../../id']);
        }

        // Define id annotations.
        $mark->annotate_ids('user', 'userid');
        $mark->annotate_ids('user', 'checkedby');

        // Define file annotations.
        $examcheck->annotate_files('mod_examcheck', 'intro', null);

        return $this->prepare_activity_structure($examcheck);
    }
}
