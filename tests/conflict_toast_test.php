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
 * Tests that the scanner conflict toast template renders.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \core\output\mustache_template_source_loader
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class conflict_toast_test extends \advanced_testcase {
    /**
     * The conflict toast template renders the (already localised) message and a
     * "View in roster" link pointing at the given roster URL.
     */
    public function test_renders_message_and_roster_link(): void {
        global $OUTPUT;
        $this->resetAfterTest();

        $html = $OUTPUT->render_from_template('mod_examcheck/conflict_toast', [
            'message'    => 'Already checked: Jane Doe (EX4) was marked by Admin User, 4 seconds ago.',
            'rosterlink' => 'https://example.org/mod/examcheck/view.php?id=2&userid=6',
        ]);

        $this->assertStringContainsString('Already checked: Jane Doe (EX4)', $html);
        $this->assertStringContainsString('/mod/examcheck/view.php?id=2', $html);
        $this->assertStringContainsString('userid=6', $html);
        $this->assertStringContainsString(get_string('view_in_roster', 'mod_examcheck'), $html);
    }
}
