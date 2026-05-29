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

$string['addeditstep'] = 'Add or edit a step';
$string['addstep'] = 'Add step';
$string['ago'] = '{$a} ago';
$string['agoseconds'] = '{$a} seconds ago';
$string['allparticipants'] = 'All participants';
$string['backtodashboard'] = 'Back to dashboard';
$string['camerablocked'] = 'The camera could not be started. Check that you granted camera permission and that the page is served over HTTPS, or use manual entry below.';
$string['cameraunsupported'] = 'Live camera scanning is not supported by this browser. You can still type or scan values into the manual entry box below (for example with a USB or Bluetooth barcode scanner).';
$string['cannotdeletelaststep'] = 'You cannot delete the last remaining step. An exam check activity must have at least one step.';
$string['checked'] = 'Checked';
$string['checkedbyon'] = 'Checked by {$a->user} ({$a->ago})';
$string['checkscleared'] = 'Checks cleared.';
$string['clearchecks'] = 'Clear checks';
$string['col_checked'] = '{$a}: checked';
$string['col_checkedat'] = '{$a}: checked at';
$string['col_checkedby'] = '{$a}: checked by';
$string['completionallsteps'] = 'All steps';
$string['completionchecked'] = 'Student must be checked to complete the activity';
$string['completionchecked_desc'] = 'Checked as required';
$string['completiondetail:checked'] = 'Be checked as required';
$string['completionstepsel'] = 'Step required for completion';
$string['completionstepsel_help'] = 'Choose what completes the activity for a student. Select "All steps" to require the student to be checked on every step, or pick a single step (for example "Attendance") to complete the activity as soon as the student is checked on just that step.

Because completion is recorded per student, you can use it as a prerequisite elsewhere — for example, requiring this activity to be complete before a quiz becomes available.';
$string['confirmclearstep'] = 'Remove all recorded checks for the step "{$a}"? The step itself is kept.';
$string['confirmdeletestep'] = 'Delete the step "{$a}"? All checks recorded for this step will be permanently removed.';
$string['confirmmark'] = 'Confirm and mark';
$string['confirmresetall'] = 'Remove every recorded check for all steps in this activity? The steps themselves are kept.';
$string['dashboard'] = 'Checking dashboard';
$string['defaultrequireconfirm'] = 'Confirm before marking by default';
$string['defaultrequireconfirm_desc'] = 'Whether new exam check activities require the teacher to confirm a scanned student before marking them.';
$string['defaultscanfield'] = 'Default scan match field';
$string['defaultscanfield_desc'] = 'The scan match field selected by default when a teacher creates a new exam check activity.';
$string['defaultscanregex'] = 'Default scan extraction pattern';
$string['defaultscanregex_desc'] = 'The extraction regular expression selected by default when a teacher creates a new exam check activity. Leave empty for no extraction.';
$string['defaultstepname'] = 'Attendance';
$string['deletestep'] = 'Delete step';
$string['editstep'] = 'Edit step';
$string['error_alreadychecked'] = 'This student has already been checked for this step.';
$string['error_invalidregex'] = 'This is not a valid regular expression.';
$string['error_nosteps'] = 'This activity has no check steps yet. Add a step first.';
$string['error_overridedenied'] = 'This mark was recorded by another teacher and you do not have permission to change it.';
$string['eventusermarked'] = 'Student marked as checked';
$string['eventuserunmarked'] = 'Student check removed';
$string['examcheck:addinstance'] = 'Add a new exam check activity';
$string['examcheck:check'] = 'Record and remove checks against students';
$string['examcheck:managesteps'] = 'Manage the check steps of an exam check activity';
$string['examcheck:override'] = 'Override or remove a mark recorded by another teacher';
$string['examcheck:view'] = 'View the exam check dashboard';
$string['export'] = 'Export';
$string['exportfilename'] = 'examcheck';
$string['field_idnumber'] = 'ID number';
$string['field_profile'] = 'Profile field: {$a}';
$string['field_userid'] = 'User id (internal)';
$string['justnow'] = 'just now';
$string['managesteps'] = 'Manage check steps';
$string['managestepslink'] = 'Manage steps';
$string['markaction'] = 'Mark "{$a}"';
$string['marksrecorded'] = 'Checks recorded';
$string['modulename'] = 'Exam check';
$string['modulename_help'] = 'The Exam check activity lets teachers run attendance and verification checks on exam day. Pick a group, then tick students off through one or more check steps (attendance, identity verification, exam copy submitted, and any custom step you add). Several teachers can check the same roster at the same time from their own phones, and each student can only be marked once per step. A camera page lets you scan a QR code or barcode printed on a student card to mark students hands-free.';
$string['modulenameplural'] = 'Exam checks';
$string['movedown'] = 'Move down';
$string['moveup'] = 'Move up';
$string['noinstances'] = 'There are no exam check activities in this course.';
$string['nomatchingstudents'] = 'No students match your search.';
$string['nostepsyet'] = 'No steps yet.';
$string['nostudents'] = 'There are no students to check in the selected group.';
$string['nostudentsatall'] = 'No students are enrolled on this course yet.';
$string['notchecked'] = 'Not checked';
$string['opascanner'] = 'Open scanner';
$string['pluginadministration'] = 'Exam check administration';
$string['pluginname'] = 'Exam check';
$string['pollinterval'] = 'Live refresh interval';
$string['pollinterval_desc'] = 'How often, in seconds, the dashboard checks for marks recorded by other teachers so that everyone sees an up-to-date roster. Set to 0 to disable live refresh.';
$string['privacy:checkedbyme'] = 'Checks you recorded as a teacher';
$string['privacy:checkedstudent'] = 'Checks recorded about you as a student';
$string['privacy:metadata:examcheck_marks'] = 'Records of which students were checked at which step, including who recorded each check.';
$string['privacy:metadata:examcheck_marks:checkedby'] = 'The teacher who recorded the check.';
$string['privacy:metadata:examcheck_marks:method'] = 'How the check was recorded (manually, from the list, or by scanning).';
$string['privacy:metadata:examcheck_marks:stepid'] = 'The check step the mark belongs to.';
$string['privacy:metadata:examcheck_marks:timecreated'] = 'The time the check was recorded.';
$string['privacy:metadata:examcheck_marks:userid'] = 'The student who was checked.';
$string['progresscount'] = '{$a->done} of {$a->total} checked';
$string['requireconfirm'] = 'Confirm before marking';
$string['requireconfirm_help'] = 'When enabled, the scanner shows the matched student\'s name and waits for the teacher to press a button before recording the check. When disabled, a scan marks the student immediately and returns to scanning the next student. Teachers can override this for their own session.';
$string['resetallchecks'] = 'Clear all checks';
$string['resetmarks'] = 'Delete all recorded checks';
$string['result_conflict'] = 'Already checked: {$a->user} was marked by {$a->by}, {$a->ago}.';
$string['result_marked'] = '{$a} marked as checked.';
$string['result_needsconfirm'] = 'Found {$a}. Confirm to mark them as checked.';
$string['result_notchecked'] = '{$a} was not checked.';
$string['result_notfound'] = 'No student in this roster matches the scanned value "{$a}".';
$string['result_notinroster'] = '{$a} is not in the selected group for this activity.';
$string['result_unmarked'] = 'Check removed for {$a}.';
$string['roster'] = 'Roster';
$string['scanfield'] = 'Scan match field';
$string['scanfield_help'] = 'The user field whose value is encoded in the QR code or barcode on the student card. When a teacher scans a code, the value is matched against this field to find the student. Teachers can change the field for their own scanning session on the scanner page.';
$string['scanmanualentry'] = 'Or type / scan the value manually';
$string['scanmanualentryplaceholder'] = 'Enter or scan a code value';
$string['scanner'] = 'Scanner';
$string['scannerfor'] = 'Scanner: {$a}';
$string['scannext'] = 'Scan next student';
$string['scanning'] = 'Point the camera at a QR code or barcode…';
$string['scanningsettings'] = 'Scanning defaults';
$string['scanregex'] = 'Scan extraction pattern';
$string['scanregex_help'] = 'An optional regular expression (without delimiters) applied to the scanned QR/barcode value to extract the part to match against the scan field. The first capturing group is used, or the whole match if there is no group.

This is useful when the code on a student card encodes extra information around the student number. For example, if a card scans as "U=12345678;LIB=987" and the student number is the 8 digits, the pattern "(\d{8})" extracts "12345678" to match against the ID number. Leave empty to match the whole scanned value.';
$string['scanregexplaceholder'] = 'e.g. (\d{8})';
$string['scanstep'] = 'Step to mark';
$string['scansubmit'] = 'Look up';
$string['searchstudents'] = 'Search by name or ID number';
$string['sessionsettings'] = 'Scanning session settings';
$string['showonlyunchecked'] = 'Show only not-yet-checked';
$string['startcamera'] = 'Start camera';
$string['stepadded'] = 'Step added.';
$string['stepdeleted'] = 'Step deleted.';
$string['stepname'] = 'Step name';
$string['steps'] = 'Check steps';
$string['stepupdated'] = 'Step updated.';
$string['stopcamera'] = 'Stop camera';
$string['student'] = 'Student';
$string['unmarkaction'] = 'Undo "{$a}"';
$string['visiblecount'] = '{$a->visible} of {$a->total} shown';
