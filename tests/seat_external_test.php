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
use mod_examcheck\external\assign_seat;
use mod_examcheck\external\search_seat_candidates;
use mod_examcheck\external\unassign_seat;
use mod_examcheck\local\seats;

/**
 * Tests for the seat assignment web services.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\external\assign_seat
 * @covers     \mod_examcheck\external\unassign_seat
 * @covers     \mod_examcheck\external\search_seat_candidates
 * @covers     \mod_examcheck\local\seat_outcome
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class seat_external_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected $course;

    /** @var \stdClass The examcheck module stub. */
    protected $examcheck;

    /** @var \stdClass The student. */
    protected $student;

    /** @var \stdClass The teacher. */
    protected $teacher;

    /** @var int[] Seat ids in sortorder. */
    protected $seatids;

    /**
     * Set up a course with two seats, one student and an editing teacher.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $this->course->id]);
        $this->student = $this->getDataGenerator()->create_and_enrol($this->course, 'student', ['idnumber' => 'EX1']);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');

        seats::replace_list($this->examcheck->id, ['A1', 'A2']);
        $this->seatids = array_map('intval', array_keys(seats::get_seats($this->examcheck->id)));

        $this->setUser($this->teacher);
    }

    /**
     * assign_seat returns a validated "assigned" outcome.
     */
    public function test_assign_seat(): void {
        $result = assign_seat::execute($this->examcheck->cmid, $this->seatids[0], $this->student->id);
        $result = external_api::clean_returnvalue(assign_seat::execute_returns(), $result);

        $this->assertSame('assigned', $result['status']);
        $this->assertSame((int) $this->student->id, $result['userid']);
        $this->assertSame(fullname($this->student), $result['userlabel']);
        $this->assertNotEmpty($result['message']);
    }

    /**
     * Assigning a student already seated elsewhere yields a conflict naming
     * their current seat, and leaves them where they were.
     */
    public function test_assign_seat_conflict(): void {
        assign_seat::execute($this->examcheck->cmid, $this->seatids[0], $this->student->id);

        $result = assign_seat::execute($this->examcheck->cmid, $this->seatids[1], $this->student->id);
        $result = external_api::clean_returnvalue(assign_seat::execute_returns(), $result);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('A1', $result['conflictseatlabel']);
        $this->assertStringContainsString('A1', $result['message']);
        $labels = seats::get_user_seat_labels($this->examcheck->id);
        $this->assertSame('A1', $labels[(int) $this->student->id]);
    }

    /**
     * Assigning over the seat's occupant replaces them.
     */
    public function test_assign_seat_replaces_occupant(): void {
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        assign_seat::execute($this->examcheck->cmid, $this->seatids[0], $this->student->id);

        $result = assign_seat::execute($this->examcheck->cmid, $this->seatids[0], $other->id);
        $result = external_api::clean_returnvalue(assign_seat::execute_returns(), $result);

        $this->assertSame('assigned', $result['status']);
        $labels = seats::get_user_seat_labels($this->examcheck->id);
        $this->assertSame('A1', $labels[(int) $other->id]);
        $this->assertArrayNotHasKey((int) $this->student->id, $labels);
    }

    /**
     * A seat id from another instance is rejected.
     */
    public function test_assign_seat_rejects_foreign_seat(): void {
        $otherinstance = $this->getDataGenerator()->create_module('examcheck', ['course' => $this->course->id]);
        seats::replace_list($otherinstance->id, ['Z1']);
        $foreignseat = (int) array_key_first(seats::get_seats($otherinstance->id));

        $this->expectException(\dml_missing_record_exception::class);
        assign_seat::execute($this->examcheck->cmid, $foreignseat, $this->student->id);
    }

    /**
     * A user who is not on the roster (e.g. another teacher) is rejected.
     */
    public function test_assign_seat_rejects_non_roster_user(): void {
        $this->expectException(\moodle_exception::class);
        assign_seat::execute($this->examcheck->cmid, $this->seatids[0], $this->teacher->id);
    }

    /**
     * The services demand the manageseats capability.
     */
    public function test_capability_required(): void {
        // A non-editing teacher can view and check, but not manage seats.
        $viewer = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($viewer);

        $this->expectException(\required_capability_exception::class);
        assign_seat::execute($this->examcheck->cmid, $this->seatids[0], $this->student->id);
    }

    /**
     * The search service demands the manageseats capability too.
     */
    public function test_search_capability_required(): void {
        $viewer = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($viewer);

        $this->expectException(\required_capability_exception::class);
        search_seat_candidates::execute($this->examcheck->cmid, '');
    }

    /**
     * Every seat service refuses when the seats feature is disabled for the activity.
     */
    public function test_services_refuse_when_seats_disabled(): void {
        $disabled = $this->getDataGenerator()->create_module(
            'examcheck',
            ['course' => $this->course->id, 'enableseats' => 0]
        );
        seats::replace_list($disabled->id, ['D1']);
        $seatid = (int) array_key_first(seats::get_seats($disabled->id));

        $calls = [
            fn() => assign_seat::execute($disabled->cmid, $seatid, $this->student->id),
            fn() => unassign_seat::execute($disabled->cmid, $seatid),
            fn() => search_seat_candidates::execute($disabled->cmid, ''),
        ];
        foreach ($calls as $call) {
            try {
                $call();
                $this->fail('Expected the seatsdisabled moodle_exception.');
            } catch (\moodle_exception $e) {
                $this->assertSame('seatsdisabled', $e->errorcode);
            }
        }
    }

    /**
     * unassign_seat clears the assignment; a second call reports notassigned.
     */
    public function test_unassign_seat(): void {
        assign_seat::execute($this->examcheck->cmid, $this->seatids[0], $this->student->id);

        $result = unassign_seat::execute($this->examcheck->cmid, $this->seatids[0]);
        $result = external_api::clean_returnvalue(unassign_seat::execute_returns(), $result);
        $this->assertSame('unassigned', $result['status']);
        $this->assertSame((int) $this->student->id, $result['userid']);

        $result = unassign_seat::execute($this->examcheck->cmid, $this->seatids[0]);
        $result = external_api::clean_returnvalue(unassign_seat::execute_returns(), $result);
        $this->assertSame('notassigned', $result['status']);
    }

    /**
     * The search returns the roster minus assigned students, honours the query,
     * and never lists checkers (teachers).
     */
    public function test_search_seat_candidates(): void {
        $other = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['firstname' => 'Zeb', 'lastname' => 'Zother']
        );
        assign_seat::execute($this->examcheck->cmid, $this->seatids[0], $this->student->id);

        $result = search_seat_candidates::execute($this->examcheck->cmid, '');
        $result = external_api::clean_returnvalue(search_seat_candidates::execute_returns(), $result);

        $ids = array_column($result['list'], 'id');
        $this->assertContains((int) $other->id, $ids);
        $this->assertNotContains((int) $this->student->id, $ids); // Already seated.
        $this->assertNotContains((int) $this->teacher->id, $ids); // A checker, not a student.
        $this->assertFalse($result['overflow']);

        // The query narrows by name, case-insensitively.
        $result = search_seat_candidates::execute($this->examcheck->cmid, 'zoth');
        $result = external_api::clean_returnvalue(search_seat_candidates::execute_returns(), $result);
        $this->assertSame([(int) $other->id], array_column($result['list'], 'id'));
    }

    /**
     * The candidate label carries an identity value only when the viewer may
     * see identity fields.
     */
    public function test_search_labels_respect_identity_visibility(): void {
        global $CFG;
        $CFG->showuseridentity = 'idnumber';

        $result = search_seat_candidates::execute($this->examcheck->cmid, '');
        $result = external_api::clean_returnvalue(search_seat_candidates::execute_returns(), $result);
        $this->assertStringContainsString('EX1', $result['list'][0]['label']);

        // Without the viewuseridentity capability the label is the bare name.
        // The role needs :view too — cm_info hides the activity from anyone
        // without the module's view capability.
        $roleid = create_role('Restricted teacher', 'restrictedteacher', '');
        assign_capability(
            'mod/examcheck:view',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id
        );
        assign_capability(
            'mod/examcheck:manageseats',
            CAP_ALLOW,
            $roleid,
            \context_system::instance()->id
        );
        $restricted = $this->getDataGenerator()->create_and_enrol($this->course, 'restrictedteacher');
        $this->setUser($restricted);

        $result = search_seat_candidates::execute($this->examcheck->cmid, '');
        $result = external_api::clean_returnvalue(search_seat_candidates::execute_returns(), $result);
        foreach ($result['list'] as $candidate) {
            $this->assertStringNotContainsString('EX1', $candidate['label']);
        }
    }

    /**
     * Under separate groups, a group-restricted teacher searching candidates is
     * confined to their own group and the call never throws.
     */
    public function test_search_respects_separate_groups(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $examcheck = $generator->create_module('examcheck', ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        seats::replace_list($examcheck->id, ['A1']);

        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $ina = $generator->create_and_enrol($course, 'student', ['firstname' => 'Ann', 'lastname' => 'Ingroup']);
        $inb = $generator->create_and_enrol($course, 'student', ['firstname' => 'Bob', 'lastname' => 'Outgroup']);
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        groups_add_member($groupa, $ina);
        groups_add_member($groupb, $inb);
        groups_add_member($groupa, $teacher);

        // Editing teachers normally hold accessallgroups: withdraw it to model a
        // genuinely group-restricted seat manager.
        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(
            'moodle/site:accessallgroups',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id
        );
        role_assign($roleid, $teacher->id, \context_course::instance($course->id)->id);

        $this->setUser($teacher);

        $result = search_seat_candidates::execute($examcheck->cmid, '');
        $result = external_api::clean_returnvalue(search_seat_candidates::execute_returns(), $result);

        $ids = array_column($result['list'], 'id');
        $this->assertContains((int) $ina->id, $ids);
        $this->assertNotContains((int) $inb->id, $ids);
    }
}
