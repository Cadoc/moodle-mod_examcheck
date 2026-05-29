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
 * Checking dashboard for an examcheck activity.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_examcheck\local\checker;
use mod_examcheck\output\dashboard;

$id = required_param('id', PARAM_INT); // Course module id.
$groupid = optional_param('group', null, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'examcheck');
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/examcheck:view', $context);

$examcheck = $DB->get_record('examcheck', ['id' => $cm->instance], '*', MUST_EXIST);

// Trigger the viewed event and update completion state.
$event = \mod_examcheck\event\course_module_viewed::create([
    'objectid' => $examcheck->id,
    'context'  => $context,
]);
$event->add_record_snapshot('course_modules', $cm);
$event->add_record_snapshot('course', $course);
$event->add_record_snapshot('examcheck', $examcheck);
$event->trigger();

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$PAGE->set_url('/mod/examcheck/view.php', ['id' => $cm->id]);
$PAGE->set_title(format_string($examcheck->name));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
$PAGE->activityheader->set_attrs(['hidecompletion' => false]);

// Resolve the active group using the activity's group mode.
$groupmode = groups_get_activity_groupmode($cm);
$groupmenu = '';
if ($groupmode != NOGROUPS) {
    $url = new moodle_url('/mod/examcheck/view.php', ['id' => $cm->id]);
    $groupmenu = groups_print_activity_menu($cm, $url, true);
}
$activegroup = (int) (groups_get_activity_group($cm, true) ?: 0);

// Under separate groups, an invigilator without "access all groups" must only
// ever see the students of their own group(s) - never the whole course. This
// keeps rooms separated: each invigilator sees only their room's roster.
if ($groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $context)) {
    $allowedgroups = groups_get_activity_allowed_groups($cm);
    if (empty($allowedgroups)) {
        // Restricted user who belongs to no group: show no students at all.
        $activegroup = -1;
    } else if ($activegroup <= 0 || !isset($allowedgroups[$activegroup])) {
        // Never fall back to "all participants": default to one of their groups.
        $activegroup = (int) array_key_first($allowedgroups);
    }
}

$checker = new checker($examcheck, $context);
$canmanage = has_capability('mod/examcheck:managesteps', $context);

echo $OUTPUT->header();

if (trim(strip_tags($examcheck->intro ?? '')) !== '') {
    echo $OUTPUT->box(format_module_intro('examcheck', $examcheck, $cm->id), 'generalbox', 'intro');
}

$dashboard = new dashboard($checker, $cm->id, (int) $activegroup, $groupmenu, $canmanage);
echo $OUTPUT->render_from_template('mod_examcheck/dashboard', $dashboard->export_for_template($OUTPUT));

echo $OUTPUT->footer();
