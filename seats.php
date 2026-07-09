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
 * Seats tab of an examcheck activity: seat assignment (default), edit the seat
 * list, and CSV import/export of the assignments.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use core\output\notification;
use mod_examcheck\form\seats_edit_form;
use mod_examcheck\local\seats;
use mod_examcheck\output\seats_action_bar;

$id = required_param('id', PARAM_INT);                       // Course module id.
$action = optional_param('action', 'assign', PARAM_ALPHA);   // Seats subpage.

[$course, $cm] = get_course_and_cm_from_cmid($id, 'examcheck');
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/examcheck:manageseats', $context);

$examcheck = $DB->get_record('examcheck', ['id' => $cm->instance], '*', MUST_EXIST);

if (!in_array($action, ['assign', 'edit', 'import', 'export'], true)) {
    $action = 'assign';
}

$baseurl = new moodle_url('/mod/examcheck/seats.php', ['id' => $cm->id, 'action' => $action]);
$assignurl = new moodle_url('/mod/examcheck/seats.php', ['id' => $cm->id, 'action' => 'assign']);

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('seats', 'mod_examcheck'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);
// Keep the Seats tab highlighted on every subpage.
$PAGE->set_secondary_active_tab('mod_examcheck_seats');

$actionbar = new seats_action_bar((int) $cm->id, $action);
$actionbarhtml = $OUTPUT->render_from_template(
    'mod_examcheck/seats_action_bar',
    $actionbar->export_for_template($OUTPUT)
);

if ($action === 'edit') {
    // Edit the seat list: one label per line, with an explicit acknowledgement
    // when saving a changed list would reset existing assignments.
    $currentlabels = array_map(fn($seat) => $seat->label, array_values(seats::get_seats((int) $examcheck->id)));
    $assignmentcount = seats::count_assignments((int) $examcheck->id);

    $mform = new seats_edit_form($baseurl, [
        'currentlabels'   => $currentlabels,
        'hasassignments'  => $assignmentcount > 0,
        'assignmentcount' => $assignmentcount,
    ]);

    if ($mform->is_cancelled()) {
        redirect($assignurl);
    } else if ($data = $mform->get_data()) {
        $labels = seats::clean_labels(seats_edit_form::split_lines($data->seatlist));
        if ($labels === $currentlabels) {
            redirect($baseurl, get_string('seatsnochange', 'mod_examcheck'), null, notification::NOTIFY_INFO);
        }
        seats::replace_list((int) $examcheck->id, $labels);
        redirect($baseurl, get_string('seatssaved', 'mod_examcheck'), null, notification::NOTIFY_SUCCESS);
    }

    $mform->set_data([
        'id'       => $cm->id,
        'action'   => 'edit',
        'seatlist' => implode("\n", $currentlabels),
    ]);

    echo $OUTPUT->header();
    echo $actionbarhtml;
    echo $OUTPUT->heading(get_string('editseats', 'mod_examcheck'));
    $mform->display();
    echo $OUTPUT->footer();
    exit;
}

if ($action === 'import' || $action === 'export') {
    // Import and export land in a later increment of this feature.
    echo $OUTPUT->header();
    echo $actionbarhtml;
    echo $OUTPUT->heading(get_string(
        $action === 'import' ? 'importseats' : 'exportseats',
        'mod_examcheck'
    ));
    echo $OUTPUT->footer();
    exit;
}

// Default: the seat assignment page.
echo $OUTPUT->header();
echo $actionbarhtml;
echo $OUTPUT->heading(get_string('seatassignment', 'mod_examcheck'));

if (!seats::count_seats((int) $examcheck->id)) {
    $editurl = new moodle_url('/mod/examcheck/seats.php', ['id' => $cm->id, 'action' => 'edit']);
    echo $OUTPUT->notification(get_string('noseatsyet', 'mod_examcheck'), notification::NOTIFY_INFO);
    echo $OUTPUT->single_button($editurl, get_string('editseats', 'mod_examcheck'), 'get');
}

echo $OUTPUT->footer();
