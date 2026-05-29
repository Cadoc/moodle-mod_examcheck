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

use mod_examcheck\table\roster;
use mod_examcheck\table\roster_filterset;

/**
 * Tests that the roster dynamic table renders server-side without error.
 *
 * Covers the rendering path the dynamic-table AJAX endpoint exercises (set_filterset,
 * query_db and the cell methods); the JS/AJAX wiring itself is verified in the browser.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\table\roster
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class roster_table_test extends \advanced_testcase {
    /**
     * The roster renders one toggle button per student/step and shows student names.
     */
    public function test_roster_renders(): void {
        global $PAGE, $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        // Configure email as an identity field; admin has moodle/site:viewuseridentity.
        $CFG->showuseridentity = 'email';

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student = $this->getDataGenerator()->create_and_enrol(
            $course,
            'student',
            ['firstname' => 'Ann', 'lastname' => 'Other', 'email' => 'ann.other@example.com']
        );
        // A teacher is a checker and must be excluded from the roster.
        $this->getDataGenerator()->create_and_enrol($course, 'editingteacher');

        // Production sets the page url in view.php; the dynamic table reads it on render.
        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $examcheck->cmid]);

        $table = new roster("examcheck-roster-{$examcheck->cmid}");
        $table->set_filterset(new roster_filterset());

        ob_start();
        $table->out(1000, false);
        $html = ob_get_clean();

        $this->assertStringContainsString('Ann Other', $html);
        $this->assertStringContainsString('data-action="toggle"', $html);
        // The dynamic-table wrapper that the AJAX refresh targets must be present.
        $this->assertStringContainsString('core_table/dynamic', $html);
        // The email identity column shows because the viewer may see it.
        $this->assertStringContainsString('ann.other@example.com', $html);
        // Row-selection checkbox is present for bulk actions.
        $this->assertStringContainsString("data-togglegroup=\"examcheck-roster\"", $html);
        // The student name links to their profile.
        $this->assertStringContainsString('/user/view.php', $html);
    }
}
