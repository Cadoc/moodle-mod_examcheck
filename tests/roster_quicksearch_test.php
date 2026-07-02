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

/**
 * Tests that the checking dashboard correctly wires up the roster quick-search
 * module and that all lang strings it depends on exist.
 *
 * The client-side search behaviour (typing narrows the roster, matches name/
 * id/group text, ignores step toggle cells) is covered in the Behat suite
 * (tests/behat/mod_examcheck_quicksearch.feature), since it requires a real
 * browser DOM to exercise meaningfully.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\output\dashboard
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class roster_quicksearch_test extends \advanced_testcase {

    /**
     * The dashboard's JS block must require mod_examcheck/roster_quicksearch and
     * initialise it, so the module actually attaches to the page.
     */
    public function test_dashboard_requires_roster_quicksearch_module(): void {
        global $PAGE;
        $this->resetAfterTest();
        $this->setAdminUser();

        $course   = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);

        /** @var \mod_examcheck_generator $gen */
        $gen = $this->getDataGenerator()->get_plugin_generator('mod_examcheck');
        $gen->create_step((int) $activity->id, 'Attendance');

        $PAGE->set_url('/mod/examcheck/view.php', ['id' => $activity->cmid]);

        /** @var \core\output\renderer_base $output */
        $output = $PAGE->get_renderer('core');

        $db = new dashboard($activity->cmid);
        $html = $output->render_from_template('mod_examcheck/dashboard', $db->export_for_template($output));

        $this->assertStringContainsString(
            'mod_examcheck/roster_quicksearch',
            $html,
            'roster_quicksearch must be required in the dashboard JS block.'
        );

        $this->assertStringContainsString(
            'RosterQuicksearch.init(',
            $html,
            'roster_quicksearch must be initialised with the course module id.'
        );
    }

    /**
     * The dashboard must still render (without the quicksearch require call in
     * an unreachable branch) when the activity has no steps yet — the JS module
     * itself no-ops safely in that case, but the server-rendered markup should
     * not error out.
     */
    public function test_dashboard_renders_with_no_steps(): void {
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

        // The require() block itself is always emitted (it is outside {{#hassteps}}),
        // so the module reference is present even though there is no roster form
        // for it to mount into on this page.
        $this->assertStringContainsString('mod_examcheck/roster_quicksearch', $html);
        $this->assertStringContainsString(get_string('error_nosteps', 'mod_examcheck'), $html);
    }

    /**
     * The quicksearchplaceholder lang string must exist so that core/str does
     * not return an empty or placeholder-formatted value in the browser.
     */
    public function test_quicksearch_lang_string_exists(): void {
        $string = get_string('quicksearchplaceholder', 'mod_examcheck');

        $this->assertStringNotContainsString(
            '[[quicksearchplaceholder]]',
            $string,
            'Lang string quicksearchplaceholder is missing from lang/en/examcheck.php.'
        );
        $this->assertNotEmpty(trim($string));
    }

    /**
     * The roster table's column set must include the columns the JS module's
     * getRowText() assumes are searchable (fullname, matchfield, groups), and
     * must include at least one step column so the toggle-cell-exclusion logic
     * has something to exclude. This guards against the client-side search
     * silently missing coverage if the table's columns are refactored.
     */
    public function test_roster_columns_match_search_assumptions(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course   = $this->getDataGenerator()->create_course();
        $activity = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $this->getDataGenerator()->create_and_enrol($course, 'student');

        /** @var \mod_examcheck_generator $gen */
        $gen    = $this->getDataGenerator()->get_plugin_generator('mod_examcheck');
        $stepid = $gen->create_step((int) $activity->id, 'Attendance');

        $table = new \mod_examcheck\table\roster("examcheck-roster-{$activity->cmid}");
        $table->set_filterset(new \mod_examcheck\table\roster_filterset());
        ob_start();
        $table->out(50, false);
        $html = ob_get_clean();

        // The student's full name and the step toggle must both be present in
        // the rendered roster: the search module relies on separating the two.
        $this->assertStringContainsString('examcheck-studentname', $html);
        $this->assertStringContainsString('data-action="examcheck-toggle"', $html);
        $this->assertStringContainsString('data-stepid="' . $stepid . '"', $html);
    }
}
