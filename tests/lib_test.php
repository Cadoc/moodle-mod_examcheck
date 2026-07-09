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
use mod_examcheck\local\seats;
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
     * Course reset deletes seat assignments but keeps the seat list: seats are
     * structure (like the steps), only who-sits-where is user data.
     */
    public function test_reset_userdata_seats(): void {
        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        seats::replace_list($examcheck->id, ['A1', 'A2']);
        $seatid = (int) array_key_first(seats::get_seats($examcheck->id));
        seats::assign($seatid, (int) $student->id, (int) $teacher->id);

        // Without the checkbox nothing seat-related changes.
        examcheck_reset_userdata((object) ['courseid' => $course->id, 'reset_examcheck_marks' => 1]);
        $this->assertSame(1, seats::count_assignments($examcheck->id));

        // With it, assignments go but the seats survive.
        $status = examcheck_reset_userdata((object) [
            'courseid' => $course->id,
            'reset_examcheck_seatassignments' => 1,
        ]);
        $this->assertSame(0, seats::count_assignments($examcheck->id));
        $this->assertSame(2, seats::count_seats($examcheck->id));
        $items = array_column($status, 'item');
        $this->assertContains(get_string('resetseatassignments', 'mod_examcheck'), $items);
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
     * Completion reverts to incomplete when the qualifying check is removed.
     *
     * This round-trip guards the "require activity complete" use case: a quiz gated on
     * this activity must re-lock if a teacher unchecks the student.
     */
    public function test_completion_reverts_on_unmark(): void {
        $this->resetAfterTest();
        [$course, $examcheck, $student, $teacher] = $this->setup_completion_course(0);

        $step = (int) array_values(steps::get_steps($examcheck->id))[0]->id;
        $context = \context_module::instance($examcheck->cmid);
        $checker = new checker($this->reload($examcheck), $context);

        $checker->mark_user($step, $student->id, $teacher->id);
        $this->assertSame(COMPLETION_COMPLETE, $this->completionstate($course, $examcheck, $student));

        $checker->unmark_user($step, $student->id, $teacher->id);
        $this->assertSame(COMPLETION_INCOMPLETE, $this->completionstate($course, $examcheck, $student));
    }

    /**
     * The module advertises custom completion rules so it can gate other activities.
     */
    public function test_supports_completion_rules(): void {
        $this->assertTrue((bool) examcheck_supports(FEATURE_COMPLETION_HAS_RULES));
    }

    /**
     * The Scanner secondary-nav node appears only when the activity enables the scanner.
     */
    public function test_settings_navigation_scanner_gated(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $enabled = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id, 'enablescanner' => 1]);
        $disabled = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id, 'enablescanner' => 0]);

        $this->assertTrue($this->has_settings_node($course, $enabled, 'mod_examcheck_scanner'));
        $this->assertFalse($this->has_settings_node($course, $disabled, 'mod_examcheck_scanner'));
    }

    /**
     * The Seats secondary-nav node appears only when the activity enables seats.
     */
    public function test_settings_navigation_seats_gated(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $course = $this->getDataGenerator()->create_course();

        $enabled = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id, 'enableseats' => 1]);
        $disabled = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id, 'enableseats' => 0]);

        $this->assertTrue($this->has_settings_node($course, $enabled, 'mod_examcheck_seats'));
        $this->assertFalse($this->has_settings_node($course, $disabled, 'mod_examcheck_seats'));
    }

    /**
     * Whether examcheck_extend_settings_navigation adds the given node for an instance.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $examcheck The instance stub with ->cmid.
     * @param string $nodekey The navigation node key (e.g. mod_examcheck_scanner).
     * @return bool
     */
    protected function has_settings_node(\stdClass $course, \stdClass $examcheck, string $nodekey): bool {
        $cm = get_fast_modinfo($course)->get_cm($examcheck->cmid);
        $page = new \moodle_page();
        $page->set_course($course);
        $page->set_cm($cm, $course);
        $page->set_url('/mod/examcheck/view.php', ['id' => $cm->id]);

        $settingsnav = new \settings_navigation($page);
        $node = \navigation_node::create('examcheck', null, \navigation_node::TYPE_SETTING, null, 'modulesettings');
        examcheck_extend_settings_navigation($settingsnav, $node);

        return (bool) $node->find($nodekey, \navigation_node::TYPE_SETTING);
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
