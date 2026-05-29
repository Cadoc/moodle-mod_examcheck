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

use core_external\external_api;
use mod_examcheck\external\get_marks;
use mod_examcheck\external\mark_user;
use mod_examcheck\external\scan_lookup;
use mod_examcheck\external\unmark_user;
use mod_examcheck\local\steps;

/**
 * Tests for the external (AJAX) web services.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\external\mark_user
 * @covers     \mod_examcheck\external\unmark_user
 * @covers     \mod_examcheck\external\scan_lookup
 * @covers     \mod_examcheck\external\get_marks
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class external_test extends \advanced_testcase {
    /** @var \stdClass The examcheck module stub. */
    protected $examcheck;
    /** @var \stdClass The student. */
    protected $student;
    /** @var \stdClass The teacher. */
    protected $teacher;
    /** @var int The seeded step id. */
    protected $stepid;

    /**
     * Set up a course with one student and an editing teacher.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $this->examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student', ['idnumber' => 'EX1']);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $this->stepid = (int) array_values(steps::get_steps($this->examcheck->id))[0]->id;

        $this->setUser($this->teacher);
    }

    /**
     * mark_user returns a validated "marked" outcome.
     */
    public function test_mark_user(): void {
        $result = mark_user::execute($this->examcheck->cmid, $this->stepid, $this->student->id, 0, 'list');
        $result = external_api::clean_returnvalue(mark_user::execute_returns(), $result);

        $this->assertSame('marked', $result['status']);
        $this->assertSame((int) $this->student->id, $result['userid']);
        $this->assertNotEmpty($result['message']);
    }

    /**
     * A second teacher marking the same student gets a conflict outcome.
     */
    public function test_mark_user_conflict(): void {
        mark_user::execute($this->examcheck->cmid, $this->stepid, $this->student->id, 0, 'list');

        $other = $this->getDataGenerator()->create_and_enrol(
            get_course($this->examcheck->course),
            'editingteacher'
        );
        $this->setUser($other);

        $result = mark_user::execute($this->examcheck->cmid, $this->stepid, $this->student->id, 0, 'list');
        $result = external_api::clean_returnvalue(mark_user::execute_returns(), $result);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame((int) $this->teacher->id, $result['checkedby']);
        $this->assertNotEmpty($result['ago']);
    }

    /**
     * unmark_user removes the mark.
     */
    public function test_unmark_user(): void {
        mark_user::execute($this->examcheck->cmid, $this->stepid, $this->student->id, 0, 'list');
        $result = unmark_user::execute($this->examcheck->cmid, $this->stepid, $this->student->id);
        $result = external_api::clean_returnvalue(unmark_user::execute_returns(), $result);
        $this->assertSame('unmarked', $result['status']);
    }

    /**
     * scan_lookup resolves an ID number and marks the student.
     */
    public function test_scan_lookup(): void {
        $result = scan_lookup::execute($this->examcheck->cmid, $this->stepid, 'idnumber', 'EX1', false, false, 0);
        $result = external_api::clean_returnvalue(scan_lookup::execute_returns(), $result);
        $this->assertSame('marked', $result['status']);
        $this->assertSame((int) $this->student->id, $result['userid']);
    }

    /**
     * get_marks returns the current state and progress.
     */
    public function test_get_marks(): void {
        mark_user::execute($this->examcheck->cmid, $this->stepid, $this->student->id, 0, 'list');

        $result = get_marks::execute($this->examcheck->cmid, 0);
        $result = external_api::clean_returnvalue(get_marks::execute_returns(), $result);

        $this->assertCount(1, $result['marks']);
        $this->assertSame((int) $this->student->id, $result['marks'][0]['userid']);
        $this->assertSame(1, $result['total']);
        $stepprogress = array_column($result['progress'], 'count', 'stepid');
        $this->assertSame(1, $stepprogress[$this->stepid]);
    }

    /**
     * Without the check capability, marking is denied.
     */
    public function test_mark_user_requires_capability(): void {
        $student = $this->student;
        $this->setUser($student);
        $this->expectException(\required_capability_exception::class);
        mark_user::execute($this->examcheck->cmid, $this->stepid, $student->id, 0, 'list');
    }
}
