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

use mod_examcheck\local\seats;

/**
 * Tests for seat management and assignment.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\local\seats
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class seats_test extends \advanced_testcase {
    /** @var \stdClass The examcheck instance. */
    protected $examcheck;

    /** @var \stdClass The student. */
    protected $student;

    /** @var \stdClass The teacher. */
    protected $teacher;

    /**
     * Create a course, instance, student and teacher.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
    }

    /**
     * Seats are stored in the given order, trimmed, with empty lines dropped.
     */
    public function test_replace_list_and_order(): void {
        seats::replace_list($this->examcheck->id, ['  A1 ', '', 'A2', '   ', 'B1']);

        $labels = array_map(fn($s) => $s->label, array_values(seats::get_seats($this->examcheck->id)));
        $this->assertSame(['A1', 'A2', 'B1'], $labels);

        $orders = array_map(fn($s) => (int) $s->sortorder, array_values(seats::get_seats($this->examcheck->id)));
        $this->assertSame([0, 1, 2], $orders);
        $this->assertSame(3, seats::count_seats($this->examcheck->id));
    }

    /**
     * A label longer than 100 characters is rejected.
     */
    public function test_replace_list_rejects_overlong_label(): void {
        $this->expectException(\moodle_exception::class);
        seats::replace_list($this->examcheck->id, [str_repeat('x', 101)]);
    }

    /**
     * Case-insensitive duplicate labels are rejected.
     */
    public function test_replace_list_rejects_duplicates(): void {
        $this->expectException(\moodle_exception::class);
        seats::replace_list($this->examcheck->id, ['A1', 'a1']);
    }

    /**
     * Editing the seat list resets every assignment, but re-saving an identical
     * list is a no-op that keeps the assignments intact.
     */
    public function test_replace_list_resets_assignments_unless_unchanged(): void {
        seats::replace_list($this->examcheck->id, ['A1', 'A2']);
        $seatid = (int) array_values(seats::get_seats($this->examcheck->id))[0]->id;
        seats::assign($seatid, (int) $this->student->id, (int) $this->teacher->id);
        $this->assertSame(1, seats::count_assignments($this->examcheck->id));

        // Identical list (same labels, same order): nothing is destroyed.
        seats::replace_list($this->examcheck->id, ['A1', 'A2']);
        $this->assertSame(1, seats::count_assignments($this->examcheck->id));
        $this->assertSame($seatid, (int) array_values(seats::get_seats($this->examcheck->id))[0]->id);

        // A real change resets all assignments and fires an unassign event each.
        $sink = $this->redirectEvents();
        seats::replace_list($this->examcheck->id, ['A1', 'A2', 'A3']);
        $events = array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \mod_examcheck\event\seat_unassigned
        );
        $sink->close();

        $this->assertSame(0, seats::count_assignments($this->examcheck->id));
        $this->assertCount(1, $events);
        $this->assertSame(3, seats::count_seats($this->examcheck->id));
    }

    /**
     * Assigning a free seat succeeds, fires an event, and is idempotent for the
     * same student on the same seat.
     */
    public function test_assign(): void {
        seats::replace_list($this->examcheck->id, ['A1']);
        $seatid = (int) array_values(seats::get_seats($this->examcheck->id))[0]->id;

        $sink = $this->redirectEvents();
        $result = seats::assign($seatid, (int) $this->student->id, (int) $this->teacher->id);
        $events = array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \mod_examcheck\event\seat_assigned
        );
        $sink->close();

        $this->assertSame('assigned', $result['status']);
        $this->assertSame('A1', $result['seatlabel']);
        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertEquals((int) $this->student->id, $event->relateduserid);
        $this->assertSame('A1', $event->other['seatlabel']);

        // Same student, same seat: idempotent success, no duplicate row.
        $again = seats::assign($seatid, (int) $this->student->id, (int) $this->teacher->id);
        $this->assertSame('assigned', $again['status']);
        $this->assertSame(1, seats::count_assignments($this->examcheck->id));
    }

    /**
     * Assigning over a seat's occupant replaces them: the old assignment is
     * removed (with its event) before the new one is recorded.
     */
    public function test_assign_replaces_occupant(): void {
        seats::replace_list($this->examcheck->id, ['A1']);
        $seatid = (int) array_values(seats::get_seats($this->examcheck->id))[0]->id;
        $other = $this->getDataGenerator()->create_and_enrol(
            get_course($this->examcheck->course),
            'student'
        );
        seats::assign($seatid, (int) $this->student->id, (int) $this->teacher->id);

        $sink = $this->redirectEvents();
        $result = seats::assign($seatid, (int) $other->id, (int) $this->teacher->id);
        $unassigned = array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \mod_examcheck\event\seat_unassigned
        );
        $sink->close();

        $this->assertSame('assigned', $result['status']);
        $this->assertCount(1, $unassigned);
        $labels = seats::get_user_seat_labels($this->examcheck->id);
        $this->assertArrayNotHasKey((int) $this->student->id, $labels);
        $this->assertSame('A1', $labels[(int) $other->id]);
    }

    /**
     * A student already sitting elsewhere yields a conflict describing their
     * current seat, who assigned them and when — they are never silently moved.
     */
    public function test_assign_conflict_when_user_seated_elsewhere(): void {
        seats::replace_list($this->examcheck->id, ['A1', 'A2']);
        $seatlist = array_values(seats::get_seats($this->examcheck->id));
        seats::assign((int) $seatlist[0]->id, (int) $this->student->id, (int) $this->teacher->id);

        $result = seats::assign((int) $seatlist[1]->id, (int) $this->student->id, (int) $this->teacher->id);

        $this->assertSame('conflict', $result['status']);
        $this->assertSame('A1', $result['seatlabel']);
        $this->assertSame(fullname($this->teacher), $result['by']);
        $this->assertNotEmpty($result['ago']);
        // The student is still on their original seat.
        $labels = seats::get_user_seat_labels($this->examcheck->id);
        $this->assertSame('A1', $labels[(int) $this->student->id]);
    }

    /**
     * Unassigning clears the seat (with an event); an empty seat reports notassigned.
     */
    public function test_unassign(): void {
        seats::replace_list($this->examcheck->id, ['A1']);
        $seatid = (int) array_values(seats::get_seats($this->examcheck->id))[0]->id;
        seats::assign($seatid, (int) $this->student->id, (int) $this->teacher->id);

        $sink = $this->redirectEvents();
        $result = seats::unassign($seatid);
        $events = array_filter(
            $sink->get_events(),
            fn($event) => $event instanceof \mod_examcheck\event\seat_unassigned
        );
        $sink->close();

        $this->assertSame('unassigned', $result['status']);
        $this->assertEquals((int) $this->student->id, $result['userid']);
        $this->assertCount(1, $events);
        $this->assertSame(0, seats::count_assignments($this->examcheck->id));

        $this->assertSame('notassigned', seats::unassign($seatid)['status']);
    }

    /**
     * get_user_seat_labels maps every assigned student to their label in one lookup.
     */
    public function test_get_user_seat_labels(): void {
        seats::replace_list($this->examcheck->id, ['A1', 'A2']);
        $seatlist = array_values(seats::get_seats($this->examcheck->id));
        $other = $this->getDataGenerator()->create_and_enrol(
            get_course($this->examcheck->course),
            'student'
        );
        seats::assign((int) $seatlist[0]->id, (int) $this->student->id, (int) $this->teacher->id);
        seats::assign((int) $seatlist[1]->id, (int) $other->id, (int) $this->teacher->id);

        $labels = seats::get_user_seat_labels($this->examcheck->id);
        $this->assertSame('A1', $labels[(int) $this->student->id]);
        $this->assertSame('A2', $labels[(int) $other->id]);
        $this->assertCount(2, $labels);
    }

    /**
     * Deleting the instance removes its seats and assignments.
     */
    public function test_delete_instance_cascades(): void {
        global $DB;

        seats::replace_list($this->examcheck->id, ['A1']);
        $seatid = (int) array_values(seats::get_seats($this->examcheck->id))[0]->id;
        seats::assign($seatid, (int) $this->student->id, (int) $this->teacher->id);

        examcheck_delete_instance($this->examcheck->id);

        $this->assertSame(0, $DB->count_records('examcheck_seats', ['examcheckid' => $this->examcheck->id]));
        $this->assertSame(0, $DB->count_records('examcheck_seat_users', ['examcheckid' => $this->examcheck->id]));
    }

    /**
     * The generator helpers create seats and assignments through the API.
     */
    public function test_generator_helpers(): void {
        /** @var \mod_examcheck_generator $generator */
        $generator = $this->getDataGenerator()->get_plugin_generator('mod_examcheck');
        $generator->set_seats((int) $this->examcheck->id, ['A1', 'A2']);
        $seatid = (int) array_values(seats::get_seats($this->examcheck->id))[0]->id;

        $result = $generator->assign_seat($seatid, (int) $this->student->id, (int) $this->teacher->id);

        $this->assertSame('assigned', $result['status']);
        $this->assertSame(2, seats::count_seats($this->examcheck->id));
        $this->assertSame(1, seats::count_assignments($this->examcheck->id));
    }
}
