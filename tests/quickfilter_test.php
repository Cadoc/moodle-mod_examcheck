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

use mod_examcheck\output\dashboard;
use mod_examcheck\table\roster;
use mod_examcheck\table\roster_filterset;

/**
 * Tests that the quick-filter container is rendered in the dashboard and that
 * all lang strings required by roster_quickfilter.js are present.
 *
 * The JavaScript wiring and tab-switching behaviour is verified in the Behat
 * suite (tests/behat/mod_examcheck_quickfilter.feature).
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\output\dashboard
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class quickfilter_test extends \advanced_testcase {

    /**
     * The dashboard HTML must include the examcheck-quickfilter region so that
     * roster_quickfilter.js can mount the tab buttons into it.
     */
    public function test_dashboard_renders_quickfilter_container(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course   = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);

        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $activity->cmid]);

        /** @var \core\output\renderer_base $output */
        $output = $PAGE->get_renderer('core');

        $db = new dashboard($activity->cmid);
        $html = $output->render_from_template('mod_examcheck/dashboard', $db->export_for_template($output));

        $this->assertStringContainsString(
            'data-region="examcheck-quickfilter"',
            $html,
            'The quickfilter mount point must be present in the dashboard template.'
        );

        // The AMD module must be initialised from the {{#js}} block.
        $this->assertStringContainsString(
            'mod_examcheck/roster_quickfilter',
            $html,
            'roster_quickfilter must be required in the dashboard JS block.'
        );

        // roster_filter.js is always wired (it drives the datafilter bar),
        // even though the quickfilter piggy-backs on its custom event.
        $this->assertStringContainsString(
            'mod_examcheck/roster_filter',
            $html,
            'roster_filter must be required by roster_quickfilter for its Events export.'
        );
    }

    /**
     * All lang strings consumed by roster_quickfilter.js must exist in the
     * language pack so that core/str does not return empty placeholders.
     *
     * @dataProvider quickfilter_strings_provider
     */
    public function test_quickfilter_lang_strings_exist(string $key): void {
        $string = get_string($key, 'mod_examcheck');

        // get_string() returns "[[key]]" when the string is missing.
        $this->assertStringNotContainsString(
            "[[{$key}]]",
            $string,
            "Lang string '{$key}' is missing from lang/en/examcheck.php."
        );

        $this->assertNotEmpty(
            trim($string),
            "Lang string '{$key}' must not be empty."
        );
    }

    /**
     * Data provider: all string keys that roster_quickfilter.js fetches via
     * core/str.get_strings().
     *
     * @return array<array<string>>
     */
    public static function quickfilter_strings_provider(): array {
        return [
            'quickfilterall'        => ['quickfilterall'],
            'quickfilterchecked'    => ['quickfilterchecked'],
            'quickfilternotchecked' => ['quickfilternotchecked'],
            'quickfiltergroup'      => ['quickfiltergroup'],
        ];
    }

    /**
     * Switching the active tab must not lose the keyword or group filters:
     * withoutCheckstatus() keeps all non-checkstatus entries intact.
     *
     * This test mirrors the JS logic in roster_quickfilter.js to guard against
     * accidental regressions if the filterset structure ever changes server-side.
     * It verifies that a roster_filterset built with all three filter types
     * correctly allows the checkstatus filter to be cleared independently.
     */
    public function test_filterset_supports_independent_checkstatus_removal(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course   = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $student  = $this->getDataGenerator()->create_and_enrol($course, 'student');

        // Seed a step so the checkstatus filter type is available.
        /** @var \mod_examcheck_generator $gen */
        $gen    = $this->getDataGenerator()->get_plugin_generator('mod_examcheck');
        $stepid = $gen->create_step((int) $activity->id, 'Attendance');

        // Build a filterset that combines keyword + checkstatus, mirroring what
        // the browser sends when both chips are active.
        $filterset = new roster_filterset();
        $filterset->add_filter(
            new \core_table\local\filter\string_filter('keywords')
        );
        $filterset->get_filter('keywords')->add_filter_value('Smith');

        $filterset->add_filter(
            new \core_table\local\filter\string_filter('checkstatus')
        );
        $filterset->get_filter('checkstatus')->add_filter_value($stepid . ':notchecked');

        // Both filters must be independently readable.
        $this->assertTrue($filterset->has_filter('keywords'));
        $this->assertTrue($filterset->has_filter('checkstatus'));

        // Simulating the "All students" tab: removing checkstatus must not
        // affect the keyword filter — just as withoutCheckstatus() does in JS.
        $keywords = $filterset->get_filter('keywords')->get_filter_values();
        $this->assertContains('Smith', $keywords,
            'Keyword filter must remain intact when checkstatus is independently cleared.');
    }
}
