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
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $context = \context_module::instance($examcheck->cmid);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $stepid = (int) array_values(steps::get_steps($examcheck->id))[0]->id;

        global $DB;
        $instance = $DB->get_record('examcheck', ['id' => $examcheck->id], '*', MUST_EXIST);
        $checker = new checker($instance, $context);

        // Capture the mark event.
        $sink = $this->redirectEvents();
        $checker->mark_user($stepid, $student->id, $teacher->id, 'list');
        $events = $sink->get_events();
        $marked = array_filter($events, fn($e) => $e instanceof \mod_examcheck\event\user_marked);
        $this->assertCount(1, $marked);
        $event = reset($marked);
        $this->assertEquals($student->id, $event->relateduserid);
        $this->assertEquals($context->id, $event->contextid);
        $sink->close();

        // Capture the unmark event.
        $sink = $this->redirectEvents();
        $checker->unmark_user($stepid, $student->id, $teacher->id);
        $unmarked = array_filter($sink->get_events(), fn($e) => $e instanceof \mod_examcheck\event\user_unmarked);
        $this->assertCount(1, $unmarked);
        $sink->close();
    }
}
