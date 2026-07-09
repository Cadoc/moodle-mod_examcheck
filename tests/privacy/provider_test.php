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

namespace mod_examcheck\privacy;

use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\writer;
use mod_examcheck\local\checker;
use mod_examcheck\local\seats;
use mod_examcheck\local\steps;

/**
 * Tests for the privacy provider.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\privacy\provider
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var \stdClass The examcheck module stub. */
    protected $examcheck;
    /** @var \context_module The module context. */
    protected $context;
    /** @var \stdClass The student. */
    protected $student;
    /** @var \stdClass The teacher. */
    protected $teacher;

    /**
     * Create a course, instance and one recorded check.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $this->examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $this->context = \context_module::instance($this->examcheck->cmid);
        $this->student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $this->teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        $stepid = (int) array_values(steps::get_steps($this->examcheck->id))[0]->id;
        $checker = new checker(
            $this->reload(),
            $this->context
        );
        $checker->mark_user($stepid, $this->student->id, $this->teacher->id, 'list');

        // And one seat assignment (teacher assigns the student to A1).
        seats::replace_list($this->examcheck->id, ['A1']);
        $seatid = (int) array_key_first(seats::get_seats($this->examcheck->id));
        seats::assign($seatid, (int) $this->student->id, (int) $this->teacher->id);
    }

    /**
     * Both the student and the teacher have a context with data.
     */
    public function test_get_contexts_for_userid(): void {
        $studentcontexts = provider::get_contexts_for_userid($this->student->id)->get_contextids();
        $teachercontexts = provider::get_contexts_for_userid($this->teacher->id)->get_contextids();

        $this->assertContainsEquals($this->context->id, $studentcontexts);
        $this->assertContainsEquals($this->context->id, $teachercontexts);
    }

    /**
     * Both users are reported as having data in the context.
     */
    public function test_get_users_in_context(): void {
        $userlist = new \core_privacy\local\request\userlist($this->context, 'mod_examcheck');
        provider::get_users_in_context($userlist);
        $ids = $userlist->get_userids();
        $this->assertContains((int) $this->student->id, $ids);
        $this->assertContains((int) $this->teacher->id, $ids);
    }

    /**
     * Export writes data for the student.
     */
    public function test_export_user_data(): void {
        $this->export_context_data_for_user($this->student->id, $this->context, 'mod_examcheck');
        $writer = writer::with_context($this->context);
        $this->assertTrue($writer->has_any_data());
    }

    /**
     * The metadata declares both the marks and the seat assignments tables.
     */
    public function test_metadata(): void {
        $collection = new \core_privacy\local\metadata\collection('mod_examcheck');
        $collection = provider::get_metadata($collection);
        $tables = array_map(
            fn($type) => $type->get_name(),
            $collection->get_collection()
        );
        $this->assertContains('examcheck_marks', $tables);
        $this->assertContains('examcheck_seat_users', $tables);
    }

    /**
     * A user whose only footprint is a seat assignment is still discovered,
     * both by context and within the context's user list.
     */
    public function test_seat_only_user_is_discovered(): void {
        global $DB;

        // Wipe the marks so the student's only data is the seat assignment.
        $DB->delete_records('examcheck_marks', ['examcheckid' => $this->examcheck->id]);

        $contexts = provider::get_contexts_for_userid($this->student->id)->get_contextids();
        $this->assertContainsEquals($this->context->id, $contexts);

        $userlist = new \core_privacy\local\request\userlist($this->context, 'mod_examcheck');
        provider::get_users_in_context($userlist);
        $ids = $userlist->get_userids();
        $this->assertContains((int) $this->student->id, $ids);
        $this->assertContains((int) $this->teacher->id, $ids);
    }

    /**
     * Deleting all data in a context removes every mark and seat assignment.
     */
    public function test_delete_for_all_users(): void {
        global $DB;
        provider::delete_data_for_all_users_in_context($this->context);
        $this->assertEquals(0, $DB->count_records('examcheck_marks', ['examcheckid' => $this->examcheck->id]));
        $this->assertEquals(0, $DB->count_records('examcheck_seat_users', ['examcheckid' => $this->examcheck->id]));
        // The seat list itself is structure, not personal data.
        $this->assertEquals(1, $DB->count_records('examcheck_seats', ['examcheckid' => $this->examcheck->id]));
    }

    /**
     * Deleting the student's data removes the mark and the seat assignment.
     */
    public function test_delete_for_student(): void {
        global $DB;
        $contextlist = new approved_contextlist($this->student, 'mod_examcheck', [$this->context->id]);
        provider::delete_data_for_user($contextlist);
        $this->assertEquals(0, $DB->count_records('examcheck_marks', ['examcheckid' => $this->examcheck->id]));
        $this->assertEquals(0, $DB->count_records('examcheck_seat_users', ['examcheckid' => $this->examcheck->id]));
    }

    /**
     * Deleting the teacher's data anonymises the checker/assigner but keeps
     * the student's records intact.
     */
    public function test_delete_for_teacher_anonymises(): void {
        global $DB;
        $contextlist = new approved_contextlist($this->teacher, 'mod_examcheck', [$this->context->id]);
        provider::delete_data_for_user($contextlist);

        $marks = $DB->get_records('examcheck_marks', ['examcheckid' => $this->examcheck->id]);
        $this->assertCount(1, $marks);
        $mark = reset($marks);
        $this->assertEquals(0, (int) $mark->checkedby);
        $this->assertEquals($this->student->id, (int) $mark->userid);

        $assignments = $DB->get_records('examcheck_seat_users', ['examcheckid' => $this->examcheck->id]);
        $this->assertCount(1, $assignments);
        $assignment = reset($assignments);
        $this->assertEquals(0, (int) $assignment->assignedby);
        $this->assertEquals($this->student->id, (int) $assignment->userid);
    }

    /**
     * delete_data_for_users deletes student rows and anonymises checker/assigner rows.
     */
    public function test_delete_for_users(): void {
        global $DB;
        $userlist = new approved_userlist($this->context, 'mod_examcheck', [$this->student->id]);
        provider::delete_data_for_users($userlist);
        $this->assertEquals(0, $DB->count_records(
            'examcheck_marks',
            ['examcheckid' => $this->examcheck->id, 'userid' => $this->student->id]
        ));
        $this->assertEquals(0, $DB->count_records(
            'examcheck_seat_users',
            ['examcheckid' => $this->examcheck->id, 'userid' => $this->student->id]
        ));

        // Targeting the teacher instead anonymises their assigner reference.
        seats::assign(
            (int) array_key_first(seats::get_seats($this->examcheck->id)),
            (int) $this->student->id,
            (int) $this->teacher->id
        );
        $userlist = new approved_userlist($this->context, 'mod_examcheck', [$this->teacher->id]);
        provider::delete_data_for_users($userlist);
        $assignment = $DB->get_record('examcheck_seat_users', ['examcheckid' => $this->examcheck->id]);
        $this->assertEquals(0, (int) $assignment->assignedby);
    }

    /**
     * Reload the full instance record.
     *
     * @return \stdClass
     */
    protected function reload(): \stdClass {
        global $DB;
        return $DB->get_record('examcheck', ['id' => $this->examcheck->id], '*', MUST_EXIST);
    }
}
