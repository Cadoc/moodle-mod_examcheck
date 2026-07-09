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

use mod_examcheck\form\seats_edit_form;
use mod_examcheck\form\seats_export_form;

/**
 * Tests for the seats form validation: the edit form's explicit "assignments will be
 * reset" acknowledgement, and the export form's scope.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\form\seats_edit_form
 * @covers     \mod_examcheck\form\seats_export_form
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class seats_form_test extends \advanced_testcase {
    /**
     * Overlong and duplicate labels are rejected on the textarea itself.
     */
    public function test_validation_rejects_bad_labels(): void {
        $this->resetAfterTest();
        $form = $this->build_form([], false);

        $errors = $form->validation(['seatlist' => str_repeat('x', 101)], []);
        $this->assertArrayHasKey('seatlist', $errors);

        $errors = $form->validation(['seatlist' => "A1\na1"], []);
        $this->assertArrayHasKey('seatlist', $errors);
    }

    /**
     * With assignments present, changing the list requires the acknowledgement
     * checkbox; an unchanged list or a ticked checkbox passes.
     */
    public function test_validation_requires_reset_acknowledgement(): void {
        $this->resetAfterTest();
        $form = $this->build_form(['A1', 'A2'], true);

        // Changed list, no acknowledgement: blocked on the checkbox.
        $errors = $form->validation(['seatlist' => "A1\nA2\nA3", 'confirmreset' => 0], []);
        $this->assertArrayHasKey('confirmreset', $errors);

        // Changed list, acknowledged: accepted.
        $errors = $form->validation(['seatlist' => "A1\nA2\nA3", 'confirmreset' => 1], []);
        $this->assertSame([], $errors);

        // Unchanged list: no acknowledgement needed.
        $errors = $form->validation(['seatlist' => "A1\nA2", 'confirmreset' => 0], []);
        $this->assertSame([], $errors);
    }

    /**
     * Without assignments the acknowledgement is never demanded.
     */
    public function test_validation_without_assignments(): void {
        $this->resetAfterTest();
        $form = $this->build_form(['A1'], false);

        $errors = $form->validation(['seatlist' => "B1\nB2"], []);
        $this->assertSame([], $errors);
    }

    /**
     * The export form accepts only the two scopes seats.php knows how to stream.
     */
    public function test_export_form_validates_the_scope(): void {
        $this->resetAfterTest();
        $form = new seats_export_form(new \moodle_url('/mod/examcheck/seats.php'));

        $this->assertSame([], $form->validation(['scope' => seats_export_form::SCOPE_SEATS], []));
        $this->assertSame([], $form->validation(['scope' => seats_export_form::SCOPE_SEATS_AND_STUDENTS], []));

        $this->assertArrayHasKey('scopegroup', $form->validation(['scope' => 'everything'], []));
        $this->assertArrayHasKey('scopegroup', $form->validation([], []));
    }

    /**
     * Build the edit form the way seats.php does.
     *
     * @param string[] $currentlabels The activity's current seat labels.
     * @param bool $hasassignments Whether assignments currently exist.
     * @return seats_edit_form
     */
    private function build_form(array $currentlabels, bool $hasassignments): seats_edit_form {
        return new seats_edit_form(new \moodle_url('/mod/examcheck/seats.php'), [
            'currentlabels'   => $currentlabels,
            'hasassignments'  => $hasassignments,
            'assignmentcount' => $hasassignments ? 1 : 0,
        ]);
    }
}
