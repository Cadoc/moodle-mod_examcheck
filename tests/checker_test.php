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
     * Scanning an unknown value reports "not found".
     */
    public function test_scan_not_found(): void {
        $checker = new checker($this->examcheck, $this->context);
        $result = $checker->scan($this->stepid, 'idnumber', 'NOPE', false, false, $this->teacher->id);
        $this->assertSame('notfound', $result['status']);
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
     * Count rows in examcheck_marks for the instance.
     *
     * @return int
     */
    protected function countmarks(): int {
        global $DB;
        return $DB->count_records('examcheck_marks', ['examcheckid' => $this->examcheck->id]);
    }
}
