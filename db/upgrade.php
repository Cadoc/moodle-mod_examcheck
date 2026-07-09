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

    if ($oldversion < 2026060301) {
        // Per-step "requires a submitted quiz attempt" gate, plus the chosen quiz cmid.
        $table = new xmldb_table('examcheck_steps');
        $require = new xmldb_field('requirequizattempt', XMLDB_TYPE_INTEGER, '2', null, XMLDB_NOTNULL, null, '0', 'name');
        if (!$dbman->field_exists($table, $require)) {
            $dbman->add_field($table, $require);
        }
        $quizcmid = new xmldb_field('quizcmid', XMLDB_TYPE_INTEGER, '10', null, null, null, null, 'requirequizattempt');
        if (!$dbman->field_exists($table, $quizcmid)) {
            $dbman->add_field($table, $quizcmid);
        }

        upgrade_mod_savepoint(true, 2026060301, 'examcheck');
    }

    if ($oldversion < 2026060306) {
        // Align the intro column with the standard Moodle module schema (NOT NULL).
        // Backfill any legacy NULL intros to '' first, since the conversion fails otherwise.
        $DB->execute("UPDATE {examcheck} SET intro = '' WHERE intro IS NULL");

        $table = new xmldb_table('examcheck');
        $field = new xmldb_field('intro', XMLDB_TYPE_TEXT, null, null, XMLDB_NOTNULL, null, null, 'name');
        $dbman->change_field_notnull($table, $field);

        upgrade_mod_savepoint(true, 2026060306, 'examcheck');
    }

    if ($oldversion < 2026070101) {
        // Generalise the per-step quiz-attempt gate into a "requirements for checking"
        // type, so a step can instead require completion of any other course activity.
        $table = new xmldb_table('examcheck_steps');

        $requirementtype = new xmldb_field(
            'requirementtype',
            XMLDB_TYPE_CHAR,
            '20',
            null,
            XMLDB_NOTNULL,
            null,
            'none',
            'name'
        );
        if (!$dbman->field_exists($table, $requirementtype)) {
            $dbman->add_field($table, $requirementtype);
        }

        // Migrate the old boolean gate into the new type before dropping it.
        $requirequizattempt = new xmldb_field('requirequizattempt');
        if ($dbman->field_exists($table, $requirequizattempt)) {
            $DB->execute("UPDATE {examcheck_steps} SET requirementtype = 'quiz' WHERE requirequizattempt = 1");
            $dbman->drop_field($table, $requirequizattempt);
        }

        // Rename quizcmid to the generic requirementcmid, shared by both the quiz and
        // activity-completion requirement types (only one is ever active per step).
        // rename_field() requires the field's full original spec (unlike field_exists()
        // / drop_field(), which only need the name), or it throws "must contain full
        // specs" — this must match quizcmid's old definition exactly.
        $quizcmid = new xmldb_field(
            'quizcmid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'requirementtype'
        );
        if ($dbman->field_exists($table, $quizcmid)) {
            $dbman->rename_field($table, $quizcmid, 'requirementcmid');
        }

        upgrade_mod_savepoint(true, 2026070101, 'examcheck');
    }

    if ($oldversion < 2026070102) {
        // Activity-wide "require step-by-step completion" setting: when on, a step
        // can only be checked once the immediately preceding step is checked too.
        $table = new xmldb_table('examcheck');
        $field = new xmldb_field(
            'requiresequential',
            XMLDB_TYPE_INTEGER,
            '2',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'completionstep'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070102, 'examcheck');
    }

    if ($oldversion < 2026070900) {
        // Per-step "another specific step must be checked first" requirement. Stored in
        // its own column because it references an examcheck_steps.id, not a course
        // module id (unlike requirementcmid, which the backup framework remaps as a
        // course_module).
        $table = new xmldb_table('examcheck_steps');
        $field = new xmldb_field(
            'requirementstepid',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            null,
            null,
            null,
            'requirementcmid'
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_mod_savepoint(true, 2026070900, 'examcheck');
    }

    return true;
}
