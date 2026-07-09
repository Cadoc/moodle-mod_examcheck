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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Backup and restore tests for the per-step requirements.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \restore_examcheck_activity_structure_step
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class backup_restore_test extends \advanced_testcase {
    /**
     * Duplicating an activity remaps a step's "another step checked" prerequisite to
     * the matching step of the copy. The gate here is a forward reference — the first
     * step depends on the second, which is restored after it — so it can only be
     * resolved by the restore step's after_execute() pass, not inline.
     */
    public function test_duplicate_remaps_step_requirement(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);

        $original = array_values(steps::get_steps($examcheck->id));
        $firststepid = (int) $original[0]->id;
        $secondstepid = steps::add_step($examcheck->id, 'Identity');

        // Forward reference: the first step (restored first) gates on the second step
        // (restored later), whose new id only exists once every step is restored.
        steps::save_step_requirement($firststepid, 'step', null, $secondstepid);

        $cm = get_coursemodule_from_instance('examcheck', $examcheck->id, $course->id, false, MUST_EXIST);
        $newcm = duplicate_module($course, $cm);

        $copysteps = array_values(steps::get_steps($newcm->instance));
        $this->assertCount(2, $copysteps);

        // The copied first step still gates on a step, now pointing at the COPIED
        // second step (a fresh id), never the original instance's step.
        $copyfirst = $copysteps[0];
        $copysecond = $copysteps[1];
        $this->assertSame('step', $copyfirst->requirementtype);
        $this->assertEquals((int) $copysecond->id, (int) $copyfirst->requirementstepid);
        $this->assertNotEquals($secondstepid, (int) $copyfirst->requirementstepid);
    }
}
