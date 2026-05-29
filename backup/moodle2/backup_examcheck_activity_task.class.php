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
 * Backup task for mod_examcheck.
 *
 * @package    mod_examcheck
 * @subpackage backup-moodle2
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/examcheck/backup/moodle2/backup_examcheck_stepslib.php');

/**
 * Provides the steps to perform one complete backup of the examcheck instance.
 */
class backup_examcheck_activity_task extends backup_activity_task {

    /**
     * No specific settings for this activity.
     */
    protected function define_my_settings() {
    }

    /**
     * Define the backup step that stores the instance data in examcheck.xml.
     */
    protected function define_my_steps() {
        $this->add_step(new backup_examcheck_activity_structure_step('examcheck_structure', 'examcheck.xml'));
    }

    /**
     * Encode URLs to the index.php and view.php scripts.
     *
     * @param string $content HTML text that may contain activity URLs.
     * @return string The content with URLs encoded.
     */
    public static function encode_content_links($content) {
        global $CFG;

        $base = preg_quote($CFG->wwwroot, '/');

        $search = "/(" . $base . "\/mod\/examcheck\/index.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@EXAMCHECKINDEX*$2@$', $content);

        $search = "/(" . $base . "\/mod\/examcheck\/view.php\?id\=)([0-9]+)/";
        $content = preg_replace($search, '$@EXAMCHECKVIEWBYID*$2@$', $content);

        return $content;
    }
}
