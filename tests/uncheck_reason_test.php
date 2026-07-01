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

declare(strict_types=1);

namespace mod_examcheck;

use mod_examcheck\local\checker;
use mod_examcheck\local\exemption_manager;
use mod_examcheck\local\flag_manager;
use mod_examcheck\local\uncheck_reason;

/**
 * Tests for uncheck documentation, student flags and step exemptions (issue #16).
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\local\uncheck_reason
 * @covers     \mod_examcheck\local\flag_manager
 * @covers     \mod_examcheck\local\exemption_manager
 * @covers     \mod_examcheck\local\checker::unmark_user
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class uncheck_reason_test extends \advanced_testcase {

    // -------------------------------------------------------------------------
    // uncheck_reason
    // -------------------------------------------------------------------------

    /**
     * All default reason keys must have corresponding lang strings.
     *
     * @dataProvider default_reason_keys_provider
     */
    public function test_default_reason_lang_strings_exist(string $key): void {
        $string = get_string('uncheckreason_' . $key, 'mod_examcheck');
        $this->assertStringNotContainsString('[[', $string,
            "Lang string 'uncheckreason_{$key}' is missing.");
        $this->assertNotEmpty(trim($string));
    }

    /**
     * Data provider: all built-in reason keys.
     *
     * @return array<array<string>>
     */
    public static function default_reason_keys_provider(): array {
        return array_map(fn($k) => [$k], uncheck_reason::DEFAULT_KEYS);
    }

    /**
     * get_for_instance returns all defaults when uncheckreasons is empty.
     */
    public function test_get_for_instance_returns_all_defaults_when_empty(): void {
        $this->resetAfterTest();
        $examcheck = (object) ['uncheckreasons' => ''];
        $reasons = uncheck_reason::get_for_instance($examcheck);
        $this->assertCount(count(uncheck_reason::DEFAULT_KEYS), $reasons);
        foreach ($reasons as $reason) {
            $this->assertArrayHasKey('key', $reason);
            $this->assertArrayHasKey('label', $reason);
        }
    }

    /**
     * get_for_instance honours a custom comma-separated list.
     */
    public function test_get_for_instance_honours_custom_list(): void {
        $this->resetAfterTest();
        $examcheck = (object) ['uncheckreasons' => 'markedinerror,fallill'];
        $reasons = uncheck_reason::get_for_instance($examcheck);
        $this->assertCount(2, $reasons);
        $this->assertSame('markedinerror', $reasons[0]['key']);
        $this->assertSame('fallill', $reasons[1]['key']);
    }

    /**
     * is_valid_key accepts known keys and rejects unknown ones.
     */
    public function test_is_valid_key(): void {
        $this->assertTrue(uncheck_reason::is_valid_key('markedinerror'));
        $this->assertTrue(uncheck_reason::is_valid_key(''));
        $this->assertFalse(uncheck_reason::is_valid_key('notavalidkey'));
    }

    // -------------------------------------------------------------------------
    // flag_manager
    // -------------------------------------------------------------------------

    /**
     * Adding a flag stores it and it is returned by get_flags_for_user.
     */
    public function test_add_and_retrieve_flag(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course    = $this->getDataGenerator()->create_course();
        $activity  = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student   = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher   = $this->getDataGenerator()->create_and_enrol($course, 'teacher');

        $flag = flag_manager::add_flag(
            (int) $activity->id,
            (int) $student->id,
            flag_manager::TYPE_MALPRACTICE,
            'Observed copying',
            (int) $teacher->id
        );

        $this->assertSame(flag_manager::TYPE_MALPRACTICE, $flag->flagtype);
        $this->assertSame('Observed copying', $flag->note);

        $flags = flag_manager::get_flags_for_user((int) $activity->id, (int) $student->id);
        $this->assertCount(1, $flags);
        $this->assertSame((string) $flag->id, (string) $flags[0]->id);
    }

    /**
     * remove_flag deletes the record and returns true.
     */
    public function test_remove_flag(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course   = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student  = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher  = $this->getDataGenerator()->create_and_enrol($course, 'teacher');

        $flag = flag_manager::add_flag(
            (int) $activity->id, (int) $student->id,
            flag_manager::TYPE_OTHER, '', (int) $teacher->id
        );

        $this->assertTrue(flag_manager::remove_flag((int) $flag->id, (int) $activity->id));
        $this->assertEmpty(flag_manager::get_flags_for_user((int) $activity->id, (int) $student->id));
    }

    /**
     * is_valid_type accepts known types and rejects unknown ones.
     */
    public function test_is_valid_type(): void {
        $this->assertTrue(flag_manager::is_valid_type(flag_manager::TYPE_MALPRACTICE));
        $this->assertTrue(flag_manager::is_valid_type(flag_manager::TYPE_EXCLUDED));
        $this->assertFalse(flag_manager::is_valid_type('unknown_type'));
    }

    // -------------------------------------------------------------------------
    // exemption_manager
    // -------------------------------------------------------------------------

    /**
     * Granting an exemption stores it and is_exempt returns true.
     */
    public function test_grant_and_check_exemption(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course   = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student  = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher  = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        /** @var \mod_examcheck_generator $gen */
        $gen    = $this->getDataGenerator()->get_plugin_generator('mod_examcheck');
        $stepid = $gen->create_step((int) $activity->id, 'Attendance');

        $record = exemption_manager::grant_exemption(
            (int) $activity->id, $stepid, (int) $student->id, 'Wheelchair user', (int) $teacher->id
        );

        $this->assertNotEmpty($record->id);
        $this->assertTrue(exemption_manager::is_exempt($stepid, (int) $student->id));
    }

    /**
     * Revoking an exemption removes it.
     */
    public function test_revoke_exemption(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course   = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student  = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher  = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        /** @var \mod_examcheck_generator $gen */
        $gen    = $this->getDataGenerator()->get_plugin_generator('mod_examcheck');
        $stepid = $gen->create_step((int) $activity->id, 'Attendance');

        exemption_manager::grant_exemption(
            (int) $activity->id, $stepid, (int) $student->id, '', (int) $teacher->id
        );
        $this->assertTrue(exemption_manager::revoke_exemption($stepid, (int) $student->id));
        $this->assertFalse(exemption_manager::is_exempt($stepid, (int) $student->id));
    }

    // -------------------------------------------------------------------------
    // checker::unmark_user with reason
    // -------------------------------------------------------------------------

    /**
     * Unmarking with uncheckmode=0 (no documentation) succeeds without a reason.
     */
    public function test_unmark_no_documentation_required(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course   = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student  = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher  = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        /** @var \mod_examcheck_generator $gen */
        $gen    = $this->getDataGenerator()->get_plugin_generator('mod_examcheck');
        $stepid = $gen->create_step((int) $activity->id, 'Attendance');

        $checker = checker::from_cmid((int) $activity->cmid);
        $checker->mark_user($stepid, (int) $student->id, (int) $teacher->id);

        $result = $checker->unmark_user($stepid, (int) $student->id, (int) $teacher->id);
        $this->assertSame('unmarked', $result['status']);
        $this->assertFalse($DB->record_exists('examcheck_uncheck_events',
            ['stepid' => $stepid, 'userid' => $student->id]));
    }

    /**
     * Unmarking with uncheckmode=2 (mandatory) without a reason returns reasonrequired.
     */
    public function test_unmark_mandatory_reason_required(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course   = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student  = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher  = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        /** @var \mod_examcheck_generator $gen */
        $gen    = $this->getDataGenerator()->get_plugin_generator('mod_examcheck');
        $stepid = $gen->create_step((int) $activity->id, 'Attendance');

        // Set uncheckmode = 2 on the step.
        $DB->set_field('examcheck_steps', 'uncheckmode', 2, ['id' => $stepid]);

        $checker = checker::from_cmid((int) $activity->cmid);
        $checker->mark_user($stepid, (int) $student->id, (int) $teacher->id);

        $result = $checker->unmark_user($stepid, (int) $student->id, (int) $teacher->id, '', '');
        $this->assertSame('reasonrequired', $result['status']);
    }

    /**
     * Unmarking with uncheckmode=2 and a valid reason succeeds and logs the event.
     */
    public function test_unmark_mandatory_with_reason_logs_event(): void {
        global $DB;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course   = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student  = $this->getDataGenerator()->create_and_enrol($course, 'student');
        $teacher  = $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        /** @var \mod_examcheck_generator $gen */
        $gen    = $this->getDataGenerator()->get_plugin_generator('mod_examcheck');
        $stepid = $gen->create_step((int) $activity->id, 'Attendance');

        $DB->set_field('examcheck_steps', 'uncheckmode', 2, ['id' => $stepid]);

        $checker = checker::from_cmid((int) $activity->cmid);
        $checker->mark_user($stepid, (int) $student->id, (int) $teacher->id);

        $result = $checker->unmark_user(
            $stepid, (int) $student->id, (int) $teacher->id, 'markedinerror', ''
        );

        $this->assertSame('unmarked', $result['status']);

        $event = $DB->get_record('examcheck_uncheck_events',
            ['stepid' => $stepid, 'userid' => $student->id]);
        $this->assertNotEmpty($event);
        $this->assertSame('markedinerror', $event->reasonkey);
    }
}
