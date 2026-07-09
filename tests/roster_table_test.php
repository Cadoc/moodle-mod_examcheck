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
use mod_examcheck\output\dashboard;
use mod_examcheck\table\roster;
use mod_examcheck\table\roster_filterset;

/**
 * Tests that the roster dynamic table renders server-side without error.
 *
 * Covers the rendering path the dynamic-table AJAX endpoint exercises (set_filterset,
 * query_db and the cell methods); the JS/AJAX wiring itself is verified in the browser.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\table\roster
 * @covers     \mod_examcheck\output\dashboard
 * @covers     \mod_examcheck\output\roster_filter
 * @covers     \mod_examcheck\table\roster_filterset
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class roster_table_test extends \advanced_testcase {
    /**
     * The roster renders one toggle button per student/step and shows student names.
     */
    public function test_roster_renders(): void {
        global $PAGE, $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Configure email as an identity field; admin has moodle/site:viewuseridentity.
        $CFG->showuseridentity = 'email';

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol(
            $course,
            'student',
            ['firstname' => 'Ann', 'lastname' => 'Other', 'email' => 'ann.other@example.com', 'idnumber' => 'STU-42']
        );
        // A teacher is a checker and must be excluded from the roster.
        $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        // Production sets the page url in view.php; the dynamic table reads it on render.
        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $examcheck->cmid]);

        $table = new roster("examcheck-roster-{$examcheck->cmid}");
        $table->set_filterset(new roster_filterset());

        ob_start();
        $table->out(1000, false);
        $html = ob_get_clean();

        $this->assertStringContainsString('Ann Other', $html);
        $this->assertStringContainsString('data-action="toggle"', $html);
        // The dynamic-table wrapper that the AJAX refresh targets must be present.
        $this->assertStringContainsString('core_table/dynamic', $html);
        // The email identity column shows because the viewer may see it.
        $this->assertStringContainsString('ann.other@example.com', $html);
        // Row-selection checkbox is present for bulk actions.
        $this->assertStringContainsString("data-togglegroup=\"examcheck-roster\"", $html);
        // The student name links to their profile.
        $this->assertStringContainsString('/user/view.php', $html);
        // The match field (default idnumber) shows in its own column, labelled "ID number".
        $this->assertStringContainsString('STU-42', $html);
        $this->assertStringContainsString(get_string('field_idnumber', 'mod_examcheck'), $html);
    }

    /**
     * The match column follows the activity's configured scan field, not a hardcoded one.
     */
    public function test_match_column_uses_configured_field(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        // Match on the internal user id instead of the id number.
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id, 'scanfield' => 'userid']);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student', ['idnumber' => 'IGNORED']);

        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $examcheck->cmid]);
        $table = new roster("examcheck-roster-{$examcheck->cmid}");
        $table->set_filterset(new roster_filterset());

        ob_start();
        $table->out(1000, false);
        $html = ob_get_clean();

        // Column is labelled for the userid field and shows the student's id, not the idnumber.
        $this->assertStringContainsString(get_string('field_userid', 'mod_examcheck'), $html);
        $this->assertStringContainsString('>' . $student->id . '<', $html);
    }

    /**
     * The dashboard renderable builds without error and includes the bulk-action menu.
     */
    public function test_dashboard_renders(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $this->getDataGenerator()->create_and_enrol($course, 'student');

        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $examcheck->cmid]);
        $PAGE->set_context(\context_module::instance($examcheck->cmid));

        $output = $PAGE->get_renderer('core');
        $context = (new dashboard((int) $examcheck->cmid))->export_for_template($output);

        // The "With selected students" optgroup menu builds (regression: optgroup format).
        $this->assertStringContainsString('export:csv', $context['withselected']);
        $this->assertStringContainsString('export:pdf', $context['withselected']);
        // Admin can check, so per-step mark/unmark options are present.
        $this->assertStringContainsString('mark:', $context['withselected']);
        $this->assertStringContainsString('unmark:', $context['withselected']);
    }

    /**
     * A "userid" filter restricts the roster to that single student (the scanner's
     * "View in roster" deep link), dropping everyone else.
     */
    public function test_userid_filter_limits_to_one_student(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $wanted = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Ann', 'lastname' => 'Wanted']);
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Bob', 'lastname' => 'Other']);

        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $examcheck->cmid]);

        $filterset = new roster_filterset();
        $filterset->add_filter_from_params('userid', roster_filterset::JOINTYPE_ANY, [(int) $wanted->id]);

        $table = new roster("examcheck-roster-{$examcheck->cmid}");
        $table->set_filterset($filterset);

        ob_start();
        $table->out(1000, false);
        $html = ob_get_clean();

        $this->assertStringContainsString('Ann Wanted', $html);
        $this->assertStringNotContainsString('Bob Other', $html);
    }

    /**
     * When the dashboard is given a focus userid it seeds the table (body limited to that
     * student) and exposes the student as a chip option in the filter bar.
     */
    public function test_dashboard_focus_user_seeds_chip(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $wanted = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Ann', 'lastname' => 'Wanted']);
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Bob', 'lastname' => 'Other']);

        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $examcheck->cmid]);
        $PAGE->set_context(\context_module::instance($examcheck->cmid));
        $output = $PAGE->get_renderer('core');

        $context = (new dashboard((int) $examcheck->cmid, (int) $wanted->id))->export_for_template($output);

        // The server-rendered body is already limited to the focus student.
        $this->assertStringContainsString('Ann Wanted', $context['table']);
        $this->assertStringNotContainsString('Bob Other', $context['table']);
        // The filter bar exposes the student as an option so the JS can pre-apply the chip.
        $this->assertStringContainsString('Ann Wanted', $context['filter']);
    }

    /**
     * A userid that is not a reachable roster student is ignored: the roster stays
     * unfiltered and the user's name is never exposed in the filter bar.
     */
    public function test_dashboard_ignores_unreachable_userid(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Ann', 'lastname' => 'Wanted']);
        $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Bob', 'lastname' => 'Other']);
        // A user who is not enrolled in this course must not filter the roster or leak in.
        $stranger = $this->getDataGenerator()->create_user(['firstname' => 'Eve', 'lastname' => 'Stranger']);

        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $examcheck->cmid]);
        $PAGE->set_context(\context_module::instance($examcheck->cmid));
        $output = $PAGE->get_renderer('core');

        $context = (new dashboard((int) $examcheck->cmid, (int) $stranger->id))->export_for_template($output);

        // The roster is unfiltered: both enrolled students remain.
        $this->assertStringContainsString('Ann Wanted', $context['table']);
        $this->assertStringContainsString('Bob Other', $context['table']);
        // The out-of-reach user's name is never rendered into the filter bar.
        $this->assertStringNotContainsString('Eve Stranger', $context['filter']);
    }

    /**
     * The seat column only appears once the activity has seats, and then shows
     * each student's assigned label.
     */
    public function test_seat_column_presence(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $examcheck->cmid]);

        // No seats yet: no seat column.
        $html = $this->render_table($examcheck->cmid);
        $this->assertStringNotContainsString(get_string('seat', 'mod_examcheck'), $html);

        // With seats: the column shows, carrying the assigned label.
        seats::replace_list($examcheck->id, ['A7']);
        $seatid = (int) array_key_first(seats::get_seats($examcheck->id));
        seats::assign($seatid, (int) $student->id, (int) $teacher->id);

        $html = $this->render_table($examcheck->cmid);
        $this->assertStringContainsString(get_string('seat', 'mod_examcheck'), $html);
        $this->assertStringContainsString('A7', $html);
    }

    /**
     * Sorting by seat uses natural order, so A2 comes before A10.
     */
    public function test_seat_column_natural_sort(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        // Alphabetical name order (the default sort) is the reverse of the seat order.
        $onten = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Aaa', 'lastname' => 'First']);
        $ontwo = $this->getDataGenerator()->create_and_enrol($course, 'student', ['firstname' => 'Zzz', 'lastname' => 'Last']);
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        seats::replace_list($examcheck->id, ['A10', 'A2']);
        $seatids = array_map('intval', array_keys(seats::get_seats($examcheck->id)));
        seats::assign($seatids[0], (int) $onten->id, (int) $teacher->id); // Aaa First on A10.
        seats::assign($seatids[1], (int) $ontwo->id, (int) $teacher->id); // Zzz Last on A2.

        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $examcheck->cmid]);
        $table = new roster("examcheck-roster-{$examcheck->cmid}");
        $table->set_sortdata([['sortby' => 'seat', 'sortorder' => SORT_ASC]]);
        $table->set_filterset(new roster_filterset());

        ob_start();
        $table->out(1000, false);
        $html = ob_get_clean();

        // Natural order: A2 (Zzz Last) must be listed before A10 (Aaa First).
        $this->assertLessThan(strpos($html, 'Aaa First'), strpos($html, 'Zzz Last'));
    }

    /**
     * A viewer with only mod/examcheck:view (no manageseats) still sees the
     * seat column: it is read-only roster information.
     */
    public function test_seat_column_visible_to_view_only_teacher(): void {
        global $PAGE;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $editing = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        // A non-editing teacher can view but has no manageseats capability.
        $viewer = $this->getDataGenerator()->create_and_enrol($course, 'teacher');

        seats::replace_list($examcheck->id, ['B4']);
        $seatid = (int) array_key_first(seats::get_seats($examcheck->id));
        seats::assign($seatid, (int) $student->id, (int) $editing->id);

        $this->setUser($viewer);
        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $examcheck->cmid]);

        $html = $this->render_table($examcheck->cmid);
        $this->assertStringContainsString(get_string('seat', 'mod_examcheck'), $html);
        $this->assertStringContainsString('B4', $html);
    }

    /**
     * Render the roster table for a cmid with an empty filterset.
     *
     * @param int $cmid The course module id.
     * @return string The table HTML.
     */
    private function render_table(int $cmid): string {
        $table = new roster("examcheck-roster-{$cmid}");
        $table->set_filterset(new roster_filterset());

        ob_start();
        $table->out(1000, false);
        return ob_get_clean();
    }
}
