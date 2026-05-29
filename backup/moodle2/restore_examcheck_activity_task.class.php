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
 * Restore task for mod_examcheck.
 *
 * @package    mod_examcheck
 * @subpackage backup-moodle2
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/examcheck/backup/moodle2/restore_examcheck_stepslib.php');

/**
 * Restore task that provides the settings and steps to restore the activity.
 */
class restore_examcheck_activity_task extends restore_activity_task {

    /**
     * No particular settings for this activity.
     */
    protected function define_my_settings() {
    }

    /**
     * Define the restore steps.
     */
    protected function define_my_steps() {
        $this->add_step(new restore_examcheck_activity_structure_step('examcheck_structure', 'examcheck.xml'));
    }

    /**
     * Define the contents processed by the link decoder.
     *
     * @return array
     */
    public static function define_decode_contents() {
        return [
            new restore_decode_content('examcheck', ['intro'], 'examcheck'),
        ];
    }

    /**
     * Define the link decoding rules.
     *
     * @return array
     */
    public static function define_decode_rules() {
        return [
            new restore_decode_rule('EXAMCHECKVIEWBYID', '/mod/examcheck/view.php?id=$1', 'course_module'),
            new restore_decode_rule('EXAMCHECKINDEX', '/mod/examcheck/index.php?id=$1', 'course'),
        ];
    }

    /**
     * Define the activity log restore rules.
     *
     * @return array
     */
    public static function define_restore_log_rules() {
        return [
            new restore_log_rule('examcheck', 'add', 'view.php?id={course_module}', '{examcheck}'),
            new restore_log_rule('examcheck', 'update', 'view.php?id={course_module}', '{examcheck}'),
            new restore_log_rule('examcheck', 'view', 'view.php?id={course_module}', '{examcheck}'),
        ];
    }

    /**
     * Define the course-level log restore rules.
     *
     * @return array
     */
    public static function define_restore_log_rules_for_course() {
        return [
            new restore_log_rule('examcheck', 'view all', 'index.php?id={course}', null),
        ];
    }
}
