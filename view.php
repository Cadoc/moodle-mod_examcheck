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

use mod_examcheck\output\dashboard;

$id = required_param('id', PARAM_INT); // Course module id.

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

echo $OUTPUT->header();

if (trim(strip_tags($examcheck->intro ?? '')) !== '') {
    echo $OUTPUT->box(format_module_intro('examcheck', $examcheck, $cm->id), 'generalbox', 'intro');
}

// The roster table enforces the separate-groups restriction itself (see roster::resolve_group),
// and the group selector lives in the datafilter bar, so the page only needs the cmid here.
$dashboard = new dashboard($cm->id);
echo $OUTPUT->render_from_template('mod_examcheck/dashboard', $dashboard->export_for_template($OUTPUT));

echo $OUTPUT->footer();
