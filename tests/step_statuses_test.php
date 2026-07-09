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

/**
 * Tests that the per-step status table template renders.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \core\output\mustache_template_source_loader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class step_statuses_test extends \advanced_testcase {
    /**
     * The table renders initials-only headers (full name on hover / for screen
     * readers), checked/unchecked icons, and highlights the current step column.
     */
    public function test_renders_columns_icons_and_current_highlight(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_examcheck/scan_result_modal/step_statuses', [
            'steps' => [
                ['name' => 'Attendance', 'abbr' => 'A', 'checked' => true, 'current' => false],
                ['name' => 'Identity verification', 'abbr' => 'IV', 'checked' => false, 'current' => true],
            ],
        ]);

        // Headers show the initials big, with the full name available on hover and to
        // screen readers (title + visually-hidden text).
        $this->assertStringContainsString('examcheck-stepabbr', $html);
        $this->assertStringContainsString('IV', $html);
        $this->assertStringContainsString('title="Identity verification"', $html);

        // Checked mirrors the roster's green tick; unchecked uses the outline square.
        $this->assertStringContainsString('fa-check', $html);
        $this->assertStringContainsString('fa-square-o', $html);

        // The current step column is highlighted via its own class (light-blue
        // background), not the old Bootstrap table-active accent.
        $this->assertStringContainsString('examcheck-currentstep', $html);
        $this->assertStringNotContainsString('table-active', $html);
    }

    /**
     * Both scanner modals embed the step-status table partial and render cleanly.
     */
    public function test_both_modals_embed_the_step_table(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $context = [
            'userFullname'  => 'Jane Doe',
            'userPicture'   => '',
            'scanFieldName' => 'ID number',
            'scanValue'     => 'EX4',
            'rosterLink'    => 'https://example.org/mod/examcheck/view.php?id=2&userid=6',
            'stepName'      => 'ID verified',
            'steps'         => [
                ['name' => 'Attendance', 'abbr' => 'A', 'checked' => true, 'current' => false],
                ['name' => 'ID verified', 'abbr' => 'IV', 'checked' => false, 'current' => true],
            ],
        ];

        $confirm = $OUTPUT->render_from_template('mod_examcheck/confirm_modal', $context);
        $this->assertStringContainsString('examcheck-step-statuses', $confirm);
        $this->assertStringContainsString('ID verified', $confirm);

        $info = $OUTPUT->render_from_template('mod_examcheck/info_modal', $context);
        $this->assertStringContainsString('examcheck-step-statuses', $info);
    }
}
