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
use mod_examcheck\output\seat_assign_page;
use mod_examcheck\table\seat_assign;
use mod_examcheck\table\seat_assign_filterset;

/**
 * Tests that the seat assignment dynamic table renders and sorts server-side.
 *
 * Covers the rendering path the dynamic-table AJAX endpoint exercises (set_filterset,
 * query_db, has_capability and the cell methods); the autocomplete wiring and the
 * re-enhancement after an AJAX refresh are verified in the browser.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\table\seat_assign
 * @covers     \mod_examcheck\table\seat_assign_filterset
 * @covers     \mod_examcheck\output\seat_assign_page
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class seats_table_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected $course;

    /** @var \stdClass The examcheck module stub. */
    protected $examcheck;

    /** @var \stdClass The teacher recording the assignments. */
    protected $teacher;

    /**
     * Set up a course with an examcheck activity and an editing teacher.
     */
    protected function setUp(): void {
        global $PAGE;

        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $this->course->id]);
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($this->teacher);

        // Production sets the page url in seats.php; the dynamic table reads it on render.
        $PAGE->set_url('/mod/examcheck/seats.php', ['id' => $this->examcheck->cmid, 'action' => 'assign']);
        $PAGE->set_context(\context_module::instance($this->examcheck->cmid));
    }

    /**
     * Every seat gets a row, an assigned student is pre-selected, and the markup the
     * AMD module hooks into is present.
     */
    public function test_seat_table_renders(): void {
        $student = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['firstname' => 'Ann', 'lastname' => 'Other']
        );
        $seatids = $this->create_seats(['A1', 'A2']);
        seats::assign($seatids[0], (int) $student->id, (int) $this->teacher->id);

        $html = $this->render_table();

        $this->assertStringContainsString('A1', $html);
        $this->assertStringContainsString('A2', $html);
        // The occupied seat server-renders its student as the selected option.
        $this->assertStringContainsString('Ann Other', $html);
        // The dynamic-table wrapper the AJAX refresh targets.
        $this->assertStringContainsString('core_table/dynamic', $html);
        // The regions the AMD module enhances.
        $this->assertStringContainsString('data-region="examcheck-seat-cell"', $html);
        $this->assertStringContainsString('data-region="examcheck-seat-select"', $html);
        $this->assertStringContainsString('data-previousid="' . (int) $student->id . '"', $html);
        // The free seat carries no occupant.
        $this->assertStringContainsString('data-previousid="0"', $html);
    }

    /**
     * The student cell shows the full name alone: an identity value there would leak
     * to a viewer who may not see one.
     */
    public function test_student_cell_shows_no_identity_value(): void {
        global $CFG;
        $CFG->showuseridentity = 'email,idnumber';

        $student = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['firstname' => 'Ann', 'lastname' => 'Other', 'email' => 'ann@example.com', 'idnumber' => 'STU-42']
        );
        $seatids = $this->create_seats(['A1']);
        seats::assign($seatids[0], (int) $student->id, (int) $this->teacher->id);

        $html = $this->render_table();

        $this->assertStringContainsString('Ann Other', $html);
        $this->assertStringNotContainsString('ann@example.com', $html);
        $this->assertStringNotContainsString('STU-42', $html);
    }

    /**
     * Seats default to the order the teacher authored them in, and the Seat header
     * reverses that order rather than sorting the labels alphabetically.
     */
    public function test_seat_order_follows_the_authored_list(): void {
        $this->create_seats(['B1', 'A1']);

        $ascending = $this->render_table();
        $this->assertLessThan(strpos($ascending, 'A1'), strpos($ascending, 'B1'));

        $descending = $this->render_table([['sortby' => 'seat', 'sortorder' => SORT_DESC]]);
        $this->assertLessThan(strpos($descending, 'B1'), strpos($descending, 'A1'));
    }

    /**
     * Sorting by student orders the rows by the occupant's name, independently of
     * the seat order.
     */
    public function test_sort_by_student(): void {
        $last = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['firstname' => 'Zzz', 'lastname' => 'Last']
        );
        $first = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['firstname' => 'Aaa', 'lastname' => 'First']
        );
        // Seat order is the reverse of the student order.
        $seatids = $this->create_seats(['A1', 'A2']);
        seats::assign($seatids[0], (int) $last->id, (int) $this->teacher->id);
        seats::assign($seatids[1], (int) $first->id, (int) $this->teacher->id);

        $html = $this->render_table([['sortby' => 'student', 'sortorder' => SORT_ASC]]);
        $this->assertLessThan(strpos($html, 'Zzz Last'), strpos($html, 'Aaa First'));

        $html = $this->render_table([['sortby' => 'student', 'sortorder' => SORT_DESC]]);
        $this->assertLessThan(strpos($html, 'Aaa First'), strpos($html, 'Zzz Last'));
    }

    /**
     * Only a seat manager of an activity with the feature enabled may load the table:
     * this is the gate the dynamic-table AJAX endpoint calls.
     */
    public function test_has_capability(): void {
        $this->create_seats(['A1']);

        $table = new seat_assign("examcheck-seatassign-{$this->examcheck->cmid}");
        $table->set_filterset(new seat_assign_filterset());
        $this->assertTrue($table->has_capability());

        // A non-editing teacher may view the activity but not manage its seats.
        $viewer = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $this->setUser($viewer);
        $table = new seat_assign("examcheck-seatassign-{$this->examcheck->cmid}");
        $table->set_filterset(new seat_assign_filterset());
        $this->assertFalse($table->has_capability());
    }

    /**
     * With the seats feature disabled the table refuses to load, even for a seat manager.
     */
    public function test_has_capability_false_when_seats_disabled(): void {
        $disabled = $this->getDataGenerator()->create_module(
            'examcheck',
            ['course' => $this->course->id, 'enableseats' => 0]
        );

        $table = new seat_assign("examcheck-seatassign-{$disabled->cmid}");
        $table->set_filterset(new seat_assign_filterset());

        $this->assertFalse($table->has_capability());
    }

    /**
     * The unique id must carry the course module id: the AJAX endpoint rebuilds the
     * table from it alone.
     */
    public function test_unique_id_must_encode_the_cmid(): void {
        $this->expectException(\coding_exception::class);
        new seat_assign('examcheck-seatassign');
    }

    /**
     * The renderable exposes the assignment counts and the rendered table.
     */
    public function test_seat_assign_page_renderable(): void {
        global $PAGE;

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $seatids = $this->create_seats(['A1', 'A2', 'A3']);
        seats::assign($seatids[0], (int) $student->id, (int) $this->teacher->id);

        $page = new seat_assign_page((int) $this->examcheck->cmid, (int) $this->examcheck->id);
        $context = $page->export_for_template($PAGE->get_renderer('core'));

        $this->assertSame((int) $this->examcheck->cmid, $context['cmid']);
        $this->assertSame(1, $context['assignedcount']);
        $this->assertSame(3, $context['seatcount']);
        $this->assertSame(2, $context['freeseats']);
        // Two students on the roster, one already seated.
        $this->assertSame(2, $context['rostercount']);
        $this->assertSame(1, $context['studentsassigned']);
        $this->assertSame(1, $context['unseatedstudents']);
        $this->assertTrue($context['canautoassign']);
        $this->assertStringContainsString('data-region="examcheck-seat-cell"', $context['table']);
    }

    /**
     * The student half of the count line is measured against the caller's roster, not
     * against the seats: more students than seats means it outruns the seat count.
     */
    public function test_seat_assign_page_counts_students_against_the_roster(): void {
        global $PAGE;

        $seated = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        for ($i = 0; $i < 4; $i++) {
            $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        }
        $seatids = $this->create_seats(['A1', 'A2']);
        seats::assign($seatids[0], (int) $seated->id, (int) $this->teacher->id);

        $page = new seat_assign_page((int) $this->examcheck->cmid, (int) $this->examcheck->id);
        $context = $page->export_for_template($PAGE->get_renderer('core'));

        // Reads "1 of 2 seats assigned / 1 of 5 students assigned".
        $this->assertSame(1, $context['assignedcount']);
        $this->assertSame(2, $context['seatcount']);
        $this->assertSame(1, $context['studentsassigned']);
        $this->assertSame(5, $context['rostercount']);
        $this->assertSame(4, $context['unseatedstudents']);
    }

    /**
     * The auto-assign trigger is offered only when there is both a free seat and a
     * student without one.
     */
    public function test_seat_assign_page_hides_the_trigger_when_idle(): void {
        global $PAGE;

        $student = $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        $seatids = $this->create_seats(['A1', 'A2']);

        // A free seat, but nobody left to sit on it: "1 of 2 seats / 1 of 1 students".
        seats::assign($seatids[0], (int) $student->id, (int) $this->teacher->id);
        $page = new seat_assign_page((int) $this->examcheck->cmid, (int) $this->examcheck->id);
        $context = $page->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame(1, $context['freeseats']);
        $this->assertSame(0, $context['unseatedstudents']);
        $this->assertSame(1, $context['studentsassigned']);
        $this->assertSame(1, $context['rostercount']);
        $this->assertFalse($context['canautoassign']);

        // An unseated student, but no seat free: "1 of 1 seats / 1 of 2 students".
        $this->getDataGenerator()->create_and_enrol($this->course, 'student');
        seats::replace_list((int) $this->examcheck->id, ['A1']);
        $seatid = (int) array_key_first(seats::get_seats((int) $this->examcheck->id));
        seats::assign($seatid, (int) $student->id, (int) $this->teacher->id);
        $page = new seat_assign_page((int) $this->examcheck->cmid, (int) $this->examcheck->id);
        $context = $page->export_for_template($PAGE->get_renderer('core'));
        $this->assertSame(0, $context['freeseats']);
        $this->assertSame(1, $context['unseatedstudents']);
        $this->assertSame(1, $context['studentsassigned']);
        $this->assertSame(2, $context['rostercount']);
        $this->assertFalse($context['canautoassign']);
    }

    /**
     * Create the seat list and return the seat ids in list order.
     *
     * @param string[] $labels The seat labels.
     * @return int[]
     */
    private function create_seats(array $labels): array {
        seats::replace_list((int) $this->examcheck->id, $labels);
        return array_map('intval', array_keys(seats::get_seats((int) $this->examcheck->id)));
    }

    /**
     * Render the seat table, optionally sorted.
     *
     * @param array $sortdata Sort data as the dynamic-table endpoint passes it.
     * @return string The table HTML.
     */
    private function render_table(array $sortdata = []): string {
        $table = new seat_assign("examcheck-seatassign-{$this->examcheck->cmid}");
        if ($sortdata) {
            $table->set_sortdata($sortdata);
        }
        $table->set_filterset(new seat_assign_filterset());

        ob_start();
        $table->out(1000, false);
        return ob_get_clean();
    }
}
