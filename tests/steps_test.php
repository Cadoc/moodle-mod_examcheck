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

use mod_examcheck\local\steps;

/**
 * Tests for step management.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\local\steps
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class steps_test extends \advanced_testcase {

    /** @var \stdClass The examcheck instance. */
    protected $examcheck;

    /**
     * Create a course and instance.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $course = $this->getDataGenerator()->create_course();
        $this->examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
    }

    /**
     * Steps can be added and are returned in order.
     */
    public function test_add_and_order(): void {
        steps::add_step($this->examcheck->id, 'Identity');
        steps::add_step($this->examcheck->id, 'Copy submitted');

        $names = array_map(fn($s) => $s->name, array_values(steps::get_steps($this->examcheck->id)));
        $this->assertSame([get_string('defaultstepname', 'mod_examcheck'), 'Identity', 'Copy submitted'], $names);
    }

    /**
     * A step can be renamed.
     */
    public function test_rename(): void {
        $steplist = array_values(steps::get_steps($this->examcheck->id));
        steps::rename_step((int) $steplist[0]->id, 'Renamed');
        $reloaded = array_values(steps::get_steps($this->examcheck->id));
        $this->assertSame('Renamed', $reloaded[0]->name);
    }

    /**
     * An empty step name is rejected.
     */
    public function test_empty_name_rejected(): void {
        $this->expectException(\moodle_exception::class);
        steps::add_step($this->examcheck->id, '   ');
    }

    /**
     * The last remaining step cannot be deleted.
     */
    public function test_cannot_delete_last_step(): void {
        $steplist = array_values(steps::get_steps($this->examcheck->id));
        $this->expectException(\moodle_exception::class);
        steps::delete_step((int) $steplist[0]->id);
    }

    /**
     * Deleting a step also removes its recorded checks.
     */
    public function test_delete_step_removes_marks(): void {
        global $DB;

        $second = steps::add_step($this->examcheck->id, 'Identity');
        $DB->insert_record('examcheck_marks', (object) [
            'examcheckid' => $this->examcheck->id,
            'stepid'      => $second,
            'userid'      => 7,
            'checkedby'   => 9,
            'method'      => 'list',
            'timecreated' => time(),
        ]);

        steps::delete_step($second);
        $this->assertFalse($DB->record_exists('examcheck_steps', ['id' => $second]));
        $this->assertEquals(0, $DB->count_records('examcheck_marks', ['stepid' => $second]));
    }

    /**
     * Steps can be reordered up and down.
     */
    public function test_move(): void {
        $first = array_values(steps::get_steps($this->examcheck->id))[0]->id;
        $second = steps::add_step($this->examcheck->id, 'Identity');

        steps::move_step($second, -1); // Move Identity above the default step.

        $order = array_map(fn($s) => (int) $s->id, array_values(steps::get_steps($this->examcheck->id)));
        $this->assertSame([$second, (int) $first], $order);
    }
}
