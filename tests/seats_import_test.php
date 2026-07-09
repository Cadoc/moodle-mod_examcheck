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
use csv_import_reader;
use mod_examcheck\local\seats;
use mod_examcheck\local\seats_exporter;
use mod_examcheck\local\seats_importer;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/csvlib.class.php');
require_once($CFG->dirroot . '/user/profile/lib.php');

/**
 * Tests for the seat CSV importer and exporter, including the round-trip.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\local\seats_importer
 * @covers     \mod_examcheck\local\seats_exporter
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class seats_import_test extends \advanced_testcase {
    /** @var \stdClass The course. */
    protected $course;

    /** @var \stdClass The examcheck module stub. */
    protected $examcheck;

    /** @var context_module The module context. */
    protected $context;

    /** @var \stdClass The first student. */
    protected $ann;

    /** @var \stdClass The second student. */
    protected $bob;

    /** @var \stdClass The teacher. */
    protected $teacher;

    /**
     * Set up a course with two students and an editing teacher.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();

        $this->course = $this->getDataGenerator()->create_course();
        $this->examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $this->course->id]);
        $this->context = context_module::instance($this->examcheck->cmid);
        $this->ann = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['username' => 'ann', 'email' => 'ann@example.com', 'idnumber' => 'ID-ANN']
        );
        $this->bob = $this->getDataGenerator()->create_and_enrol(
            $this->course,
            'student',
            ['username' => 'bob', 'email' => 'bob@example.com', 'idnumber' => 'ID-BOB']
        );
        $this->teacher = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $this->setUser($this->teacher);
    }

    /**
     * Override mode creates the seat list from the file and assigns students.
     */
    public function test_override_creates_seats_and_assigns(): void {
        $importer = $this->importer(true);
        $cir = $this->reader("seat,username\nA1,ann\nA2,bob\nA3,\n");

        $this->assertSame([], $importer->validate($cir));
        $counts = $importer->apply();

        $this->assertSame(['seats' => 3, 'assigned' => 2, 'unassigned' => 0], $counts);
        $labels = seats::get_user_seat_labels($this->examcheck->id);
        $this->assertSame('A1', $labels[(int) $this->ann->id]);
        $this->assertSame('A2', $labels[(int) $this->bob->id]);
    }

    /**
     * Non-override mode only changes assignments: existing seats are kept,
     * students can be reseated (even swapped) and empty rows clear seats.
     */
    public function test_non_override_reassigns_and_clears(): void {
        seats::replace_list($this->examcheck->id, ['A1', 'A2', 'A3']);
        $seatids = array_map('intval', array_keys(seats::get_seats($this->examcheck->id)));
        seats::assign($seatids[0], (int) $this->ann->id, (int) $this->teacher->id);
        seats::assign($seatids[1], (int) $this->bob->id, (int) $this->teacher->id);

        // Swap Ann and Bob, and explicitly clear A3.
        $importer = $this->importer(false);
        $cir = $this->reader("seat,username\nA1,bob\nA2,ann\nA3,\n");

        $this->assertSame([], $importer->validate($cir));
        $counts = $importer->apply();

        $this->assertSame(3, $counts['seats']);
        $this->assertSame(2, $counts['assigned']);
        $labels = seats::get_user_seat_labels($this->examcheck->id);
        $this->assertSame('A2', $labels[(int) $this->ann->id]);
        $this->assertSame('A1', $labels[(int) $this->bob->id]);
    }

    /**
     * Students can be matched by email or idnumber when no username column is
     * present, following the confirmed priority.
     */
    public function test_match_by_email_and_idnumber(): void {
        $importer = $this->importer(true);
        $cir = $this->reader("seat,email\nA1,ANN@example.com\n");
        $this->assertSame([], $importer->validate($cir));
        $importer->apply();
        $this->assertSame('A1', seats::get_user_seat_labels($this->examcheck->id)[(int) $this->ann->id]);

        $importer = $this->importer(true);
        $cir = $this->reader("seat,idnumber\nB1,ID-BOB\n");
        $this->assertSame([], $importer->validate($cir));
        $importer->apply();
        $this->assertSame('B1', seats::get_user_seat_labels($this->examcheck->id)[(int) $this->bob->id]);
    }

    /**
     * Every validation failure is reported, keyed by line, and nothing is applied.
     */
    public function test_validation_failures_apply_nothing(): void {
        seats::replace_list($this->examcheck->id, ['A1']);
        $outsider = $this->getDataGenerator()->create_user(['username' => 'outsider']);

        $importer = $this->importer(false);
        $cir = $this->reader(
            "seat,username\n" .
            "A1,nosuchuser\n" .          // Line 2: unmatched student.
            "A1,outsider\n" .            // Line 3: duplicate seat + not on roster.
            "Z9,ann\n" .                 // Line 4: unknown seat (non-override).
            ",bob\n" .                   // Line 5: missing seat label.
            str_repeat('x', 101) . ",\n" // Line 6: label too long.
        );

        $errors = $importer->validate($cir);

        $this->assertArrayHasKey(2, $errors);
        $this->assertStringContainsString('nosuchuser', $errors[2][0]);
        $this->assertArrayHasKey(3, $errors);
        $this->assertCount(2, $errors[3]); // Duplicate seat AND not on roster.
        $this->assertArrayHasKey(4, $errors);
        $this->assertStringContainsString('Z9', $errors[4][0]);
        $this->assertArrayHasKey(5, $errors);
        $this->assertArrayHasKey(6, $errors);

        // Nothing was applied and apply() refuses to run.
        $this->assertSame(0, seats::count_assignments($this->examcheck->id));
        $this->expectException(\coding_exception::class);
        $importer->apply();
    }

    /**
     * A student listed twice, a missing seat column and an ambiguous match are
     * all rejected.
     */
    public function test_more_validation_failures(): void {
        // Duplicate student.
        $importer = $this->importer(true);
        $cir = $this->reader("seat,username\nA1,ann\nA2,ann\n");
        $errors = $importer->validate($cir);
        $this->assertArrayHasKey(3, $errors);
        $this->assertStringContainsString('ann', $errors[3][0]);

        // Missing seat column: reported against the header line.
        $importer = $this->importer(true);
        $cir = $this->reader("username\nann\n");
        $errors = $importer->validate($cir);
        $this->assertArrayHasKey(1, $errors);

        // Ambiguous idnumber shared by two roster students.
        global $DB;
        $DB->set_field('user', 'idnumber', 'SHARED', ['id' => $this->ann->id]);
        $DB->set_field('user', 'idnumber', 'SHARED', ['id' => $this->bob->id]);
        $importer = $this->importer(true);
        $cir = $this->reader("seat,idnumber\nA1,SHARED\n");
        $errors = $importer->validate($cir);
        $this->assertArrayHasKey(2, $errors);
        $this->assertStringContainsString('SHARED', $errors[2][0]);
    }

    /**
     * The export lists every seat in order with username and identity columns,
     * leaving unassigned seats empty; headers are stable machine names.
     */
    public function test_export_columns_and_rows(): void {
        global $CFG;
        $CFG->showuseridentity = 'email,idnumber';

        seats::replace_list($this->examcheck->id, ['A1', 'A2']);
        $seatids = array_map('intval', array_keys(seats::get_seats($this->examcheck->id)));
        seats::assign($seatids[0], (int) $this->ann->id, (int) $this->teacher->id);

        [$columns, $rows] = seats_exporter::columns_and_rows((int) $this->examcheck->id, $this->context);

        $this->assertSame(['seat', 'username', 'email', 'idnumber', 'fullname'], $columns);
        $this->assertSame(['A1', 'ann', 'ann@example.com', 'ID-ANN', fullname($this->ann)], $rows[0]);
        $this->assertSame(['A2', '', '', '', ''], $rows[1]);
    }

    /**
     * A viewer without identity-field access still exports seat and username,
     * so the file remains importable without leaking identity columns.
     */
    public function test_export_respects_identity_visibility(): void {
        global $CFG;
        $CFG->showuseridentity = 'email';

        seats::replace_list($this->examcheck->id, ['A1']);
        $seatid = (int) array_key_first(seats::get_seats($this->examcheck->id));
        seats::assign($seatid, (int) $this->ann->id, (int) $this->teacher->id);

        // A role that may manage seats but not view user identities.
        $roleid = create_role('Restricted teacher', 'restrictedteacher', '');
        assign_capability('mod/examcheck:view', CAP_ALLOW, $roleid, \context_system::instance()->id);
        assign_capability('mod/examcheck:manageseats', CAP_ALLOW, $roleid, \context_system::instance()->id);
        $restricted = $this->getDataGenerator()->create_and_enrol($this->course, 'restrictedteacher');
        $this->setUser($restricted);

        [$columns, $rows] = seats_exporter::columns_and_rows((int) $this->examcheck->id, $this->context);

        $this->assertSame(['seat', 'username', 'fullname'], $columns);
        $this->assertSame(['A1', 'ann', fullname($this->ann)], $rows[0]);
    }

    /**
     * A full export/import round-trip restores the exact seat list and
     * assignments, including custom profile fields in the identity columns.
     */
    public function test_export_import_round_trip(): void {
        global $CFG;

        // Identity includes a custom profile field (exporter passes true to
        // include them, unlike the roster table).
        $field = $this->getDataGenerator()->create_custom_profile_field([
            'shortname' => 'faculty', 'name' => 'Faculty', 'datatype' => 'text',
        ]);
        profile_save_data((object) ['id' => $this->ann->id, 'profile_field_faculty' => 'Science']);
        $CFG->showuseridentity = 'email,profile_field_faculty';

        seats::replace_list($this->examcheck->id, ['A1', 'A2', 'B1']);
        $seatids = array_map('intval', array_keys(seats::get_seats($this->examcheck->id)));
        seats::assign($seatids[0], (int) $this->ann->id, (int) $this->teacher->id);
        seats::assign($seatids[2], (int) $this->bob->id, (int) $this->teacher->id);

        [$columns, $rows] = seats_exporter::columns_and_rows((int) $this->examcheck->id, $this->context);
        $this->assertContains('profile_field_faculty', $columns);
        $csv = implode(',', $columns) . "\n";
        foreach ($rows as $row) {
            $csv .= implode(',', $row) . "\n";
        }

        // Wipe everything, then import the export back in override mode.
        seats::replace_list($this->examcheck->id, ['Z9']);
        $importer = $this->importer(true);
        $cir = $this->reader($csv);
        $this->assertSame([], $importer->validate($cir));
        $counts = $importer->apply();

        $this->assertSame(['seats' => 3, 'assigned' => 2, 'unassigned' => 0], $counts);
        $labels = array_map(fn($seat) => $seat->label, array_values(seats::get_seats($this->examcheck->id)));
        $this->assertSame(['A1', 'A2', 'B1'], $labels);
        $userlabels = seats::get_user_seat_labels($this->examcheck->id);
        $this->assertSame('A1', $userlabels[(int) $this->ann->id]);
        $this->assertSame('B1', $userlabels[(int) $this->bob->id]);
    }

    /**
     * Build an importer for the test instance.
     *
     * @param bool $override Whether the file replaces the seat list.
     * @return seats_importer
     */
    private function importer(bool $override): seats_importer {
        return new seats_importer((int) $this->examcheck->id, $this->context, $override);
    }

    /**
     * Load CSV content into a ready csv_import_reader.
     *
     * @param string $content The CSV text.
     * @return csv_import_reader
     */
    private function reader(string $content): csv_import_reader {
        $iid = csv_import_reader::get_new_iid('examcheckseatstest');
        $cir = new csv_import_reader($iid, 'examcheckseatstest');
        $this->assertGreaterThan(1, $cir->load_csv_content($content, 'UTF-8', 'comma'));
        return $cir;
    }
}
