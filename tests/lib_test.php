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

use mod_examcheck\completion\custom_completion;
use mod_examcheck\local\checker;
use mod_examcheck\local\steps;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/mod/examcheck/lib.php');
require_once($CFG->libdir . '/completionlib.php');

/**
 * Tests for the library callbacks and completion logic.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\completion\custom_completion
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class lib_test extends \advanced_testcase {
    /**
     * Run each test as admin so completion recalculation has a user context.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
    }

    /**
     * Deleting an instance removes its steps and marks.
     */
    public function test_delete_instance_cascades(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $step = (int) array_values(steps::get_steps($examcheck->id))[0]->id;
        $DB->insert_record('examcheck_marks', (object) [
            'examcheckid' => $examcheck->id, 'stepid' => $step, 'userid' => 3,
            'checkedby' => 4, 'method' => 'list', 'timecreated' => time(),
        ]);

        examcheck_delete_instance($examcheck->id);

        $this->assertFalse($DB->record_exists('examcheck', ['id' => $examcheck->id]));
        $this->assertEquals(0, $DB->count_records('examcheck_steps', ['examcheckid' => $examcheck->id]));
        $this->assertEquals(0, $DB->count_records('examcheck_marks', ['examcheckid' => $examcheck->id]));
    }

    /**
     * Course reset deletes recorded checks.
     */
    public function test_reset_userdata(): void {
        global $DB;
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $step = (int) array_values(steps::get_steps($examcheck->id))[0]->id;
        $DB->insert_record('examcheck_marks', (object) [
            'examcheckid' => $examcheck->id, 'stepid' => $step, 'userid' => 3,
            'checkedby' => 4, 'method' => 'list', 'timecreated' => time(),
        ]);

        examcheck_reset_userdata((object) ['courseid' => $course->id, 'reset_examcheck_marks' => 1]);
        $this->assertEquals(0, $DB->count_records('examcheck_marks', ['examcheckid' => $examcheck->id]));
    }

    /**
     * "All steps" completion requires every step to be checked.
     */
    public function test_completion_all_steps(): void {
        $this->resetAfterTest();
        [$course, $examcheck, $student, $teacher] = $this->setup_completion_course(0);

        steps::add_step($examcheck->id, 'Identity');
        $allsteps = array_values(steps::get_steps($examcheck->id));

        $context = \context_module::instance($examcheck->cmid);
        $checker = new checker($this->reload($examcheck), $context);

        // Check only the first of two steps: not complete yet.
        $checker->mark_user((int) $allsteps[0]->id, $student->id, $teacher->id);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completionstate($course, $examcheck, $student));

        // Check the second step too: now complete.
        $checker->mark_user((int) $allsteps[1]->id, $student->id, $teacher->id);
        $this->assertSame(COMPLETION_COMPLETE, $this->completionstate($course, $examcheck, $student));
    }

    /**
     * Single-step completion only requires the chosen step.
     */
    public function test_completion_single_step(): void {
        $this->resetAfterTest();

        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $first = null;
        // Create with completion on; choose the (only, default) step after creation.
        $examcheck = $this->getDataGenerator()->create_module('examcheck', [
            'course' => $course->id, 'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionchecked' => 1,
        ]);
        $steplist = array_values(steps::get_steps($examcheck->id));
        $chosen = (int) $steplist[0]->id;
        $other = steps::add_step($examcheck->id, 'Identity');

        // Point completion at the chosen step only.
        global $DB;
        $DB->set_field('examcheck', 'completionstep', $chosen, ['id' => $examcheck->id]);
        rebuild_course_cache($course->id, true);

        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        $context = \context_module::instance($examcheck->cmid);
        $checker = new checker($this->reload($examcheck), $context);

        // Checking the "other" step does not complete it.
        $checker->mark_user($other, $student->id, $teacher->id);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completionstate($course, $examcheck, $student));

        // Checking the chosen step completes it.
        $checker->mark_user($chosen, $student->id, $teacher->id);
        $this->assertSame(COMPLETION_COMPLETE, $this->completionstate($course, $examcheck, $student));
    }

    /**
     * The active rule description is reported when completion is automatic.
     */
    public function test_active_rule_descriptions(): void {
        $this->resetAfterTest();
        [$course, $examcheck] = $this->setup_completion_course(0);
        $cm = get_fast_modinfo($course)->get_cm($examcheck->cmid);
        $descriptions = mod_examcheck_get_completion_active_rule_descriptions($cm);
        $this->assertContains(get_string('completionchecked_desc', 'mod_examcheck'), $descriptions);
    }

    /**
     * Build a course + instance with automatic "all steps" completion.
     *
     * @param int $completionstep 0 for all steps, or a step id.
     * @return array [course, examcheck, student, teacher]
     */
    protected function setup_completion_course(int $completionstep): array {
        $course = $this->getDataGenerator()->create_course(['enablecompletion' => 1]);
        $examcheck = $this->getDataGenerator()->create_module('examcheck', [
            'course' => $course->id,
            'completion' => COMPLETION_TRACKING_AUTOMATIC,
            'completionchecked' => 1,
            'completionstep' => $completionstep,
        ]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');
        return [$course, $examcheck, $student, $teacher];
    }

    /**
     * Reload the full instance record (with completion fields).
     *
     * @param \stdClass $examcheck The module stub with ->id.
     * @return \stdClass
     */
    protected function reload(\stdClass $examcheck): \stdClass {
        global $DB;
        return $DB->get_record('examcheck', ['id' => $examcheck->id], '*', MUST_EXIST);
    }

    /**
     * Get the completion state for a student.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $examcheck The instance stub with ->cmid.
     * @param \stdClass $student The student.
     * @return int The completion state.
     */
    protected function completionstate(\stdClass $course, \stdClass $examcheck, \stdClass $student): int {
        $cm = get_fast_modinfo($course)->get_cm($examcheck->cmid);
        $completion = new \completion_info($course);
        return (int) $completion->get_data($cm, false, $student->id)->completionstate;
    }
}
