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

$string['addeditstep'] = 'Add a step';
$string['addstep'] = 'Add step';
$string['ago'] = '{$a} ago';
$string['agoseconds'] = '{$a} seconds ago';
$string['allparticipants'] = 'All participants';
$string['attendancesettings'] = 'Attendance settings';
$string['backtomanagesteps'] = 'Back to manage steps';
$string['bulkcheck'] = 'Check: {$a}';
$string['bulkresult'] = '{$a->done} updated, {$a->skipped} skipped.';
$string['bulkresultwithfailed'] = '{$a->done} updated, {$a->skipped} skipped, {$a->failed} blocked by step requirements.';
$string['bulkuncheck'] = 'Uncheck: {$a}';
$string['camera'] = 'Camera';
$string['camerablocked'] = 'The camera could not be started. Check that you granted camera permission and that the page is served over HTTPS, or use manual entry below.';
$string['cameraunsupported'] = 'Live camera scanning is unavailable here. It needs a device camera and a secure (HTTPS) connection. You can still type or scan values into the manual entry box below (for example with a USB or Bluetooth barcode scanner).';
$string['cannotdeletelaststep'] = 'You cannot delete the last remaining step. An exam check activity must have at least one step.';
$string['checked'] = 'Checked';
$string['checkedbyon'] = 'Checked by {$a->user} ({$a->ago})';
$string['checkscleared'] = 'Checks cleared.';
$string['checkstatus'] = 'Check status';
$string['checkstatus_optionchecked'] = '{$a}: checked';
$string['checkstatus_optionnotchecked'] = '{$a}: not checked';
$string['clearchecks'] = 'Clear checks';
$string['col_checked'] = '{$a}: checked';
$string['col_checkedat'] = '{$a}: checked at';
$string['col_checkedby'] = '{$a}: checked by';
$string['columns'] = 'Columns';
$string['completionactivity'] = 'Activity';
$string['completionactivity_help'] = 'Choose any activity in this course. The invigilator will only be able to check in a student once completion is recorded for that student on the chosen activity.';
$string['completionallsteps'] = 'All steps';
$string['completioncheck'] = 'Completion check';
$string['completionchecked'] = 'Student must be checked to complete the activity';
$string['completionchecked_desc'] = 'Checked as required';
$string['completiondetail:checked'] = 'Be checked as required';
$string['completionstepsel'] = 'Step required for completion';
$string['completionstepsel_help'] = 'Choose what completes the activity for a student. Select "All steps" to require the student to be checked on every step, or pick a single step (for example "Attendance") to complete the activity as soon as the student is checked on just that step.

Because completion is recorded per student, you can use it as a prerequisite elsewhere — for example, requiring this activity to be complete before a quiz becomes available.';
$string['confirm_step'] = 'Confirm: {$a}';
$string['confirmclearstep'] = 'Remove all recorded checks for the step "{$a}"? The step itself is kept.';
$string['confirmdeletestep'] = 'Delete the step "{$a}"? All checks recorded for this step will be permanently removed.';
$string['confirmmark'] = 'Confirm and mark';
$string['confirmresetall'] = 'Remove every recorded check for all steps in this activity? The steps themselves are kept.';
$string['dashboard'] = 'Checking dashboard';
$string['defaultenablescanner'] = 'Enable scanner by default';
$string['defaultenablescanner_desc'] = 'Whether the camera/barcode scanner is enabled when a new exam check activity is created.';
$string['defaultrequireconfirm'] = 'Confirm before marking by default';
$string['defaultrequireconfirm_desc'] = 'Whether new exam check activities require the teacher to confirm a scanned student before marking them.';
$string['defaultrequiresequential'] = 'Require step-by-step completion by default';
$string['defaultrequiresequential_desc'] = 'Whether new exam check activities require students to be checked on steps in order by default.';
$string['defaultscanfield'] = 'Default scan match field';
$string['defaultscanfield_desc'] = 'The scan match field selected by default when a teacher creates a new exam check activity.';
$string['defaultscannermode'] = 'Default scanner mode';
$string['defaultscannermode_desc'] = 'The scanner mode (Scanning or Reading) selected by default each time a teacher opens the scanner page. Teachers can still switch modes for their own session; the choice is not remembered after the page is left or reloaded.';
$string['defaultscanregex'] = 'Scan extraction pattern';
$string['defaultscanregex_desc'] = 'A regular expression (without delimiters) applied by every exam check scanner on this site to extract the part of a scanned QR/barcode value to match against the scan field. The first capturing group is used, or the whole match if there is no group. For example, if a card scans as "U=12345678;LIB=987", the pattern "(\d{8})" extracts "12345678". Leave empty to match the whole scanned value.';
$string['defaultshowcameraswitcher'] = 'Show camera switcher by default';
$string['defaultshowcameraswitcher_desc'] = 'Whether the scanner shows a camera/lens picker when a new exam check activity is created.';
$string['defaultstepname'] = 'Attendance';
$string['deletestep'] = 'Delete step';
$string['editstep'] = 'Edit step';
$string['editstepfor'] = 'Edit step: {$a}';
$string['enablescanner'] = 'Enable scanner';
$string['enablescanner_help'] = 'When enabled, teachers can open the camera/barcode scanner from the activity to check students. When disabled, the scanner is hidden and cannot be opened; the list and bulk actions on the dashboard still work.';
$string['error_alreadychecked'] = 'This student has already been checked for this step.';
$string['error_invalidflagtype'] = 'The flag type provided is not valid.';

// Privacy metadata for new tables.
$string['error_invalidregex'] = 'This is not a valid regular expression.';
$string['error_nosteps'] = 'This activity has no check steps yet. Add a step first.';
$string['error_overridedenied'] = 'This mark was recorded by another teacher and you do not have permission to change it.';
$string['error_uncheckdenied'] = 'You do not have permission to remove this check.';
$string['eventusermarked'] = 'Student marked as checked';
$string['eventuserunmarked'] = 'Student check removed';
$string['examcheck:addinstance'] = 'Add a new exam check activity';
$string['examcheck:check'] = 'Record and remove checks against students';
$string['examcheck:managesteps'] = 'Manage the check steps of an exam check activity';
$string['examcheck:override'] = 'Override or remove a mark recorded by another teacher';
$string['examcheck:view'] = 'View the exam check dashboard';
$string['exempt_grant'] = 'Grant exemption';
$string['exempt_help'] = 'An exempted student is excused from this step (e.g. a student with a disability who follows a different entry procedure). Exempted steps are shown with a distinct indicator in the roster.';

// Capability-related error strings.
$string['exempt_reason'] = 'Reason for exemption';
$string['exempt_revoke'] = 'Revoke exemption';
$string['exempt_studentisexempt'] = 'Exempt';
$string['export'] = 'Export';
$string['exportas'] = 'Export as';
$string['exportfilename'] = 'examcheck';
$string['field_idnumber'] = 'ID number';
$string['field_profile'] = 'Profile field: {$a}';
$string['field_userid'] = 'User id (internal)';
$string['flag_addflag'] = 'Add flag';
$string['flag_note'] = 'Flag note';
$string['flag_removeflag'] = 'Remove flag';
$string['flagstudent'] = 'Flag student';
$string['flagstudent_help'] = 'Raise a flag to note an exceptional situation for this student (e.g. suspected malpractice or exclusion). Flags are visible to all invigilators and supervisors with the appropriate capability.';
$string['flagtype_administrative'] = 'Administrative note';
$string['flagtype_excluded'] = 'Excluded from exam';
$string['flagtype_malpractice'] = 'Suspected malpractice';
$string['flagtype_other'] = 'Other';
$string['justnow'] = 'just now';
$string['managesteps'] = 'Manage check steps';
$string['managestepslink'] = 'Manage steps';
$string['markaction'] = 'Mark "{$a}"';
$string['markchecked'] = 'Mark checked';
$string['marksrecorded'] = 'Checks recorded';
$string['modulename'] = 'Exam check';
$string['modulename_help'] = 'The Exam check activity lets teachers run attendance and verification checks on exam day. Pick a group, then tick students off through one or more check steps (attendance, identity verification, exam copy submitted, and any custom step you add). Several teachers can check the same roster at the same time from their own phones, and each student can only be marked once per step. A camera page lets you scan a QR code or barcode printed on a student card to mark students hands-free.';
$string['modulenameplural'] = 'Exam checks';
$string['movedown'] = 'Move down';
$string['moveup'] = 'Move up';
$string['nocompletionactivitiesincourse'] = 'There are no activities with completion tracking enabled in this course yet. Enable completion tracking on an activity before configuring this step to require its completion.';
$string['noinstances'] = 'There are no exam check activities in this course.';
$string['nomatchingstudents'] = 'No students match your search.';
$string['noquizinthiscourse'] = 'There are no quiz activities in this course yet. Add a quiz before configuring this step to require a submitted attempt.';
$string['nostepsyet'] = 'No steps yet.';
$string['nostudents'] = 'There are no students to check in the selected group.';
$string['nostudentsatall'] = 'No students are enrolled on this course yet.';
$string['notchecked'] = 'Not checked';
$string['opascanner'] = 'Open scanner';
$string['participant_status'] = 'Participant status';
$string['pluginadministration'] = 'Exam check administration';
$string['pluginname'] = 'Exam check';
$string['pollinterval'] = 'Live refresh interval';
$string['pollinterval_desc'] = 'How often, in seconds, the dashboard checks for marks recorded by other teachers so that everyone sees an up-to-date roster. Set to 0 to disable live refresh.';
$string['privacy:checkedbyme'] = 'Checks you recorded as a teacher';
$string['privacy:checkedstudent'] = 'Checks recorded about you as a student';
$string['privacy:metadata:examcheck_exemptions'] = 'Records of students exempted from specific check steps.';
$string['privacy:metadata:examcheck_exemptions:exemptedby'] = 'The teacher who granted the exemption.';
$string['privacy:metadata:examcheck_exemptions:reason'] = 'The reason for the exemption.';
$string['privacy:metadata:examcheck_exemptions:userid'] = 'The student who is exempt.';
$string['privacy:metadata:examcheck_flags'] = 'Student-level flags raised during an exam sitting.';
$string['privacy:metadata:examcheck_flags:flaggedby'] = 'The invigilator who raised the flag.';
$string['privacy:metadata:examcheck_flags:flagtype'] = 'The type of flag raised.';
$string['privacy:metadata:examcheck_flags:note'] = 'The note associated with the flag.';
$string['privacy:metadata:examcheck_flags:userid'] = 'The flagged student.';
$string['privacy:metadata:examcheck_marks'] = 'Records of which students were checked at which step, including who recorded each check.';
$string['privacy:metadata:examcheck_marks:checkedby'] = 'The teacher who recorded the check.';
$string['privacy:metadata:examcheck_marks:method'] = 'How the check was recorded (manually, from the list, or by scanning).';
$string['privacy:metadata:examcheck_marks:stepid'] = 'The check step the mark belongs to.';
$string['privacy:metadata:examcheck_marks:timecreated'] = 'The time the check was recorded.';
$string['privacy:metadata:examcheck_marks:userid'] = 'The student who was checked.';
$string['privacy:metadata:examcheck_uncheck_events'] = 'Records of uncheck actions with the reason documented by the invigilator.';
$string['privacy:metadata:examcheck_uncheck_events:actingby'] = 'The invigilator who removed the check.';
$string['privacy:metadata:examcheck_uncheck_events:reasonkey'] = 'The predefined reason key selected, if any.';
$string['privacy:metadata:examcheck_uncheck_events:reasontext'] = 'The free-text reason entered, if any.';
$string['privacy:metadata:examcheck_uncheck_events:timecreated'] = 'The time the check was removed.';
$string['privacy:metadata:examcheck_uncheck_events:userid'] = 'The student whose check was removed.';
$string['progresscount'] = '{$a->done} of {$a->total} checked';
$string['quizactivity'] = 'Quiz activity';
$string['quizactivity_help'] = 'The quiz this step verifies. This restriction is not customisable: the student must have no attempt in progress, and a minimum of one completed (submitted) attempt, before they can be marked on this step.';
$string['requireconfirm'] = 'Confirm before marking';
$string['requireconfirm_help'] = 'When enabled, the scanner shows the matched student\'s name and waits for the teacher to press a button before recording the check. When disabled, a scan marks the student immediately and returns to scanning the next student. Teachers can override this for their own session.';
$string['requirementtype'] = 'Requirements for checking';
$string['requirementtype_completion'] = 'Activity completion';
$string['requirementtype_help'] = 'Optionally require students to satisfy an extra condition before an invigilator can check them on this step:

* **Submitted quiz attempt** — the student must have a finished attempt (and none in progress) on a chosen course quiz. Useful for an "Exam copy submitted" step backed by a real Moodle quiz.
* **Activity completion** — the student must have completion recorded on any other chosen activity in the course.';
$string['requirementtype_none'] = 'No requirement';
$string['requirementtype_quiz'] = 'Submitted quiz attempt';
$string['requiresequential'] = 'Require step-by-step completion';
$string['requiresequential_help'] = 'When enabled, a student can only be checked on a step once they have already been checked on the immediately preceding step (steps are checked in the order shown in Manage steps). This applies in addition to any custom requirement configured on the step itself — both must be satisfied.';
$string['resetallchecks'] = 'Clear all checks';
$string['resetmarks'] = 'Delete all recorded checks';
$string['result_conflict'] = 'Already checked: {$a->user} was marked by {$a->by}, {$a->ago}.';
$string['result_marked'] = '{$a} marked as checked.';
$string['result_needsconfirm'] = 'Found {$a}. Confirm to mark them as checked.';
$string['result_notchecked'] = '{$a} was not checked.';
$string['result_notenrolled'] = 'A matching user account was found for "{$a}", but is not enrolled in this course.';
$string['result_notfound'] = 'No student in this roster matches the scanned value "{$a}".';
$string['result_notinroster'] = '{$a} is not in the selected group for this activity.';
$string['result_read'] = '{$a->user}: {$a->statuses}';
$string['result_requirementnotmet_incomplete'] = '{$a->user} cannot be checked yet: they still need to complete "{$a->activity}" first.';
$string['result_requirementnotmet_inprogress'] = '{$a->user} still has an attempt in progress on "{$a->quiz}". Ask them to submit before checking.';
$string['result_requirementnotmet_misconfigured'] = 'This step has a requirement configured, but the linked quiz or activity is missing, no longer exists, or no longer tracks completion. Edit the step to fix it.';
$string['result_requirementnotmet_nosubmission'] = '{$a->user} has not submitted any attempt on "{$a->quiz}" yet.';
$string['result_requirementnotmet_sequential'] = '{$a->user} must be checked on the previous step, "{$a->previousstep}", before this one.';
$string['result_unmarked'] = 'Check removed for {$a}.';
$string['roster'] = 'Roster';
$string['scancodetype'] = 'Code type';
$string['scancodetype1d'] = '1D barcodes only (Code128, EAN, UPC, Codabar, ITF, DataBar)';
$string['scancodetype2d'] = '2D codes only (QR, Data Matrix, Aztec, PDF417, MaxiCode)';
$string['scancodetype_desc'] = 'Only affects the live camera scan. Manual entry and USB/Bluetooth barcode scanners submit plain text with no code type information, so they are not affected.';
$string['scancodetypeall'] = 'Both (QR and barcode)';
$string['scanfield'] = 'Scan match field';
$string['scanfield_help'] = 'The user field whose value is encoded in the QR code or barcode on the student card. When a teacher scans a code, the value is matched against this field to find the student. Teachers can change the field for their own scanning session on the scanner page.';
$string['scanmanualentry'] = 'Or type / scan the value manually';
$string['scanmanualentryplaceholder'] = 'Enter or scan a code value';
$string['scanner'] = 'Scanner';
$string['scannerdisabled'] = 'The scanner is disabled for this activity.';
$string['scannerfor'] = 'Scanner: {$a}';
$string['scannermode'] = 'Scanner mode';
$string['scannermode_desc'] = 'Scanning marks the student on the step below as soon as they are matched. Reading only looks the student up and shows their name and check status on every step, without marking anything.';
$string['scannermodereading'] = 'Reading';
$string['scannermodescanning'] = 'Scanning';
$string['scannext'] = 'Scan next student';
$string['scanning'] = 'Point the camera at a QR code or barcode…';
$string['scanningsettings'] = 'Scanning defaults';
$string['scanstep'] = 'Step to mark';
$string['scansubmit'] = 'Look up';
$string['searchstudents'] = 'Search by name or ID number';
$string['sessionsettings'] = 'Scanning session settings';
$string['showcameraswitcher'] = 'Show camera switcher';
$string['showcameraswitcher_help'] = 'When enabled, the scanner shows a dropdown to switch between the device\'s cameras (for example to pick a specific rear lens on a phone). Hidden on devices with a single camera.';
$string['startcamera'] = 'Start camera';
$string['stepadded'] = 'Step added.';
$string['stepdeleted'] = 'Step deleted.';
$string['stepname'] = 'Step name';
$string['steps'] = 'Check steps';
$string['stepupdated'] = 'Step updated.';
$string['stopcamera'] = 'Stop camera';
$string['student'] = 'Student';
$string['studentisflagged'] = 'This student has been flagged: {$a}';

// Step exemption strings.
$string['uncheck'] = 'Uncheck';
$string['uncheckdialog_cancel'] = 'Cancel';
$string['uncheckdialog_choosereason'] = '— Select a reason —';
$string['uncheckdialog_confirm'] = 'Confirm removal';
$string['uncheckdialog_freetextlabel'] = 'Additional notes';
$string['uncheckdialog_freetextplaceholder'] = 'Describe the reason in your own words…';
$string['uncheckdialog_mandatoryhint'] = 'A reason is required before this check can be removed.';
$string['uncheckdialog_optionalhint'] = 'Optionally document why this check is being removed.';
$string['uncheckdialog_reasonlabel'] = 'Reason for removal';
$string['uncheckdialog_reasonrequired'] = 'Please select a reason or enter a note before confirming.';
$string['uncheckdialog_title'] = 'Remove check — document reason';

// Per-step uncheck mode setting.
$string['uncheckfreetext'] = 'Allow free-text reason';
$string['uncheckfreetext_help'] = 'When enabled, invigilators with the "Enter free-text reason" capability may type a note in addition to (or instead of) selecting a predefined reason.';

// Activity-level predefined reasons setting.
$string['uncheckmode'] = 'Uncheck documentation';
$string['uncheckmode_help'] = 'Controls whether an invigilator must document a reason when removing a student's check on this step. <strong>None</strong>: no confirmation or reason needed. <strong>Optional</strong>: confirmation is required but a reason does not have to be entered. <strong>Mandatory</strong>: a reason (predefined or free text) must be provided before the check can be removed.';
$string['uncheckmode_mandatory'] = 'Mandatory — a reason must be given';
$string['uncheckmode_none'] = 'None — no confirmation or reason required';
$string['uncheckmode_optional'] = 'Optional — confirmation required; reason is optional';
$string['uncheckreason_escortedout'] = 'Student escorted out — attendance not recorded';
$string['uncheckreason_excluded'] = 'Student excluded from exam';
$string['uncheckreason_exemptiongranted'] = 'Student has a documented exemption';
$string['uncheckreason_fallill'] = 'Student has fallen ill';
$string['uncheckreason_markedinerror'] = 'Marked in error';
$string['uncheckreason_other'] = 'Other reason';
$string['uncheckreason_suspectedirregularity'] = 'Suspected irregularity — documented separately';

// Student flag strings.
$string['uncheckreasons'] = 'Predefined uncheck reasons';
$string['uncheckreasons_help'] = 'Choose which predefined reasons are offered to invigilators when removing a check. Leave all unchecked to offer the full default list.';

// Predefined uncheck reason labels.
$string['unchecksettings'] = 'Uncheck documentation';

// Uncheck reason dialog strings.
$string['unmarkaction'] = 'Undo "{$a}"';
$string['view_in_roster'] = 'View in roster';
$string['visiblecount'] = '{$a->visible} of {$a->total} shown';
$string['withselectedstudents'] = 'With selected students';
