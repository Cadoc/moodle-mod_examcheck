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
 * List all examcheck activities in a course.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

$courseid = required_param('id', PARAM_INT);

$course = get_course($courseid);
require_login($course);

$context = context_course::instance($course->id);

$event = \core\event\course_module_instance_list_viewed::create(['context' => $context]);
$event->add_record_snapshot('course', $course);
$event->trigger();

$PAGE->set_url('/mod/examcheck/index.php', ['id' => $course->id]);
$PAGE->set_title(format_string($course->fullname));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('modulenameplural', 'mod_examcheck'));

$instances = get_all_instances_in_course('examcheck', $course);
if (empty($instances)) {
    notice(get_string('noinstances', 'mod_examcheck'), new moodle_url('/course/view.php', ['id' => $course->id]));
}

$table = new html_table();
$table->head = [get_string('name'), get_string('sectionname', 'format_' . $course->format)];
$table->align = ['left', 'left'];

foreach ($instances as $instance) {
    $url = new moodle_url('/mod/examcheck/view.php', ['id' => $instance->coursemodule]);
    $name = format_string($instance->name);
    if (!$instance->visible) {
        $name = html_writer::span($name, 'dimmed');
    }
    $table->data[] = [
        html_writer::link($url, $name),
        get_section_name($course, $instance->section),
    ];
}

echo html_writer::table($table);
echo $OUTPUT->footer();
