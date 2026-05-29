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

use mod_examcheck\external\bulk_action;
use mod_examcheck\local\checker;
use mod_examcheck\local\steps;

/**
 * Tests for the bulk mark/unmark web service.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\external\bulk_action
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class bulk_action_test extends \advanced_testcase {
    /**
     * Bulk mark then unmark a step for several students, including conflict/skip cases.
     */
    public function test_bulk_mark_and_unmark(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $cmid = (int) $examcheck->cmid;
        $stepid = (int) array_values(steps::get_steps($examcheck->id))[0]->id;

        // A non-editing teacher can check but cannot override others' marks.
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'teacher');
        $other = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $s1 = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $s2 = $this->getDataGenerator()->create_and_enrol($course, 'student');

        $checker = checker::from_cmid($cmid);

        // The other teacher pre-checks s2, so the bulk mark sees a conflict on s2.
        $this->setUser($other);
        $checker->mark_user($stepid, $s2->id, $other->id);

        // Bulk mark both as the non-editing teacher: s1 newly marked, s2 already checked.
        $this->setUser($teacher);
        $marked = bulk_action::execute($cmid, $stepid, [$s1->id, $s2->id], 'mark', 0);
        $this->assertSame(1, $marked['done']);
        $this->assertSame(1, $marked['conflicts']);
        $this->assertSame(2, $marked['total']);

        // Bulk unmark: s1 is ours -> removed; s2 is the other teacher's and we lack override -> skipped.
        $unmarked = bulk_action::execute($cmid, $stepid, [$s1->id, $s2->id], 'unmark', 0);
        $this->assertSame(1, $unmarked['done']);
        $this->assertSame(1, $unmarked['skipped']);
    }
}
