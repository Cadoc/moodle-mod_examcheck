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
 * Export the roster check status for an examcheck activity.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_examcheck\local\checker;
use mod_examcheck\local\steps;

$id = required_param('id', PARAM_INT);              // Course module id.
$groupid = optional_param('group', 0, PARAM_INT);
$dataformat = required_param('dataformat', PARAM_ALPHA);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'examcheck');
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/examcheck:view', $context);

$examcheck = $DB->get_record('examcheck', ['id' => $cm->instance], '*', MUST_EXIST);
$checker = new checker($examcheck, $context);

$steplist = array_values(steps::get_steps($examcheck->id));
$roster = $checker->get_roster($groupid);
$marks = $checker->get_marks();

// Column headers: identity, then three columns per step.
$columns = [
    get_string('lastname'),
    get_string('firstname'),
    get_string('idnumber'),
];
foreach ($steplist as $step) {
    $name = format_string($step->name);
    $columns[] = get_string('col_checked', 'mod_examcheck', $name);
    $columns[] = get_string('col_checkedby', 'mod_examcheck', $name);
    $columns[] = get_string('col_checkedat', 'mod_examcheck', $name);
}

// One row per student.
$rows = [];
foreach ($roster as $user) {
    $row = [$user->lastname, $user->firstname, (string) $user->idnumber];
    foreach ($steplist as $step) {
        $mark = $marks[$step->id][$user->id] ?? null;
        $row[] = $mark ? get_string('yes') : get_string('no');
        $row[] = $mark ? checker::user_label((int) $mark->checkedby) : '';
        $row[] = $mark ? userdate((int) $mark->timecreated, get_string('strftimedatetimeshort', 'langconfig')) : '';
    }
    $rows[] = $row;
}

$filename = clean_filename(get_string('exportfilename', 'mod_examcheck') . '_' . format_string($examcheck->name));

\core\dataformat::download_data($filename, $dataformat, $columns, $rows);
