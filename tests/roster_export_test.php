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

use context_module;
use mod_examcheck\local\checker;
use mod_examcheck\local\roster_exporter;
use mod_examcheck\local\seats;
use mod_examcheck\local\steps;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Tests for the roster export builder, in particular its identity-field gate.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\local\roster_exporter
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class roster_export_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected $course;

    /** @var \stdClass The examcheck module stub. */
    protected $examcheck;

    /** @var context_module The module context. */
    protected $context;

    /** @var \stdClass The student. */
    protected $student;

    /** @var \stdClass The teacher recording the assignments. */
    protected $teacher;

    /**
     * Set up a course with one student and an editing teacher.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $this->course->id]);
        $this->context = context_module::instance($this->examcheck->cmid);
        $this->student = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            [
                'firstname' => 'Ann',
                'lastname'  => 'Other',
                'email'     => 'ann@example.com',
                'idnumber'  => 'STU-42',
            ]
        );
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setAdminUser();
    }

    /**
     * A viewer with identity access gets exactly the configured identity columns,
     * custom profile fields included.
     */
    public function test_identity_columns_follow_site_config(): void {
        global $CFG;

        $this->getDataGenerator()->create_custom_profile_field(
            ['datatype' => 'text', 'shortname' => 'dni', 'name' => 'DNI number']
        );
        profile_save_data((object) ['id' => $this->student->id, 'profile_field_dni' => 'X-7700']);
        $CFG->showuseridentity = 'idnumber,profile_field_dni';

        [$columns, $rows] = $this->build();

        // The step columns trail the identity block; only the block itself is under test.
        $this->assertSame(
            [get_string('lastname'), get_string('firstname'), get_string('idnumber'), 'DNI number'],
            array_slice($columns, 0, 4)
        );
        $this->assertSame(['Other', 'Ann', 'STU-42', 'X-7700'], array_slice($rows[0], 0, 4));
    }

    /**
     * Without moodle/site:viewuseridentity the export carries names only: no ID number,
     * no email, matching what the roster table shows that same viewer.
     */
    public function test_identity_columns_hidden_without_capability(): void {
        global $CFG;
        $CFG->showuseridentity = 'email,idnumber';

        // A role that may view the activity but not user identities.
        $roleid = create_role('Restricted viewer', 'restrictedviewer', '');
        assign_capability('mod/examcheck:view', CAP_ALLOW, $roleid, \context_system::instance()->id);
        $restricted = $this->getDataGenerator()->create_and_enrol($this->course, 'restrictedviewer');
        $this->setUser($restricted);

        [$columns, $rows] = $this->build();

        // Straight from the names to the step columns: no identity block at all.
        $this->assertSame([get_string('lastname'), get_string('firstname')], array_slice($columns, 0, 2));
        $this->assertNotContains(get_string('idnumber'), $columns);
        $this->assertNotContains(get_string('email'), $columns);
        // The values never reach the file, not even in an unlabelled column.
        $this->assertNotContains('STU-42', $rows[0]);
        $this->assertNotContains('ann@example.com', $rows[0]);
    }

    /**
     * The seat column appears whenever the activity has seats, even before anyone
     * is assigned to one.
     */
    public function test_seat_column_presence(): void {
        global $CFG;
        $CFG->showuseridentity = '';

        // No seats: no seat column.
        [$columns] = $this->build();
        $this->assertNotContains(get_string('seat', 'mod_examcheck'), $columns);

        // Seats but no assignment: the column shows, the cell is empty.
        seats::replace_list($this->examcheck->id, ['A7']);
        [$columns, $rows] = $this->build();
        $this->assertSame(
            [get_string('lastname'), get_string('firstname'), get_string('seat', 'mod_examcheck')],
            array_slice($columns, 0, 3)
        );
        $this->assertSame(['Other', 'Ann', ''], array_slice($rows[0], 0, 3));

        // Assigned: the label shows.
        $seatid = (int) array_key_first(seats::get_seats($this->examcheck->id));
        seats::assign($seatid, (int) $this->student->id, (int) $this->teacher->id);
        [, $rows] = $this->build();
        $this->assertSame(['Other', 'Ann', 'A7'], array_slice($rows[0], 0, 3));
    }

    /**
     * Build the export the way export.php does.
     *
     * @return array Two elements: string[] $columns and string[][] $rows.
     */
    private function build(): array {
        global $DB;

        $examcheck = $DB->get_record('examcheck', ['id' => $this->examcheck->id], '*', MUST_EXIST);
        $checker = new checker($examcheck, $this->context);
        $hasseats = seats::count_seats((int) $examcheck->id) > 0;

        return roster_exporter::columns_and_rows(
            $checker->get_roster(),
            array_values(steps::get_steps((int) $examcheck->id)),
            $checker->get_marks(),
            $hasseats,
            $hasseats ? seats::get_user_seat_labels((int) $examcheck->id) : [],
            $this->context
        );
    }
}
