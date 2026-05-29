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
 * Camera / barcode scanner page for an examcheck activity.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use mod_examcheck\local\scanfield;
use mod_examcheck\local\steps;

$id = required_param('id', PARAM_INT); // Course module id.
$groupid = optional_param('group', 0, PARAM_INT);
$preferredstep = optional_param('step', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($id, 'examcheck');
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/examcheck:check', $context);

$examcheck = $DB->get_record('examcheck', ['id' => $cm->instance], '*', MUST_EXIST);
(new \mod_examcheck\local\checker($examcheck, $context))->require_group_access($groupid);

$dashboardurl = new moodle_url('/mod/examcheck/view.php', ['id' => $cm->id]);

$steps = array_values(steps::get_steps($examcheck->id));
if (empty($steps)) {
    redirect($dashboardurl, get_string('error_nosteps', 'mod_examcheck'), null, \core\output\notification::NOTIFY_ERROR);
}

$PAGE->set_url('/mod/examcheck/scan.php', ['id' => $cm->id, 'group' => $groupid]);
$PAGE->set_title(get_string('scannerfor', 'mod_examcheck', format_string($examcheck->name)));
$PAGE->set_heading(format_string($course->fullname));
$PAGE->set_context($context);

// Load the ZXing decoder as a plain script (exposes window.ZXing) so the scanner can decode
// on browsers without the native BarcodeDetector API (Safari, Firefox, many mobiles).
$PAGE->requires->js(new moodle_url('/mod/examcheck/thirdpartylibs/zxing.min.js'));

// Build the step options.
$stepoptions = [];
foreach ($steps as $index => $step) {
    $selected = $preferredstep ? ((int) $step->id === $preferredstep) : ($index === 0);
    $stepoptions[] = [
        'value'    => (int) $step->id,
        'name'     => format_string($step->name),
        'selected' => $selected,
    ];
}

// Build the scan field options, defaulting to the instance's field.
$fieldoptions = [];
foreach (scanfield::get_field_menu() as $key => $label) {
    $fieldoptions[] = [
        'value'    => $key,
        'name'     => $label,
        'selected' => ($key === $examcheck->scanfield),
    ];
}

$templatecontext = [
    'cmid'           => $cm->id,
    'groupid'        => $groupid,
    'name'           => format_string($examcheck->name),
    'steps'          => $stepoptions,
    'fields'         => $fieldoptions,
    'requireconfirm' => (bool) $examcheck->requireconfirm,
    'dashboardurl'   => $dashboardurl->out(false),
];

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_examcheck/scanner', $templatecontext);
echo $OUTPUT->footer();
