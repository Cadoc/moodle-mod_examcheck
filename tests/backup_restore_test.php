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
use mod_examcheck\local\steps;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/course/lib.php');

/**
 * Backup and restore tests for the per-step requirements and the seats.
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

    /**
     * Duplicating an activity copies the seat list (structure) but not the
     * assignments: duplication never carries user data.
     */
    public function test_duplicate_copies_seat_list_without_assignments(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        seats::replace_list($examcheck->id, ['A1', 'A2']);
        $seatid = (int) array_key_first(seats::get_seats($examcheck->id));
        seats::assign($seatid, (int) $student->id, (int) $teacher->id);

        $cm = get_coursemodule_from_instance('examcheck', $examcheck->id, $course->id, false, MUST_EXIST);
        $newcm = duplicate_module($course, $cm);

        $copylabels = array_map(fn($seat) => $seat->label, array_values(seats::get_seats($newcm->instance)));
        $this->assertSame(['A1', 'A2'], $copylabels);
        $this->assertSame(0, seats::count_assignments((int) $newcm->instance));
    }

    /**
     * Duplicating an activity preserves the seats toggle: a copy of a
     * seats-disabled activity stays disabled.
     */
    public function test_duplicate_preserves_enableseats(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id, 'enableseats' => 0]);

        $cm = get_coursemodule_from_instance('examcheck', $examcheck->id, $course->id, false, MUST_EXIST);
        $newcm = duplicate_module($course, $cm);

        $this->assertSame(0, (int) $DB->get_field('examcheck', 'enableseats', ['id' => $newcm->instance]));
    }

    /**
     * A full backup and restore with user data carries the assignments across,
     * remapped to the restored seats with the assigner preserved.
     */
    public function test_backup_restore_seats_with_userinfo(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        seats::replace_list($examcheck->id, ['A1', 'A2']);
        $seatlist = array_values(seats::get_seats($examcheck->id));
        seats::assign((int) $seatlist[1]->id, (int) $student->id, (int) $teacher->id);

        $newcourseid = $this->backup_and_restore($course, true);

        $restored = $this->find_restored_instance($newcourseid);
        $labels = array_map(fn($seat) => $seat->label, array_values(seats::get_seats((int) $restored->id)));
        $this->assertSame(['A1', 'A2'], $labels);

        // The assignment followed the student onto the restored "A2" seat.
        $userlabels = seats::get_user_seat_labels((int) $restored->id);
        $this->assertSame('A2', $userlabels[(int) $student->id]);
        $assignments = array_values(seats::get_assignments((int) $restored->id));
        $this->assertCount(1, $assignments);
        $this->assertEquals((int) $teacher->id, (int) $assignments[0]->assignedby);
    }

    /**
     * Restoring without user data keeps the seat list but drops the assignments.
     */
    public function test_backup_restore_seats_without_userinfo(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        seats::replace_list($examcheck->id, ['A1']);
        $seatid = (int) array_key_first(seats::get_seats($examcheck->id));
        seats::assign($seatid, (int) $student->id, (int) $teacher->id);

        $newcourseid = $this->backup_and_restore($course, false);

        $restored = $this->find_restored_instance($newcourseid);
        $this->assertSame(1, seats::count_seats((int) $restored->id));
        $this->assertSame(0, seats::count_assignments((int) $restored->id));
    }

    /**
     * Back a course up and restore it into a brand new course.
     *
     * @param \stdClass $srccourse The course to back up.
     * @param bool $userdata Whether to include user data.
     * @return int The id of the newly restored course.
     */
    private function backup_and_restore(\stdClass $srccourse, bool $userdata): int {
        global $CFG, $USER;

        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');

        // Turn off file logging, otherwise it can't delete the file (Windows).
        $CFG->backup_file_logger_level = \backup::LOG_NONE;

        $bc = new \backup_controller(
            \backup::TYPE_1COURSE,
            $srccourse->id,
            \backup::FORMAT_MOODLE,
            \backup::INTERACTIVE_NO,
            \backup::MODE_IMPORT,
            $USER->id
        );
        $bc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $bc->get_plan()->get_setting('users')->set_value($userdata);
        $backupid = $bc->get_backupid();
        $bc->execute_plan();
        $bc->destroy();

        $newcourseid = \restore_dbops::create_new_course(
            $srccourse->fullname,
            $srccourse->shortname . '_2',
            $srccourse->category
        );
        $rc = new \restore_controller(
            $backupid,
            $newcourseid,
            \backup::INTERACTIVE_NO,
            \backup::MODE_GENERAL,
            $USER->id,
            \backup::TARGET_NEW_COURSE
        );
        $rc->get_plan()->get_setting('users')->set_status(\backup_setting::NOT_LOCKED);
        $rc->get_plan()->get_setting('users')->set_value($userdata);
        $this->assertTrue($rc->execute_precheck());
        $rc->execute_plan();
        $rc->destroy();

        return $newcourseid;
    }

    /**
     * Find the single restored examcheck instance in a course.
     *
     * @param int $courseid The restored course id.
     * @return \stdClass The examcheck record.
     */
    private function find_restored_instance(int $courseid): \stdClass {
        global $DB;
        $instances = $DB->get_records('examcheck', ['course' => $courseid]);
        $this->assertCount(1, $instances);
        return reset($instances);
    }
}
