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
 * Manage the check steps of an examcheck activity (add / rename / reorder /
 * delete) and clear recorded checks.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use core\output\notification;
use mod_examcheck\form\step_form;
use mod_examcheck\local\steps;

$id = required_param('id', PARAM_INT);          // Course module id.
$action = optional_param('action', '', PARAM_ALPHA);
$stepid = optional_param('stepid', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'examcheck');
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/examcheck:managesteps', $context);

$examcheck = $DB->get_record('examcheck', ['id' => $cm->instance], '*', MUST_EXIST);
$baseurl = new moodle_url('/mod/examcheck/manage.php', ['id' => $cm->id]);

$PAGE->set_url($baseurl);
$PAGE->set_title(get_string('managesteps', 'mod_examcheck'));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Reorder actions (immediate, require sesskey).
if (($action === 'moveup' || $action === 'movedown') && $stepid) {
    require_sesskey();
    steps::move_step($stepid, $action === 'moveup' ? -1 : 1);
    redirect($baseurl);
}

// Delete a step (confirmation required).
if ($action === 'delete' && $stepid) {
    $step = $DB->get_record('examcheck_steps', ['id' => $stepid, 'examcheckid' => $examcheck->id], '*', MUST_EXIST);
    if ($confirm) {
        require_sesskey();
        steps::delete_step($stepid);
        redirect($baseurl, get_string('stepdeleted', 'mod_examcheck'), null, notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmdeletestep', 'mod_examcheck', format_string($step->name)),
        new moodle_url($baseurl, ['action' => 'delete', 'stepid' => $stepid, 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

// Clear all recorded checks for one step (confirmation required).
if ($action === 'clear' && $stepid) {
    $step = $DB->get_record('examcheck_steps', ['id' => $stepid, 'examcheckid' => $examcheck->id], '*', MUST_EXIST);
    if ($confirm) {
        require_sesskey();
        $DB->delete_records('examcheck_marks', ['stepid' => $stepid]);
        redirect($baseurl, get_string('checkscleared', 'mod_examcheck'), null, notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmclearstep', 'mod_examcheck', format_string($step->name)),
        new moodle_url($baseurl, ['action' => 'clear', 'stepid' => $stepid, 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

// Clear every recorded check for the whole activity (confirmation required).
if ($action === 'resetall') {
    if ($confirm) {
        require_sesskey();
        $DB->delete_records('examcheck_marks', ['examcheckid' => $examcheck->id]);
        redirect($baseurl, get_string('checkscleared', 'mod_examcheck'), null, notification::NOTIFY_SUCCESS);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->confirm(
        get_string('confirmresetall', 'mod_examcheck'),
        new moodle_url($baseurl, ['action' => 'resetall', 'confirm' => 1, 'sesskey' => sesskey()]),
        $baseurl
    );
    echo $OUTPUT->footer();
    exit;
}

// Add / rename form.
$mform = new step_form($baseurl);
if ($mform->is_cancelled()) {
    redirect($baseurl);
} else if ($data = $mform->get_data()) {
    if (!empty($data->stepid)) {
        // Confirm the step belongs to this instance before renaming.
        $DB->get_record('examcheck_steps', ['id' => $data->stepid, 'examcheckid' => $examcheck->id], 'id', MUST_EXIST);
        steps::rename_step((int) $data->stepid, $data->name);
        redirect($baseurl, get_string('stepupdated', 'mod_examcheck'), null, notification::NOTIFY_SUCCESS);
    } else {
        steps::add_step($examcheck->id, $data->name);
        redirect($baseurl, get_string('stepadded', 'mod_examcheck'), null, notification::NOTIFY_SUCCESS);
    }
}

// Pre-fill the form when editing an existing step.
$editing = null;
if ($action === 'edit' && $stepid) {
    $editing = $DB->get_record('examcheck_steps', ['id' => $stepid, 'examcheckid' => $examcheck->id], '*', MUST_EXIST);
    $mform->set_data(['id' => $cm->id, 'stepid' => $editing->id, 'action' => 'edit', 'name' => $editing->name]);
} else {
    $mform->set_data(['id' => $cm->id, 'action' => 'add']);
}

// Build the steps list with per-step mark counts and action URLs.
$steprecords = array_values(steps::get_steps($examcheck->id));
$count = count($steprecords);
$rows = [];
foreach ($steprecords as $index => $step) {
    $rows[] = [
        'name'      => format_string($step->name),
        'marks'     => $DB->count_records('examcheck_marks', ['stepid' => $step->id]),
        'isfirst'   => $index === 0,
        'islast'    => $index === $count - 1,
        'editurl'   => (new moodle_url($baseurl, ['action' => 'edit', 'stepid' => $step->id]))->out(false),
        'upurl'     => (new moodle_url(
            $baseurl,
            ['action' => 'moveup', 'stepid' => $step->id, 'sesskey' => sesskey()]
        ))->out(false),
        'downurl'   => (new moodle_url(
            $baseurl,
            ['action' => 'movedown', 'stepid' => $step->id, 'sesskey' => sesskey()]
        ))->out(false),
        'clearurl'  => (new moodle_url($baseurl, ['action' => 'clear', 'stepid' => $step->id]))->out(false),
        'deleteurl' => (new moodle_url($baseurl, ['action' => 'delete', 'stepid' => $step->id]))->out(false),
        'candelete' => $count > 1,
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('managesteps', 'mod_examcheck'));

echo $OUTPUT->render_from_template('mod_examcheck/manage', [
    'steps'        => $rows,
    'dashboardurl' => (new moodle_url('/mod/examcheck/view.php', ['id' => $cm->id]))->out(false),
    'resetallurl'  => (new moodle_url($baseurl, ['action' => 'resetall']))->out(false),
    'hasmarks'     => $DB->record_exists('examcheck_marks', ['examcheckid' => $examcheck->id]),
]);

$mform->display();

echo $OUTPUT->footer();
