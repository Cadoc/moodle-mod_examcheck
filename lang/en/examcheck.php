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
 * English strings for mod_examcheck.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

// Plugin.
$string['pluginname'] = 'Exam check';
$string['modulename'] = 'Exam check';
$string['modulenameplural'] = 'Exam checks';
$string['modulename_help'] = 'The Exam check activity lets teachers run attendance and verification checks on exam day. Pick a group, then tick students off through one or more check steps (attendance, identity verification, exam copy submitted, and any custom step you add). Several teachers can check the same roster at the same time from their own phones, and each student can only be marked once per step. A camera page lets you scan a QR code or barcode printed on a student card to mark students hands-free.';
$string['pluginadministration'] = 'Exam check administration';
$string['examcheck:addinstance'] = 'Add a new exam check activity';
$string['examcheck:view'] = 'View the exam check dashboard';
$string['examcheck:check'] = 'Record and remove checks against students';
$string['examcheck:override'] = 'Override or remove a mark recorded by another teacher';
$string['examcheck:managesteps'] = 'Manage the check steps of an exam check activity';

// Activity / form.
$string['scanningsettings'] = 'Scanning defaults';
$string['scanfield'] = 'Scan match field';
$string['scanfield_help'] = 'The user field whose value is encoded in the QR code or barcode on the student card. When a teacher scans a code, the value is matched against this field to find the student. Teachers can change the field for their own scanning session on the scanner page.';
$string['requireconfirm'] = 'Confirm before marking';
$string['requireconfirm_help'] = 'When enabled, the scanner shows the matched student\'s name and waits for the teacher to press a button before recording the check. When disabled, a scan marks the student immediately and returns to scanning the next student. Teachers can override this for their own session.';

// Admin settings.
$string['defaultscanfield'] = 'Default scan match field';
$string['defaultscanfield_desc'] = 'The scan match field selected by default when a teacher creates a new exam check activity.';
$string['defaultrequireconfirm'] = 'Confirm before marking by default';
$string['defaultrequireconfirm_desc'] = 'Whether new exam check activities require the teacher to confirm a scanned student before marking them.';
$string['pollinterval'] = 'Live refresh interval';
$string['pollinterval_desc'] = 'How often, in seconds, the dashboard checks for marks recorded by other teachers so that everyone sees an up-to-date roster. Set to 0 to disable live refresh.';

// Scan field labels.
$string['field_idnumber'] = 'ID number';
$string['field_userid'] = 'User id (internal)';
$string['field_profile'] = 'Profile field: {$a}';

// Dashboard / roster.
$string['dashboard'] = 'Checking dashboard';
$string['roster'] = 'Roster';
$string['nostudents'] = 'There are no students to check in the selected group.';
$string['nostudentsatall'] = 'No students are enrolled on this course yet.';
$string['student'] = 'Student';
$string['allparticipants'] = 'All participants';
$string['checked'] = 'Checked';
$string['notchecked'] = 'Not checked';
$string['checkedbyon'] = 'Checked by {$a->user}, {$a->time} ({$a->ago})';
$string['markaction'] = 'Mark "{$a}"';
$string['unmarkaction'] = 'Undo "{$a}"';
$string['progresscount'] = '{$a->done} of {$a->total} checked';
$string['searchstudents'] = 'Search by name or ID number';
$string['showonlyunchecked'] = 'Show only not-yet-checked';
$string['nomatchingstudents'] = 'No students match your search.';
$string['visiblecount'] = '{$a->visible} of {$a->total} shown';
$string['opascanner'] = 'Open scanner';
$string['managestepslink'] = 'Manage steps';
$string['backtodashboard'] = 'Back to dashboard';

// Steps management.
$string['steps'] = 'Check steps';
$string['managesteps'] = 'Manage check steps';
$string['stepname'] = 'Step name';
$string['addstep'] = 'Add step';
$string['editstep'] = 'Edit step';
$string['deletestep'] = 'Delete step';
$string['moveup'] = 'Move up';
$string['movedown'] = 'Move down';
$string['confirmdeletestep'] = 'Delete the step "{$a}"? All checks recorded for this step will be permanently removed.';
$string['cannotdeletelaststep'] = 'You cannot delete the last remaining step. An exam check activity must have at least one step.';
$string['stepadded'] = 'Step added.';
$string['stepupdated'] = 'Step updated.';
$string['stepdeleted'] = 'Step deleted.';
$string['defaultstepname'] = 'Attendance';
$string['marksrecorded'] = 'Checks recorded';
$string['clearchecks'] = 'Clear checks';
$string['resetallchecks'] = 'Clear all checks';
$string['checkscleared'] = 'Checks cleared.';
$string['confirmclearstep'] = 'Remove all recorded checks for the step "{$a}"? The step itself is kept.';
$string['confirmresetall'] = 'Remove every recorded check for all steps in this activity? The steps themselves are kept.';
$string['nostepsyet'] = 'No steps yet.';
$string['addeditstep'] = 'Add or edit a step';
$string['resetmarks'] = 'Delete all recorded checks';

// Scanner page.
$string['scanner'] = 'Scanner';
$string['scannerfor'] = 'Scanner: {$a}';
$string['scanstep'] = 'Step to mark';
$string['startcamera'] = 'Start camera';
$string['stopcamera'] = 'Stop camera';
$string['scanning'] = 'Point the camera at a QR code or barcode…';
$string['scanmanualentry'] = 'Or type / scan the value manually';
$string['scanmanualentryplaceholder'] = 'Enter or scan a code value';
$string['scansubmit'] = 'Look up';
$string['confirmmark'] = 'Confirm and mark';
$string['scannext'] = 'Scan next student';
$string['camerablocked'] = 'The camera could not be started. Check that you granted camera permission and that the page is served over HTTPS, or use manual entry below.';
$string['cameraunsupported'] = 'Live camera scanning is not supported by this browser. You can still type or scan values into the manual entry box below (for example with a USB or Bluetooth barcode scanner).';
$string['sessionsettings'] = 'Scanning session settings';

// Scan / mark results (also reused by AJAX).
$string['result_marked'] = '{$a} marked as checked.';
$string['result_needsconfirm'] = 'Found {$a}. Confirm to mark them as checked.';
$string['result_notfound'] = 'No student in this roster matches the scanned value "{$a}".';
$string['result_notinroster'] = '{$a} is not in the selected group for this activity.';
$string['result_conflict'] = 'Already checked: {$a->user} was marked by {$a->by}, {$a->ago}.';
$string['result_unmarked'] = 'Check removed for {$a}.';
$string['result_notchecked'] = '{$a} was not checked.';
$string['error_alreadychecked'] = 'This student has already been checked for this step.';
$string['error_overridedenied'] = 'This mark was recorded by another teacher and you do not have permission to change it.';
$string['error_nosteps'] = 'This activity has no check steps yet. Add a step first.';
$string['justnow'] = 'just now';
$string['agoseconds'] = '{$a} seconds ago';
$string['ago'] = '{$a} ago';

// Completion.
$string['completionchecked'] = 'Student must be checked to complete the activity';
$string['completionchecked_desc'] = 'Checked as required';
$string['completionallsteps'] = 'All steps';
$string['completionstepsel'] = 'Step required for completion';
$string['completionstepsel_help'] = 'Choose what completes the activity for a student. Select "All steps" to require the student to be checked on every step, or pick a single step (for example "Attendance") to complete the activity as soon as the student is checked on just that step.

Because completion is recorded per student, you can use it as a prerequisite elsewhere — for example, requiring this activity to be complete before a quiz becomes available.';
$string['completiondetail:checked'] = 'Be checked as required';

// Events.
$string['eventusermarked'] = 'Student marked as checked';
$string['eventuserunmarked'] = 'Student check removed';

// Privacy.
$string['privacy:metadata:examcheck_marks'] = 'Records of which students were checked at which step, including who recorded each check.';
$string['privacy:metadata:examcheck_marks:userid'] = 'The student who was checked.';
$string['privacy:metadata:examcheck_marks:checkedby'] = 'The teacher who recorded the check.';
$string['privacy:metadata:examcheck_marks:stepid'] = 'The check step the mark belongs to.';
$string['privacy:metadata:examcheck_marks:method'] = 'How the check was recorded (manually, from the list, or by scanning).';
$string['privacy:metadata:examcheck_marks:timecreated'] = 'The time the check was recorded.';
$string['privacy:checkedbyme'] = 'Checks you recorded as a teacher';
$string['privacy:checkedstudent'] = 'Checks recorded about you as a student';
