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
use mod_examcheck\external\auto_assign_seats;
use mod_examcheck\local\seats;

/**
 * Tests for the auto-assign seats web service.
 *
 * The pairing is random, so the assertions are invariants: how many were seated, which
 * seats were used, and that nobody the caller cannot reach is touched.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\external\auto_assign_seats
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class seat_auto_assign_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected $course;

    /** @var \stdClass The examcheck module stub. */
    protected $examcheck;

    /** @var \stdClass The teacher. */
    protected $teacher;

    /**
     * Create a course with an examcheck activity and an editing teacher.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $this->course->id]);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($this->teacher);
    }

    /**
     * With room to spare, every unseated student gets a seat and the counters report
     * a full house.
     */
    public function test_seats_every_student(): void {
        seats::replace_list($this->examcheck->id, ['A1', 'A2', 'A3']);
        $students = $this->students(2);

        $result = $this->call();

        $this->assertSame(2, $result['assigned']);
        $this->assertSame(0, $result['unseatedstudents']);
        $this->assertSame(1, $result['freeseats']);
        $this->assertSame(3, $result['seatcount']);
        $this->assertStringContainsString('2', $result['message']);

        $labels = seats::get_user_seat_labels($this->examcheck->id);
        $this->assertEqualsCanonicalizing(array_map('intval', array_keys($students)), array_keys($labels));
        // The free seats were taken from the top of the authored list.
        $this->assertEqualsCanonicalizing(['A1', 'A2'], array_values($labels));
    }

    /**
     * A student already seated keeps their seat and is not counted again.
     */
    public function test_leaves_seated_students_alone(): void {
        seats::replace_list($this->examcheck->id, ['A1', 'A2', 'A3']);
        $students = $this->students(3);
        $seatids = array_map('intval', array_keys(seats::get_seats($this->examcheck->id)));
        $first = (int) array_key_first($students);
        seats::assign($seatids[2], $first, (int) $this->teacher->id);

        $result = $this->call();

        $this->assertSame(2, $result['assigned']);
        $this->assertSame(0, $result['unseatedstudents']);
        $this->assertSame(0, $result['freeseats']);
        $this->assertSame('A3', seats::get_user_seat_labels($this->examcheck->id)[$first]);
    }

    /**
     * With fewer seats than students, every seat is filled and the shortfall is
     * reported so the page can say so.
     */
    public function test_reports_students_left_without_a_seat(): void {
        seats::replace_list($this->examcheck->id, ['A1']);
        $this->students(3);

        $result = $this->call();

        $this->assertSame(1, $result['assigned']);
        $this->assertSame(2, $result['unseatedstudents']);
        $this->assertSame(0, $result['freeseats']);
        $this->assertSame(1, seats::count_assignments($this->examcheck->id));
    }

    /**
     * Nothing to assign is a no-op, not an error.
     */
    public function test_no_free_seats(): void {
        seats::replace_list($this->examcheck->id, ['A1']);
        $students = $this->students(1);
        $seatid = (int) array_key_first(seats::get_seats($this->examcheck->id));
        seats::assign($seatid, (int) array_key_first($students), (int) $this->teacher->id);

        $result = $this->call();

        $this->assertSame(0, $result['assigned']);
        $this->assertSame(0, $result['unseatedstudents']);
        $this->assertSame(0, $result['freeseats']);
    }

    /**
     * Managing seats is required: viewing the activity is not enough.
     */
    public function test_requires_manageseats(): void {
        seats::replace_list($this->examcheck->id, ['A1']);
        $this->students(1);

        // A non-editing teacher may view the activity but not manage its seats.
        $this->setUser($this->getDataGenerator()->create_and_enrol($this->course, 'teacher'));

        $this->expectException(\required_capability_exception::class);
        auto_assign_seats::execute((int) $this->examcheck->cmid);
    }

    /**
     * With the feature switched off the service refuses, like its siblings.
     */
    public function test_rejects_when_seats_disabled(): void {
        $disabled = $this->getDataGenerator()->create_module(
            'examcheck',
            ['course' => $this->course->id, 'enableseats' => 0]
        );
        seats::replace_list($disabled->id, ['A1']);
        $this->students(1);

        try {
            auto_assign_seats::execute((int) $disabled->cmid);
            $this->fail('Expected a seatsdisabled exception.');
        } catch (\moodle_exception $e) {
            $this->assertSame('seatsdisabled', $e->errorcode);
        }
    }

    /**
     * Under separate groups, a group-restricted teacher only ever seats their own
     * students; the other group's students stay standing.
     */
    public function test_respects_separate_groups(): void {
        $generator = $this->getDataGenerator();
        $course = $generator->create_course(['groupmode' => SEPARATEGROUPS, 'groupmodeforce' => 1]);
        $examcheck = $generator->create_module('examcheck', ['course' => $course->id, 'groupmode' => SEPARATEGROUPS]);
        seats::replace_list($examcheck->id, ['A1', 'A2', 'A3', 'A4']);

        $groupa = $generator->create_group(['courseid' => $course->id]);
        $groupb = $generator->create_group(['courseid' => $course->id]);
        $ina = $generator->create_and_enrol($course, 'student');
        $inb = $generator->create_and_enrol($course, 'student');
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        groups_add_member($groupa, $ina);
        groups_add_member($groupb, $inb);
        groups_add_member($groupa, $teacher);

        // Editing teachers normally hold accessallgroups: withdraw it to model a
        // genuinely group-restricted seat manager.
        $roleid = $generator->create_role();
        assign_capability(
            'moodle/site:accessallgroups',
            CAP_PROHIBIT,
            $roleid,
            \context_course::instance($course->id)->id
        );
        role_assign($roleid, $teacher->id, \context_course::instance($course->id)->id);

        $this->setUser($teacher);

        $result = auto_assign_seats::execute((int) $examcheck->cmid);
        $result = external_api::clean_returnvalue(auto_assign_seats::execute_returns(), $result);

        $this->assertSame(1, $result['assigned']);
        $labels = seats::get_user_seat_labels($examcheck->id);
        $this->assertArrayHasKey((int) $ina->id, $labels);
        $this->assertArrayNotHasKey((int) $inb->id, $labels);
    }

    /**
     * Enrol students on the test course.
     *
     * @param int $count How many.
     * @return \stdClass[] Keyed by user id.
     */
    private function students(int $count): array {
        $students = [];
        for ($i = 0; $i < $count; $i++) {
            $user = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
            $students[(int) $user->id] = $user;
        }
        return $students;
    }

    /**
     * Call the service and validate its return shape.
     *
     * @return array The cleaned response.
     */
    private function call(): array {
        $result = auto_assign_seats::execute((int) $this->examcheck->cmid);
        return external_api::clean_returnvalue(auto_assign_seats::execute_returns(), $result);
    }
}
