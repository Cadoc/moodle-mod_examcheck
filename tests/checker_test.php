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

namespace mod_examcheck;

use mod_examcheck\local\checker;
use mod_examcheck\local\steps;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for the checker business logic.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\local\checker
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class checker_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected $course;
    /** @var \stdClass The examcheck instance. */
    protected $examcheck;
    /** @var \context_module The module context. */
    protected $context;
    /** @var \stdClass[] Created students. */
    protected $students = [];
    /** @var \stdClass The editing teacher. */
    protected $teacher;
    /** @var int The single seeded step id. */
    protected $stepid;

    /**
     * Build a course with an examcheck instance, students and a teacher.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $gen = $this->getDataGenerator();
        $this->course = $gen->create_course();
        $this->examcheck = $gen->create_module('examcheck', ['course' => $this->course->id]);
        $this->context = \context_module::instance($this->examcheck->cmid);

        for ($i = 1; $i <= 3; $i++) {
            $user = $gen->create_user(['idnumber' => 'S' . $i, 'firstname' => 'Stu', 'lastname' => 'Dent' . $i]);
            $gen->enrol_user($user->id, $this->course->id, 'student');
            $this->students[$i] = $user;
        }

        $this->teacher = $gen->create_user();
        $gen->enrol_user($this->teacher->id, $this->course->id, 'editingteacher');

        $steplist = array_values(steps::get_steps($this->examcheck->id));
        $this->stepid = (int) $steplist[0]->id;
    }

    /**
     * A fresh instance seeds exactly one step.
     */
    public function test_default_step_seeded(): void {
        $steps = steps::get_steps($this->examcheck->id);
        $this->assertCount(1, $steps);
        $first = reset($steps);
        $this->assertSame(get_string('defaultstepname', 'mod_examcheck'), $first->name);
    }

    /**
     * The roster contains students only and respects group filtering.
     */
    public function test_roster_excludes_teachers_and_filters_groups(): void {
        $checker = new checker($this->examcheck, $this->context);

        $roster = $checker->get_roster();
        $this->assertCount(3, $roster);
        $this->assertArrayNotHasKey($this->teacher->id, $roster);

        // Put one student in a group and filter by it.
        $group = $this->getDataGenerator()->create_group(['courseid' => $this->course->id]);
        $this->getDataGenerator()->create_group_member(['groupid' => $group->id, 'userid' => $this->students[1]->id]);

        $filtered = $checker->get_roster($group->id);
        $this->assertCount(1, $filtered);
        $this->assertArrayHasKey($this->students[1]->id, $filtered);
    }

    /**
     * Marking creates a shared record; a second mark reports a conflict.
     */
    public function test_mark_and_conflict(): void {
        $checker = new checker($this->examcheck, $this->context);

        $first = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id, 'list');
        $this->assertSame('marked', $first['status']);
        $this->assertEquals(1, $this->countmarks());

        // A different teacher marking the same student gets a conflict, not a duplicate.
        $other = $this->getDataGenerator()->create_user();
        $second = $checker->mark_user($this->stepid, $this->students[1]->id, $other->id, 'list');
        $this->assertSame('conflict', $second['status']);
        $this->assertEquals($this->teacher->id, $second['mark']->checkedby);
        $this->assertEquals(1, $this->countmarks());
    }

    /**
     * Marking refuses students who are not on the roster.
     */
    public function test_mark_rejects_non_roster_user(): void {
        $checker = new checker($this->examcheck, $this->context);
        $stranger = $this->getDataGenerator()->create_user();

        $result = $checker->mark_user($this->stepid, $stranger->id, $this->teacher->id);
        $this->assertSame('notinroster', $result['status']);
        $this->assertEquals(0, $this->countmarks());
    }

    /**
     * A teacher can remove their own mark.
     */
    public function test_unmark_own(): void {
        $this->setUser($this->teacher);
        $checker = new checker($this->examcheck, $this->context);

        $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);
        $result = $checker->unmark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('unmarked', $result['status']);
        $this->assertEquals(0, $this->countmarks());
    }

    /**
     * Removing another teacher's mark needs the override capability.
     */
    public function test_unmark_other_requires_override(): void {
        $gen = $this->getDataGenerator();
        $checker = new checker($this->examcheck, $this->context);
        $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        // A non-editing teacher has check but not override.
        $assistant = $gen->create_user();
        $gen->enrol_user($assistant->id, $this->course->id, 'teacher');
        $this->setUser($assistant);

        $this->expectException(\moodle_exception::class);
        $checker->unmark_user($this->stepid, $this->students[1]->id, $assistant->id);
    }

    /**
     * Scanning by ID number marks the matching student.
     */
    public function test_scan_by_idnumber_marks(): void {
        $checker = new checker($this->examcheck, $this->context);

        $result = $checker->scan($this->stepid, 'idnumber', 'S2', false, false, $this->teacher->id);
        $this->assertSame('marked', $result['status']);
        $this->assertEquals($this->students[2]->id, $result['mark']->userid);
    }

    /**
     * Scanning with an extraction regex matches an embedded student number.
     */
    public function test_scan_with_regex(): void {
        $checker = new checker($this->examcheck, $this->context);

        // The card encodes extra data around the ID number "S3".
        $payload = 'CARD;ID=S3;ISSUED=2026';
        $result = $checker->scan(
            $this->stepid,
            'idnumber',
            $payload,
            false,
            false,
            $this->teacher->id,
            0,
            'ID=(S\d+)'
        );

        $this->assertSame('marked', $result['status']);
        $this->assertEquals($this->students[3]->id, $result['mark']->userid);
    }

    /**
     * A regex that does not match the scanned value reports "not found".
     */
    public function test_scan_regex_no_match(): void {
        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->scan(
            $this->stepid,
            'idnumber',
            'S1',
            false,
            false,
            $this->teacher->id,
            0,
            'ID=(S\d+)'
        );
        $this->assertSame('notfound', $result['status']);
    }

    /**
     * Scanning an unknown value reports "not found".
     */
    public function test_scan_not_found(): void {
        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->scan($this->stepid, 'idnumber', 'NOPE', false, false, $this->teacher->id);
        $this->assertSame('notfound', $result['status']);
    }

    /**
     * Scanning a value that matches a real account not enrolled in the course
     * is reported distinctly from an unknown value.
     */
    public function test_scan_matches_unenrolled_user(): void {
        $checker = new checker($this->examcheck, $this->context);
        $this->getDataGenerator()->create_user(['idnumber' => 'OUTSIDER']);

        $result = $checker->scan($this->stepid, 'idnumber', 'OUTSIDER', false, false, $this->teacher->id);
        $this->assertSame('notenrolled', $result['status']);
        $this->assertEquals(0, $this->countmarks());
    }

    /**
     * With confirmation required, a scan pauses for confirmation before marking.
     */
    public function test_scan_needs_confirm_then_marks(): void {
        $checker = new checker($this->examcheck, $this->context);

        $pending = $checker->scan($this->stepid, 'idnumber', 'S1', false, true, $this->teacher->id);
        $this->assertSame('needsconfirm', $pending['status']);
        $this->assertEquals($this->students[1]->id, $pending['userid']);
        $this->assertEquals(0, $this->countmarks());

        $confirmed = $checker->scan($this->stepid, 'idnumber', 'S1', true, true, $this->teacher->id);
        $this->assertSame('marked', $confirmed['status']);
        $this->assertEquals(1, $this->countmarks());
    }

    /**
     * Reading mode: lookup() resolves a scanned value to a student and reports
     * their status on every step, without recording a mark.
     */
    public function test_lookup_returns_step_statuses_without_marking(): void {
        $checker = new checker($this->examcheck, $this->context);
        $checker->mark_user($this->stepid, $this->students[2]->id, $this->teacher->id);

        $result = $checker->lookup('idnumber', 'S2');

        $this->assertSame('found', $result['status']);
        $this->assertEquals($this->students[2]->id, $result['userid']);
        $this->assertCount(1, $result['steps']);
        $this->assertTrue($result['steps'][0]['checked']);
        // A fresh instance seeds one step, so marking it doesn't add another.
        $this->assertEquals(1, $this->countmarks());
    }

    /**
     * lookup() reports the correct (unchecked) status for a student not yet marked.
     */
    public function test_lookup_unchecked_student(): void {
        $checker = new checker($this->examcheck, $this->context);

        $result = $checker->lookup('idnumber', 'S1');

        $this->assertSame('found', $result['status']);
        $this->assertFalse($result['steps'][0]['checked']);
        $this->assertEquals(0, $this->countmarks());
    }

    /**
     * lookup() applies the extraction regex the same way scan() does.
     */
    public function test_lookup_with_regex(): void {
        $checker = new checker($this->examcheck, $this->context);

        $result = $checker->lookup('idnumber', 'CARD;ID=S3;ISSUED=2026', 0, 'ID=(S\d+)');

        $this->assertSame('found', $result['status']);
        $this->assertEquals($this->students[3]->id, $result['userid']);
    }

    /**
     * lookup() reports "not found" for an unknown value, same as scan().
     */
    public function test_lookup_not_found(): void {
        $checker = new checker($this->examcheck, $this->context);

        $result = $checker->lookup('idnumber', 'NOPE');

        $this->assertSame('notfound', $result['status']);
    }

    /**
     * lookup() reports "not enrolled" for a value that matches a real account
     * outside the course, distinctly from an unknown value.
     */
    public function test_lookup_matches_unenrolled_user(): void {
        $checker = new checker($this->examcheck, $this->context);
        $this->getDataGenerator()->create_user(['idnumber' => 'OUTSIDER']);

        $result = $checker->lookup('idnumber', 'OUTSIDER');

        $this->assertSame('notenrolled', $result['status']);
    }

    /**
     * Progress counts only checked roster members for the group.
     */
    public function test_progress(): void {
        $checker = new checker($this->examcheck, $this->context);
        $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);
        $checker->mark_user($this->stepid, $this->students[2]->id, $this->teacher->id);

        $progress = $checker->get_progress();
        $this->assertSame(2, $progress[$this->stepid]);
    }

    /**
     * Under separate groups, a teacher without accessallgroups may only act on
     * their own group; another group's id is rejected.
     */
    public function test_group_access_separate_groups(): void {
        global $DB;

        $gen = $this->getDataGenerator();
        $course = $gen->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $examcheck = $gen->create_module('examcheck', ['course' => $course->id]);
        $context = \context_module::instance($examcheck->cmid);
        $groupa = $gen->create_group(['courseid' => $course->id]);
        $groupb = $gen->create_group(['courseid' => $course->id]);

        $teacher = $gen->create_and_enrol($course, 'teacher');
        $gen->create_group_member(['groupid' => $groupa->id, 'userid' => $teacher->id]);

        // Remove the "access all groups" privilege for the teacher role here.
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'teacher'], MUST_EXIST);
        assign_capability('moodle/site:accessallgroups', CAP_PROHIBIT, $roleid, $context->id, true);
        accesslib_clear_all_caches_for_unit_testing();

        $this->setUser($teacher);
        $checker = new checker($DB->get_record('examcheck', ['id' => $examcheck->id], '*', MUST_EXIST), $context);

        // Their own group is allowed (no exception).
        $checker->require_group_access((int) $groupa->id);

        // Another group is rejected.
        $this->expectException(\required_capability_exception::class);
        $checker->require_group_access((int) $groupb->id);
    }

    /**
     * Marking passes when the step's quiz gate is satisfied by a finished attempt.
     */
    public function test_mark_passes_when_quiz_attempt_submitted(): void {
        $quiz = $this->configure_quiz_gate();
        $this->insert_quiz_attempt($quiz, $this->students[1]->id, 'finished');

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('marked', $result['status']);
        $this->assertEquals(1, $this->countmarks());
    }

    /**
     * Marking is blocked while the student still has an attempt in progress.
     */
    public function test_mark_blocked_when_attempt_inprogress(): void {
        $quiz = $this->configure_quiz_gate();
        $this->insert_quiz_attempt($quiz, $this->students[1]->id, 'finished');
        $this->insert_quiz_attempt($quiz, $this->students[1]->id, 'inprogress');

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertSame('inprogress', $result['reason']);
        $this->assertEquals(0, $this->countmarks());
    }

    /**
     * Marking is blocked when no attempt has been submitted at all.
     */
    public function test_mark_blocked_when_no_submitted_attempt(): void {
        $this->configure_quiz_gate();

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertSame('nosubmission', $result['reason']);
        $this->assertEquals(0, $this->countmarks());
    }

    /**
     * Marking is blocked when the linked quiz no longer exists.
     */
    public function test_mark_blocked_when_quiz_deleted(): void {
        $this->configure_quiz_gate();
        // Point the step at a cmid that does not exist.
        global $DB;
        $DB->set_field('examcheck_steps', 'requirementcmid', 99999999, ['id' => $this->stepid]);

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertSame('missingactivity', $result['reason']);
    }

    /**
     * Marking is blocked when the gate is on but no quiz was picked.
     */
    public function test_mark_blocked_when_misconfigured(): void {
        $this->configure_quiz_gate();
        global $DB;
        $DB->set_field('examcheck_steps', 'requirementcmid', null, ['id' => $this->stepid]);

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertSame('misconfigured', $result['reason']);
    }

    /**
     * Preview attempts must not satisfy the gate (they're never real submissions).
     */
    public function test_mark_ignores_preview_attempts(): void {
        $quiz = $this->configure_quiz_gate();
        $this->insert_quiz_attempt($quiz, $this->students[1]->id, 'finished', preview: 1);

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertSame('nosubmission', $result['reason']);
    }

    /**
     * The scanner fails fast before "needs confirm" when the gate refuses, so
     * the teacher never sees a confirm prompt for a student who will be blocked.
     */
    public function test_scan_fails_fast_before_needsconfirm(): void {
        $this->configure_quiz_gate(); // No attempt for student 1.

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->scan($this->stepid, 'idnumber', 'S1', false, true, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertEquals(0, $this->countmarks());
    }

    /**
     * Removing a mark must always work, even when the step has the gate on
     * (mistakes happen and unmarking should never be blocked by the gate).
     */
    public function test_unmark_unaffected_by_gate(): void {
        $quiz = $this->configure_quiz_gate();
        $this->insert_quiz_attempt($quiz, $this->students[1]->id, 'finished');

        $checker = new checker($this->examcheck, $this->context);
        $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);
        $this->assertEquals(1, $this->countmarks());

        // Now delete the submitted attempt so the gate would refuse a new mark…
        global $DB;
        $DB->delete_records('quiz_attempts', ['quiz' => $quiz->instance, 'userid' => $this->students[1]->id]);

        // …but unmarking still works.
        $result = $checker->unmark_user($this->stepid, $this->students[1]->id, $this->teacher->id);
        $this->assertSame('unmarked', $result['status']);
        $this->assertEquals(0, $this->countmarks());
    }

    /**
     * Marking passes once the target student has completion recorded on the
     * chosen activity.
     */
    public function test_mark_passes_when_activity_complete(): void {
        $page = $this->configure_completion_gate();
        $this->set_activity_completion($page, $this->students[1]->id, COMPLETION_COMPLETE);

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('marked', $result['status']);
        $this->assertEquals(1, $this->countmarks());
    }

    /**
     * Marking is blocked while the target student has not completed the activity.
     */
    public function test_mark_blocked_when_activity_incomplete(): void {
        $this->configure_completion_gate();

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertSame('incomplete', $result['reason']);
        $this->assertEquals(0, $this->countmarks());
    }

    /**
     * Marking is blocked when the linked activity no longer exists.
     */
    public function test_mark_blocked_when_completion_activity_deleted(): void {
        $this->configure_completion_gate();
        global $DB;
        $DB->set_field('examcheck_steps', 'requirementcmid', 99999999, ['id' => $this->stepid]);

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertSame('missingactivity', $result['reason']);
    }

    /**
     * Marking is blocked when the linked activity no longer tracks completion.
     */
    public function test_mark_blocked_when_completion_not_tracked(): void {
        $page = $this->configure_completion_gate();
        global $DB;
        $DB->set_field('course_modules', 'completion', COMPLETION_TRACKING_NONE, ['id' => $page->id]);
        rebuild_course_cache($this->course->id, true);

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertSame('nocompletion', $result['reason']);
    }

    /**
     * The activity-completion gate must check the STUDENT being marked, never the
     * invigilator performing the check. completion_info::get_data() silently falls
     * back to the current $USER when no userid is passed, so this guards against
     * that mistake creeping back in: the checking teacher "completed" the target
     * activity, the student did not, and the mark must still be refused.
     */
    public function test_completion_checks_student_not_invigilator(): void {
        $page = $this->configure_completion_gate();
        $this->set_activity_completion($page, $this->teacher->id, COMPLETION_COMPLETE);
        $this->setUser($this->teacher);

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertSame('incomplete', $result['reason']);
        $this->assertEquals(0, $this->countmarks());
    }

    /**
     * Marking a later step is blocked while the immediately preceding step has
     * not been checked for that student, when step-by-step completion is required.
     */
    public function test_mark_blocked_when_previous_step_not_checked(): void {
        $second = steps::add_step($this->examcheck->id, 'Identity');
        $this->enable_sequential();

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($second, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertSame('sequential', $result['reason']);
        $this->assertEquals(0, $this->countmarks());
    }

    /**
     * Marking a later step succeeds once the immediately preceding step is checked.
     */
    public function test_mark_passes_when_previous_step_checked(): void {
        $second = steps::add_step($this->examcheck->id, 'Identity');
        $this->enable_sequential();

        $checker = new checker($this->examcheck, $this->context);
        $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);
        $result = $checker->mark_user($second, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('marked', $result['status']);
        $this->assertEquals(2, $this->countmarks());
    }

    /**
     * The very first step has no predecessor, so it is never blocked by sequencing.
     */
    public function test_first_step_never_blocked_by_sequential(): void {
        $this->enable_sequential();

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('marked', $result['status']);
    }

    /**
     * With the setting off (the default), steps can still be checked in any order.
     */
    public function test_sequential_off_allows_any_order(): void {
        $second = steps::add_step($this->examcheck->id, 'Identity');

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($second, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('marked', $result['status']);
    }

    /**
     * When both the sequential gate and the step's own custom requirement are
     * unmet at once, the sequential failure takes priority: custom requirements
     * build on top of sequencing, not the other way round.
     */
    public function test_sequential_checked_before_custom_requirement(): void {
        $second = steps::add_step($this->examcheck->id, 'Identity');
        $this->enable_sequential();
        global $DB;
        $gen = $this->getDataGenerator();
        $quiz = $gen->create_module('quiz', ['course' => $this->course->id]);
        $DB->update_record('examcheck_steps', (object) [
            'id'              => $second,
            'requirementtype' => 'quiz',
            'requirementcmid' => (int) $quiz->cmid,
            'timemodified'    => time(),
        ]);
        // Neither the previous step nor the quiz requirement are satisfied.

        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->mark_user($second, $this->students[1]->id, $this->teacher->id);

        $this->assertSame('requirementnotmet', $result['status']);
        $this->assertSame('sequential', $result['reason']);
    }

    /**
     * Unmarking an earlier step does not retroactively revoke a later step's
     * existing mark: the gate only blocks future marking attempts.
     */
    public function test_unmark_previous_step_does_not_revoke_later_mark(): void {
        $second = steps::add_step($this->examcheck->id, 'Identity');
        $this->enable_sequential();

        $checker = new checker($this->examcheck, $this->context);
        $checker->mark_user($this->stepid, $this->students[1]->id, $this->teacher->id);
        $checker->mark_user($second, $this->students[1]->id, $this->teacher->id);
        $this->assertEquals(2, $this->countmarks());

        $checker->unmark_user($this->stepid, $this->students[1]->id, $this->teacher->id);

        $this->assertNotFalse($checker->get_mark($second, $this->students[1]->id));
        $this->assertEquals(1, $this->countmarks());
    }

    /**
     * Enable "require step-by-step completion" on the instance under test.
     */
    protected function enable_sequential(): void {
        global $DB;
        $DB->set_field('examcheck', 'requiresequential', 1, ['id' => $this->examcheck->id]);
        $this->examcheck->requiresequential = 1;
    }

    /**
     * Create a quiz in the course and wire the seeded step to require an attempt on it.
     *
     * @return \stdClass The quiz course-module record (with ->instance = quiz id, ->id = cmid).
     */
    protected function configure_quiz_gate(): \stdClass {
        $gen = $this->getDataGenerator();
        $quiz = $gen->create_module('quiz', ['course' => $this->course->id]);

        global $DB;
        $DB->update_record('examcheck_steps', (object) [
            'id'              => $this->stepid,
            'requirementtype' => 'quiz',
            'requirementcmid' => (int) $quiz->cmid,
            'timemodified'    => time(),
        ]);

        return (object) ['id' => (int) $quiz->cmid, 'instance' => (int) $quiz->id];
    }

    /**
     * Create a manually-completable page in the course and wire the seeded step to
     * require its completion.
     *
     * @return \cm_info The page course module.
     */
    protected function configure_completion_gate(): \cm_info {
        global $DB;
        $DB->set_field('course', 'enablecompletion', 1, ['id' => $this->course->id]);
        // Refresh the in-memory course used by completion_info elsewhere in this test.
        $this->course = $DB->get_record('course', ['id' => $this->course->id], '*', MUST_EXIST);

        $gen = $this->getDataGenerator();
        $page = $gen->create_module('page', [
            'course' => $this->course->id,
            'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        $DB->update_record('examcheck_steps', (object) [
            'id'              => $this->stepid,
            'requirementtype' => 'completion',
            'requirementcmid' => (int) $page->cmid,
            'timemodified'    => time(),
        ]);

        rebuild_course_cache($this->course->id, true);

        return get_fast_modinfo($this->course->id)->get_cm($page->cmid);
    }

    /**
     * Set a specific student's completion state for a course module.
     *
     * @param \cm_info $cm The course module.
     * @param int $userid The user whose completion state to set.
     * @param int $state One of the COMPLETION_* constants.
     */
    protected function set_activity_completion(\cm_info $cm, int $userid, int $state): void {
        $completion = new \completion_info($this->course);
        $completion->update_state($cm, $state, $userid);
    }

    /**
     * Insert a quiz_attempts row directly. The plugin only reads (state, preview) so a
     * minimal record is enough — we don't need to drive the quiz attempt state machine.
     *
     * @param \stdClass $quiz The result of configure_quiz_gate().
     * @param int $userid The student.
     * @param string $state One of 'finished', 'inprogress', 'overdue', 'abandoned'.
     * @param int $preview 0 for a real attempt, 1 for a teacher preview.
     */
    protected function insert_quiz_attempt(\stdClass $quiz, int $userid, string $state, int $preview = 0): void {
        global $DB;

        $now = time();
        // attempt is unique per (quiz, userid); uniqueid is unique across the whole table.
        $attemptno = $DB->count_records('quiz_attempts', ['quiz' => $quiz->instance, 'userid' => $userid]) + 1;
        $uniqueid = $DB->count_records('quiz_attempts') + 1;
        $DB->insert_record('quiz_attempts', (object) [
            'quiz'                => $quiz->instance,
            'userid'              => $userid,
            'attempt'             => $attemptno,
            'uniqueid'            => $uniqueid,
            'layout'              => '',
            'currentpage'         => 0,
            'preview'             => $preview,
            'state'               => $state,
            'timestart'           => $now - 60,
            'timefinish'          => $state === 'finished' ? $now : 0,
            'timemodified'        => $now,
            'timemodifiedoffline' => 0,
            'timecheckstate'      => null,
            'sumgrades'           => null,
            'gradednotificationsenttime' => null,
        ]);
    }

    /**
     * Count rows in examcheck_marks for the instance.
     *
     * @return int
     */
    protected function countmarks(): int {
        global $DB;
        return $DB->count_records('examcheck_marks', ['examcheckid' => $this->examcheck->id]);
    }
}
