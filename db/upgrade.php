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
 * Upgrade steps for mod_examcheck.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Execute the mod_examcheck upgrade from the given old version.
 *
 * @param int $oldversion The currently installed version of the plugin.
 * @return bool Always true.
 */
function xmldb_examcheck_upgrade($oldversion) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2026052901) {
        // Drop the meaningless empty-string DEFAULT on the scanregex column.
        // The column stays NOT NULL; an empty value continues to mean "use the
        // scanned value as-is". This matches the XMLDB auto-fix and silences the
        // "CHAR NOT NULL column with '' as DEFAULT" debug warning.
        $table = new xmldb_table('examcheck');
        $field = new xmldb_field('scanregex', XMLDB_TYPE_CHAR, '255', null, XMLDB_NOTNULL, null, null, 'scanfield');
        $dbman->change_field_default($table, $field);

        upgrade_mod_savepoint(true, 2026052901, 'examcheck');
    }

    if ($oldversion < 2026052906) {
        // The scan extraction pattern is now a single site-wide admin setting
        // (mod_examcheck/defaultscanregex), so the per-activity column is removed.
        $table = new xmldb_table('examcheck');
        $field = new xmldb_field('scanregex');
        if ($dbman->field_exists($table, $field)) {
            $dbman->drop_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026052906, 'examcheck');
    }

    if ($oldversion < 2026053003) {
        // Per-activity scanner toggles. Existing activities keep the scanner (and switcher) on.
        $table = new xmldb_table('examcheck');
        $enable = new xmldb_field('enablescanner', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'requireconfirm');
        if (!$dbman->field_exists($table, $enable)) {
            $dbman->add_field($table, $enable);
        }
        $switcher = new xmldb_field('showcameraswitcher', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '1', 'enablescanner');
        if (!$dbman->field_exists($table, $switcher)) {
            $dbman->add_field($table, $switcher);
        }

        upgrade_mod_savepoint(true, 2026053003, 'examcheck');
    }

    return true;
}
