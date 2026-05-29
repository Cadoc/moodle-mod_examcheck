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
 * Tests that marking and unmarking trigger the expected events.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\event\user_marked
 * @covers     \mod_examcheck\event\user_unmarked
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class event_test extends \advanced_testcase {
    /**
     * Marking fires user_marked; unmarking fires user_unmarked.
     */
    public function test_mark_unmark_events(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $context = \context_module::instance($examcheck->cmid);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $step = array_values(steps::get_steps($examcheck->id))[0];
        $stepid = (int) $step->id;

        global $DB;
        $instance = $DB->get_record('examcheck', ['id' => $examcheck->id], '*', MUST_EXIST);
        $checker = new checker($instance, $context);

        // Act as the teacher so the event's acting user is the teacher.
        $this->setUser($teacher);

        // Capture the mark event: "teacher A checked step <name> for student B".
        $sink = $this->redirectEvents();
        $checker->mark_user($stepid, $student->id, $teacher->id, 'list');
        $marked = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_examcheck\event\user_marked);
        $this->assertCount(1, $marked);
        $event = reset($marked);
        $sink->close();

        $this->assertEquals($student->id, $event->relateduserid);
        $this->assertEquals($context->id, $event->contextid);
        $this->assertSame('examcheck_marks', $event->objecttable);
        $this->assertSame('c', $event->crud);
        $this->assertSame($stepid, (int) $event->other['stepid']);
        $this->assertSame($step->name, $event->other['stepname']);

        // The human-readable log line names the actor, the step, and the affected student.
        $description = $event->get_description();
        $this->assertStringContainsString($step->name, $description);
        $this->assertStringContainsString((string) $teacher->id, $description);
        $this->assertStringContainsString((string) $student->id, $description);

        // Capture the unmark event.
        $sink = $this->redirectEvents();
        $checker->unmark_user($stepid, $student->id, $teacher->id);
        $unmarked = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_examcheck\event\user_unmarked);
        $this->assertCount(1, $unmarked);
        $unmarkevent = reset($unmarked);
        $sink->close();

        $this->assertSame('d', $unmarkevent->crud);
        $this->assertEquals($student->id, $unmarkevent->relateduserid);
        $this->assertStringContainsString($step->name, $unmarkevent->get_description());
    }
}
