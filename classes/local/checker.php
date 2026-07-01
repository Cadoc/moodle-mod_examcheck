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

namespace mod_examcheck\local;

use context_module;
use mod_examcheck\local\exemption_manager;
use mod_examcheck\local\flag_manager;
use mod_examcheck\local\uncheck_reason;
use moodle_exception;
use stdClass;

/**
 * Core checking logic: who is on the roster, and recording / removing marks.
 *
 * All marks are shared: there is at most one mark per (step, student). When a
 * second teacher tries to mark a student who is already checked, this class
 * reports a conflict describing who recorded the original mark and when, rather
 * than silently re-marking.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class checker {
    /** @var stdClass The examcheck instance record. */
    protected stdClass $examcheck;

    /** @var context_module The module context. */
    protected context_module $context;

    /**
     * Constructor.
     *
     * @param stdClass $examcheck The examcheck instance record.
     * @param context_module $context The module context.
     */
    public function __construct(stdClass $examcheck, context_module $context) {
        $this->examcheck = $examcheck;
        $this->context = $context;
    }

    /**
     * Convenience factory from a course module id.
     *
     * @param int $cmid The course module id.
     * @return self
     */
    public static function from_cmid(int $cmid): self {
        global $DB;
        [$course, $cm] = get_course_and_cm_from_cmid($cmid, 'examcheck');
        $examcheck = $DB->get_record('examcheck', ['id' => $cm->instance], '*', MUST_EXIST);
        return new self($examcheck, context_module::instance($cm->id));
    }

    /**
     * The examcheck instance record.
     *
     * @return stdClass
     */
    public function get_instance(): stdClass {
        return $this->examcheck;
    }

    /**
     * The module context.
     *
     * @return context_module
     */
    public function get_context(): context_module {
        return $this->context;
    }

    /**
     * Ensure the current user is allowed to act on the requested group.
     *
     * Under separate groups, a user without moodle/site:accessallgroups may
     * only work with the groups they belong to. This guards the group id that
     * arrives from the request on the scanner, export and web services, so it
     * cannot be used to reach students in other groups.
     *
     * @param int $groupid The requested group id (0 = all participants).
     * @throws \required_capability_exception When the group is not permitted.
     */
    public function require_group_access(int $groupid): void {
        $cm = get_coursemodule_from_instance(
            'examcheck',
            $this->examcheck->id,
            $this->examcheck->course,
            false,
            MUST_EXIST
        );

        if (
            groups_get_activity_groupmode($cm) != SEPARATEGROUPS
                || has_capability('moodle/site:accessallgroups', $this->context)
        ) {
            // No groups, visible groups, or privileged: any selection is allowed.
            return;
        }

        $allowed = groups_get_activity_allowed_groups($cm);
        if ($groupid == 0 || !isset($allowed[$groupid])) {
            throw new \required_capability_exception(
                $this->context,
                'moodle/site:accessallgroups',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Ensure the current user may act on a specific student.
     *
     * Used where no group id is supplied (e.g. removing a mark): under separate
     * groups, the student must share at least one group with the current user.
     *
     * @param int $userid The student user id.
     * @throws \required_capability_exception When the student is out of reach.
     */
    public function require_user_access(int $userid): void {
        $cm = get_coursemodule_from_instance(
            'examcheck',
            $this->examcheck->id,
            $this->examcheck->course,
            false,
            MUST_EXIST
        );

        if (
            groups_get_activity_groupmode($cm) != SEPARATEGROUPS
                || has_capability('moodle/site:accessallgroups', $this->context)
        ) {
            return;
        }

        $allowed = array_keys(groups_get_activity_allowed_groups($cm));
        $usergroups = array_keys(groups_get_all_groups($this->examcheck->course, $userid, $cm->groupingid));
        if (!array_intersect($allowed, $usergroups)) {
            throw new \required_capability_exception(
                $this->context,
                'moodle/site:accessallgroups',
                'nopermissions',
                ''
            );
        }
    }

    /**
     * Return the students to be checked, optionally restricted to a group.
     *
     * The roster is every actively enrolled user who cannot themselves check
     * students (i.e. teachers and graders are excluded), sorted by name.
     *
     * @param int $groupid Group id to filter by, or 0 for all participants.
     * @param string[] $extrafields Extra user-table fields to select (e.g. identity fields).
     * @return stdClass[] User records keyed by user id.
     */
    public function get_roster(int $groupid = 0, array $extrafields = []): array {
        $base = ['id', 'firstname', 'lastname', 'firstnamephonetic', 'lastnamephonetic',
            'middlename', 'alternatename', 'picture', 'imagealt', 'email', 'idnumber'];
        // Append any requested extra fields not already selected (e.g. phone, department).
        $select = array_values(array_unique(array_merge($base, $extrafields)));
        $fields = implode(', ', array_map(fn($f) => 'u.' . $f, $select));
        $users = get_enrolled_users($this->context, '', $groupid, $fields, 'u.lastname ASC, u.firstname ASC', 0, 0, true);

        // Exclude anyone who is themselves a checker (teacher / grader).
        $checkers = get_enrolled_users($this->context, 'mod/examcheck:check', $groupid, 'u.id');
        foreach ($checkers as $checker) {
            unset($users[$checker->id]);
        }

        return $users;
    }

    /**
     * Return the user ids that make up the roster for a group.
     *
     * @param int $groupid Group id to filter by, or 0 for all participants.
     * @return int[]
     */
    public function get_roster_ids(int $groupid = 0): array {
        return array_map('intval', array_keys($this->get_roster($groupid)));
    }

    /**
     * Return all marks for this instance, indexed by step id then user id.
     *
     * @param int $since Only return marks created at or after this time (0 = all).
     * @return array<int, array<int, stdClass>> Marks indexed [stepid][userid].
     */
    public function get_marks(int $since = 0): array {
        global $DB;

        $params = ['examcheckid' => $this->examcheck->id];
        $where = 'examcheckid = :examcheckid';
        if ($since > 0) {
            $where .= ' AND timecreated >= :since';
            $params['since'] = $since;
        }

        $records = $DB->get_records_select('examcheck_marks', $where, $params);
        $indexed = [];
        foreach ($records as $mark) {
            $indexed[(int) $mark->stepid][(int) $mark->userid] = $mark;
        }
        return $indexed;
    }

    /**
     * Get the mark for a single (step, user) pair, if any.
     *
     * @param int $stepid The step id.
     * @param int $userid The student user id.
     * @return stdClass|false The mark record, or false when not checked.
     */
    public function get_mark(int $stepid, int $userid) {
        global $DB;
        return $DB->get_record('examcheck_marks', ['stepid' => $stepid, 'userid' => $userid]);
    }

    /**
     * Record a check for a student against a step.
     *
     * If the student is already checked for the step, no change is made and a
     * conflict result is returned describing the existing mark.
     *
     * @param int $stepid The step id (must belong to this instance).
     * @param int $userid The student user id (must be on the roster).
     * @param int $checkedby The teacher recording the mark.
     * @param string $method One of "manual", "list" or "scan".
     * @param int $groupid Group context used to validate roster membership.
     * @return array Result with at least a "status" key (marked|conflict|notinroster).
     */
    public function mark_user(int $stepid, int $userid, int $checkedby, string $method = 'manual', int $groupid = 0): array {
        global $DB;

        $step = $this->require_step($stepid);

        if (!in_array($userid, $this->get_roster_ids($groupid), true)) {
            return ['status' => 'notinroster', 'user' => self::user_label($userid)];
        }

        // Lock-free conflict detection backed by the (stepid, userid) unique key:
        // if a concurrent insert wins the race, the catch below reports the conflict.
        if ($existing = $this->get_mark($stepid, $userid)) {
            return $this->conflict_result($existing, $userid);
        }

        // Activity-wide "require step-by-step completion" gate, checked before the
        // step's own requirement: custom requirements build on top of sequencing.
        if ($failure = $this->validate_sequential($step, $userid)) {
            return $failure;
        }

        // Per-step "requirements for checking" gate. Returns null when the step has
        // no requirement configured; otherwise a requirementnotmet result describing why.
        if ($failure = $this->validate_requirement($step, $userid)) {
            return $failure;
        }

        $mark = (object) [
            'examcheckid' => $this->examcheck->id,
            'stepid'      => $stepid,
            'userid'      => $userid,
            'checkedby'   => $checkedby,
            'method'      => in_array($method, ['manual', 'list', 'scan'], true) ? $method : 'manual',
            'timecreated' => time(),
        ];

        try {
            $mark->id = $DB->insert_record('examcheck_marks', $mark);
        } catch (\dml_exception $e) {
            // Another teacher inserted the same mark microseconds earlier.
            if ($existing = $this->get_mark($stepid, $userid)) {
                return $this->conflict_result($existing, $userid);
            }
            throw $e;
        }

        \mod_examcheck\event\user_marked::create_from_mark($this->context, $mark, $step)->trigger();
        $this->update_completion_for_user($userid);

        return ['status' => 'marked', 'mark' => $mark, 'user' => self::user_label($userid)];
    }

    /**
     * Remove a check for a student against a step, optionally documenting the reason.
     *
     * Removing a mark recorded by a different teacher requires the
     * mod/examcheck:override capability. When the step requires documentation
     * (uncheckmode = 2), a reason key or free-text must be supplied.
     *
     * @param int    $stepid     The step id.
     * @param int    $userid     The student user id.
     * @param int    $actingby   The teacher removing the mark.
     * @param string $reasonkey  Optional predefined reason key.
     * @param string $reasontext Optional free-text reason (requires mod/examcheck:uncheckfreetext).
     * @return array Result with a "status" key (unmarked|notchecked|reasonrequired).
     * @throws moodle_exception When override or free-text permission is missing.
     */
    public function unmark_user(
        int $stepid,
        int $userid,
        int $actingby,
        string $reasonkey = '',
        string $reasontext = ''
    ): array {
        global $DB;

        $step = $this->require_step($stepid);

        if (!$mark = $this->get_mark($stepid, $userid)) {
            return ['status' => 'notchecked', 'user' => self::user_label($userid)];
        }

        // Override check: own marks can always be removed; others' marks require :override.
        if ((int) $mark->checkedby !== $actingby && !has_capability('mod/examcheck:override', $this->context)) {
            throw new moodle_exception('error_overridedenied', 'mod_examcheck');
        }

        // Free-text capability gate.
        if ($reasontext !== '' && !has_capability('mod/examcheck:uncheckfreetext', $this->context)) {
            $reasontext = '';
        }

        // Validate reason key.
        if ($reasonkey !== '' && !\mod_examcheck\local\uncheck_reason::is_valid_key($reasonkey)) {
            $reasonkey = '';
        }

        // Mandatory reason check: when uncheckmode = 2, at least one form of reason is required.
        $uncheckmode = (int) ($step->uncheckmode ?? 0);
        if ($uncheckmode === 2 && $reasonkey === '' && $reasontext === '') {
            return ['status' => 'reasonrequired', 'user' => self::user_label($userid)];
        }

        $DB->delete_records('examcheck_marks', ['id' => $mark->id]);

        // Persist the audit event whenever any reason is present, or whenever the
        // step requires documentation (so "no reason supplied" cases are also logged).
        if ($uncheckmode > 0 || $reasonkey !== '' || $reasontext !== '') {
            $DB->insert_record('examcheck_uncheck_events', (object) [
                'examcheckid' => $this->examcheck->id,
                'stepid'      => $stepid,
                'userid'      => $userid,
                'actingby'    => $actingby,
                'reasonkey'   => $reasonkey !== '' ? $reasonkey : null,
                'reasontext'  => $reasontext !== '' ? $reasontext : null,
                'timecreated' => time(),
            ]);
        }

        \mod_examcheck\event\user_unmarked::create_from_mark($this->context, $mark, $step)->trigger();
        $this->update_completion_for_user($userid);

        return ['status' => 'unmarked', 'user' => self::user_label($userid)];
    }

    /**
     * Grant a step exemption for a student.
     *
     * Requires mod/examcheck:exempt capability.
     *
     * @param int    $stepid     The step id.
     * @param int    $userid     The student user id.
     * @param string $reason     Optional free-text reason for the exemption.
     * @param int    $exemptedby The teacher granting the exemption.
     * @return array Result with a "status" key (exempted|alreadyexempt).
     */
    public function grant_exemption(int $stepid, int $userid, string $reason, int $exemptedby): array {
        require_capability('mod/examcheck:exempt', $this->context);
        $this->require_step($stepid);

        $record = \mod_examcheck\local\exemption_manager::grant_exemption(
            $this->examcheck->id, $stepid, $userid, $reason, $exemptedby
        );

        $status = $record->timecreated === time() ? 'exempted' : 'alreadyexempt';
        return ['status' => $status, 'user' => self::user_label($userid)];
    }

    /**
     * Revoke a step exemption for a student.
     *
     * Requires mod/examcheck:exempt capability.
     *
     * @param int $stepid The step id.
     * @param int $userid The student user id.
     * @return array Result with a "status" key (revoked|notexempt).
     */
    public function revoke_exemption(int $stepid, int $userid): array {
        require_capability('mod/examcheck:exempt', $this->context);
        $this->require_step($stepid);

        $deleted = \mod_examcheck\local\exemption_manager::revoke_exemption($stepid, $userid);
        return ['status' => $deleted ? 'revoked' : 'notexempt', 'user' => self::user_label($userid)];
    }

    /**
     * Add a flag for a student (suspected malpractice, exclusion, etc.).
     *
     * Requires mod/examcheck:flag capability.
     *
     * @param int    $userid   The student user id.
     * @param string $flagtype One of the flag_manager TYPE_* constants.
     * @param string $note     Optional free-text note.
     * @param int    $flaggedby The teacher raising the flag.
     * @return array Result with a "status" key (flagged) and the flag record.
     */
    public function add_flag(int $userid, string $flagtype, string $note, int $flaggedby): array {
        require_capability('mod/examcheck:flag', $this->context);

        if (!\mod_examcheck\local\flag_manager::is_valid_type($flagtype)) {
            throw new moodle_exception('error_invalidflagtype', 'mod_examcheck');
        }

        $flag = \mod_examcheck\local\flag_manager::add_flag(
            $this->examcheck->id, $userid, $flagtype, $note, $flaggedby
        );

        return ['status' => 'flagged', 'flag' => $flag, 'user' => self::user_label($userid)];
    }

    /**
     * Remove a student flag.
     *
     * Requires mod/examcheck:flag capability.
     *
     * @param int $flagid The flag record id.
     * @return array Result with a "status" key (removed|notfound).
     */
    public function remove_flag(int $flagid): array {
        require_capability('mod/examcheck:flag', $this->context);
        $deleted = \mod_examcheck\local\flag_manager::remove_flag($flagid, $this->examcheck->id);
        return ['status' => $deleted ? 'removed' : 'notfound'];
    }

    /**
     * Resolve a scanned value to a student and mark them, honouring the
     * "confirm before marking" behaviour.
     *
     * @param int $stepid The step id.
     * @param string $fieldkey The scan field key (see {@see scanfield}).
     * @param string $value The raw scanned value.
     * @param bool $confirm Whether the teacher has confirmed the matched student.
     * @param bool $requireconfirm Whether confirmation is required this session.
     * @param int $checkedby The teacher recording the mark.
     * @param int $groupid Group context used to validate roster membership.
     * @param string $regex Optional regex (no delimiters) to extract the value to match.
     * @return array Result: notfound|needsconfirm|marked|conflict, plus user data.
     */
    public function scan(
        int $stepid,
        string $fieldkey,
        string $value,
        bool $confirm,
        bool $requireconfirm,
        int $checkedby,
        int $groupid = 0,
        string $regex = ''
    ): array {
        $step = $this->require_step($stepid);

        // Optionally extract the part of the scanned code to match (e.g. a
        // student number embedded in a longer barcode payload).
        $needle = scanfield::apply_regex($regex, $value);
        if ($needle === null || $needle === '') {
            return ['status' => 'notfound', 'value' => trim($value)];
        }

        $rosterids = $this->get_roster_ids($groupid);
        $userid = scanfield::find_user($fieldkey, $needle, $rosterids);

        if (!$userid) {
            return ['status' => 'notfound', 'value' => trim($value)];
        }

        // Already checked? Report the conflict regardless of the confirm setting.
        if ($existing = $this->get_mark($stepid, $userid)) {
            return $this->conflict_result($existing, $userid);
        }

        // Fail fast on the gates so the teacher never sees a "Confirm" prompt for a
        // student who will be refused. Same checks fire again inside mark_user as a
        // belt-and-braces — they're idempotent.
        if ($failure = $this->validate_sequential($step, $userid)) {
            return $failure;
        }
        if ($failure = $this->validate_requirement($step, $userid)) {
            return $failure;
        }

        // Pause for the teacher to confirm the student before marking.
        if ($requireconfirm && !$confirm) {
            return [
                'status' => 'needsconfirm',
                'userid' => $userid,
                'user'   => self::user_label($userid),
            ];
        }

        return $this->mark_user($stepid, $userid, $checkedby, 'scan', $groupid);
    }

    /**
     * Count checked students per step for the given group, for progress display.
     *
     * @param int $groupid Group id, or 0 for all participants.
     * @return array<int, int> Map of step id => number of checked students.
     */
    public function get_progress(int $groupid = 0): array {
        $rosterids = $this->get_roster_ids($groupid);
        $rostermap = array_fill_keys($rosterids, true);
        $marks = $this->get_marks();

        $progress = [];
        foreach (steps::get_steps($this->examcheck->id) as $step) {
            $count = 0;
            foreach (($marks[$step->id] ?? []) as $userid => $mark) {
                if (isset($rostermap[$userid])) {
                    $count++;
                }
            }
            $progress[(int) $step->id] = $count;
        }
        return $progress;
    }

    /**
     * Recalculate activity completion for a student after a mark change.
     *
     * This makes the "checked on the required steps" rule take effect
     * immediately, so completion-gated activities (e.g. a quiz that requires
     * attendance) unlock as soon as the teacher checks the student.
     *
     * @param int $userid The student whose completion should be recalculated.
     */
    protected function update_completion_for_user(int $userid): void {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        $cm = get_coursemodule_from_instance('examcheck', $this->examcheck->id, $this->examcheck->course);
        if (!$cm) {
            return;
        }
        $course = get_course($this->examcheck->course);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_AUTOMATIC && !empty($this->examcheck->completionchecked)) {
            $completion->update_state($cm, COMPLETION_UNKNOWN, $userid);
        }
    }

    /**
     * Enforce the activity-wide "require step-by-step completion" setting.
     *
     * When enabled, a step can only be checked once the immediately preceding
     * step (by sortorder) is already checked for the same student. The first
     * step has no predecessor and always passes. This gate only blocks new
     * marking attempts; it never retroactively unmarks a later step if an
     * earlier one is subsequently unmarked.
     *
     * @param stdClass $step The step record being checked.
     * @param int $userid The student user id.
     * @return array|null Requirementnotmet result (reason "sequential"), or null when the gate passes / is off.
     */
    protected function validate_sequential(stdClass $step, int $userid): ?array {
        if (empty($this->examcheck->requiresequential)) {
            return null;
        }

        $previous = steps::get_previous_step($this->examcheck->id, (int) $step->id);
        if (!$previous) {
            return null;
        }

        if (!$this->get_mark((int) $previous->id, $userid)) {
            return [
                'status'       => 'requirementnotmet',
                'reason'       => 'sequential',
                'user'         => self::user_label($userid),
                'previousstep' => format_string($previous->name, true, ['context' => $this->context]),
            ];
        }

        return null;
    }

    /**
     * Enforce the optional "requirements for checking" gate on a step.
     *
     * Returns null when the step has no requirement configured (or an unknown
     * type), or a requirementnotmet result array describing why the student
     * does not qualify yet.
     *
     * @param stdClass $step The step record (must include requirementtype and requirementcmid).
     * @param int $userid The student user id.
     * @return array|null Requirementnotmet result, or null when the gate passes / is off.
     */
    protected function validate_requirement(stdClass $step, int $userid): ?array {
        switch ($step->requirementtype ?? 'none') {
            case 'quiz':
                return $this->validate_quiz_requirement($step, $userid);
            case 'completion':
                return $this->validate_completion_requirement($step, $userid);
            default:
                return null;
        }
    }

    /**
     * Enforce the "submitted quiz attempt" requirement type. This restriction is
     * fixed and not customisable: no attempt currently in progress, and at least
     * one finished (submitted) attempt.
     *
     * The reason code drives the localised message in {@see outcome::format()}:
     *
     * - misconfigured:  requirement enabled but no quiz picked.
     * - missingactivity: the configured quiz cmid no longer resolves (deleted).
     * - inprogress:     the student still has an attempt in progress / overdue.
     * - nosubmission:   no finished (submitted) attempt for the student.
     *
     * @param stdClass $step The step record (must include requirementcmid).
     * @param int $userid The student user id.
     * @return array|null Requirementnotmet result, or null when the requirement passes.
     */
    protected function validate_quiz_requirement(stdClass $step, int $userid): ?array {
        global $DB;

        if (empty($step->requirementcmid)) {
            return [
                'status' => 'requirementnotmet',
                'reason' => 'misconfigured',
                'user'   => self::user_label($userid),
            ];
        }

        // Resolve only quizzes that belong to this examcheck's course. A cross-course cmid
        // saved via a tampered form submission would otherwise be honoured; here we treat
        // it as "missing" so the gate reports a misconfiguration rather than silently
        // depending on a quiz from another course.
        $cm = get_coursemodule_from_id(
            'quiz',
            (int) $step->requirementcmid,
            (int) $this->examcheck->course,
            false,
            IGNORE_MISSING
        );
        if (!$cm) {
            return [
                'status' => 'requirementnotmet',
                'reason' => 'missingactivity',
                'user'   => self::user_label($userid),
            ];
        }

        // Count real (non-preview) attempts by state.
        $states = $DB->get_records_menu(
            'quiz_attempts',
            ['quiz' => (int) $cm->instance, 'userid' => $userid, 'preview' => 0],
            '',
            'id, state'
        );
        $inprogress = 0;
        $submitted  = 0;
        foreach ($states as $state) {
            if ($state === 'inprogress' || $state === 'overdue') {
                $inprogress++;
            } else if ($state === 'finished') {
                $submitted++;
            }
            // Abandoned attempts intentionally count for neither bucket.
        }

        $quizname = format_string($cm->name, true, ['context' => $this->context]);
        if ($inprogress > 0) {
            return [
                'status' => 'requirementnotmet',
                'reason' => 'inprogress',
                'user'   => self::user_label($userid),
                'quiz'   => $quizname,
            ];
        }
        if ($submitted === 0) {
            return [
                'status' => 'requirementnotmet',
                'reason' => 'nosubmission',
                'user'   => self::user_label($userid),
                'quiz'   => $quizname,
            ];
        }
        return null;
    }

    /**
     * Enforce the "activity completion" requirement type: the student being
     * checked must have completion recorded for the chosen activity.
     *
     * Completion is looked up for the student passed in $userid — the one about
     * to be checked — never the invigilator performing the check. Passing 0 to
     * completion_info::get_data() would silently default to the current $USER,
     * so $userid must always be supplied explicitly here.
     *
     * @param stdClass $step The step record (must include requirementcmid).
     * @param int $userid The student user id being checked.
     * @return array|null Requirementnotmet result, or null when the requirement passes.
     */
    protected function validate_completion_requirement(stdClass $step, int $userid): ?array {
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');

        if (empty($step->requirementcmid)) {
            return [
                'status' => 'requirementnotmet',
                'reason' => 'misconfigured',
                'user'   => self::user_label($userid),
            ];
        }

        // Cross-course guard, same rationale as the quiz requirement above: any
        // activity type is allowed, so pass null for modname to accept them all.
        $cm = get_coursemodule_from_id(
            null,
            (int) $step->requirementcmid,
            (int) $this->examcheck->course,
            false,
            IGNORE_MISSING
        );
        if (!$cm) {
            return [
                'status' => 'requirementnotmet',
                'reason' => 'missingactivity',
                'user'   => self::user_label($userid),
            ];
        }

        $course = get_course($this->examcheck->course);
        $completion = new \completion_info($course);
        if ($completion->is_enabled($cm) == COMPLETION_TRACKING_NONE) {
            return [
                'status' => 'requirementnotmet',
                'reason' => 'nocompletion',
                'user'   => self::user_label($userid),
            ];
        }

        // Explicit $userid: this must be the student being checked, not whoever is
        // currently logged in and performing the check.
        $data = $completion->get_data($cm, false, $userid);
        if ((int) $data->completionstate === COMPLETION_INCOMPLETE) {
            return [
                'status'   => 'requirementnotmet',
                'reason'   => 'incomplete',
                'user'     => self::user_label($userid),
                'activity' => format_string($cm->name, true, ['context' => $this->context]),
            ];
        }

        return null;
    }

    /**
     * Build a structured conflict result for an existing mark.
     *
     * @param stdClass $mark The existing mark.
     * @param int $userid The student user id.
     * @return array
     */
    protected function conflict_result(stdClass $mark, int $userid): array {
        return [
            'status'    => 'conflict',
            'mark'      => $mark,
            'user'      => self::user_label($userid),
            'by'        => self::user_label((int) $mark->checkedby),
            'ago'       => self::relative_time((int) $mark->timecreated),
            'timestamp' => (int) $mark->timecreated,
        ];
    }

    /**
     * Ensure a step exists and belongs to this instance.
     *
     * @param int $stepid The step id.
     * @return stdClass The step record.
     * @throws moodle_exception When the step does not belong to this instance.
     */
    protected function require_step(int $stepid): stdClass {
        global $DB;
        return $DB->get_record(
            'examcheck_steps',
            ['id' => $stepid, 'examcheckid' => $this->examcheck->id],
            '*',
            MUST_EXIST
        );
    }

    /**
     * Human readable full name for a user id (cached per request by core).
     *
     * @param int $userid The user id.
     * @return string
     */
    public static function user_label(int $userid): string {
        $user = \core_user::get_user($userid, '*', IGNORE_MISSING);
        return $user ? fullname($user) : (string) $userid;
    }

    /**
     * Render a timestamp as a short "… ago" phrase relative to now.
     *
     * @param int $timestamp The time the event happened.
     * @return string
     */
    public static function relative_time(int $timestamp): string {
        $diff = time() - $timestamp;
        if ($diff <= 1) {
            return get_string('justnow', 'mod_examcheck');
        }
        if ($diff < 60) {
            return get_string('agoseconds', 'mod_examcheck', $diff);
        }
        return get_string('ago', 'mod_examcheck', format_time($diff));
    }
}
