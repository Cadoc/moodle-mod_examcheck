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

/**
 * Behat step definitions for mod_examcheck.
 *
 * The quick search input is created dynamically by amd/src/roster_quicksearch.js
 * and has no name/id/associated label, so it cannot be targeted with the core
 * "I set the field ... to ..." step (which relies on Mink's findField()). This
 * custom step locates it via its data-region attribute instead.
 *
 * @package    mod_examcheck
 * @category   test
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_examcheck extends behat_base {

    /**
     * Create a check step directly via the plugin's business logic, bypassing
     * the manage.php UI entirely.
     *
     * This avoids two fragile assumptions this suite would otherwise have to
     * make: that the "Manage steps" settings-menu link is visible and
     * clickable without first opening an action menu (theme-dependent), and
     * that Moodle's generic "the following '$component > $entity' exist"
     * generator step resolves to this plugin's create_step() method without
     * being able to verify that resolution in an environment without a full
     * Moodle install to run Behat against.
     *
     * @Given the following mod_examcheck steps exist:
     *
     * @param \Behat\Gherkin\Node\TableNode $data Table with columns: examcheck, name.
     *                                             "examcheck" must match the idnumber of an
     *                                             existing mod_examcheck course module.
     */
    public function the_following_examcheck_steps_exist(\Behat\Gherkin\Node\TableNode $data): void {
        global $DB;

        foreach ($data->getHash() as $row) {
            if (!isset($row['examcheck'], $row['name'])) {
                throw new \Behat\Behat\Tester\Exception\PendingException(
                    'The "mod_examcheck steps" table requires "examcheck" and "name" columns.'
                );
            }

            $cm = $DB->get_record('course_modules', ['idnumber' => $row['examcheck']], '*', MUST_EXIST);
            $examcheckid = (int) $cm->instance;

            \mod_examcheck\local\steps::add_step($examcheckid, $row['name']);
        }
    }

    /**
     * Type a value into the roster quick search box, simulating real keystrokes
     * so the module's input-event listener (and its debounce) fires exactly as
     * it would for a real invigilator typing on the keyboard.
     *
     * Waits out the module's 300ms debounce delay afterwards so subsequent
     * "I should (not) see" assertions run against the settled DOM state.
     *
     * @When I type :value into the roster quick search
     *
     * @param string $value The text to type. An empty string clears the field.
     */
    public function i_type_into_the_roster_quick_search(string $value): void {
        $session = $this->getSession();
        $page    = $session->getPage();

        $input = $page->find('css', '[data-region="examcheck-quicksearch"]');
        if (!$input) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session,
                'roster quick search input',
                'css',
                '[data-region="examcheck-quicksearch"]'
            );
        }

        // setValue() on the Selenium driver sends real key events, so the
        // module's 'input' listener fires per keystroke just like it would
        // for a person typing, correctly exercising the debounce timer.
        $input->setValue($value);

        // The module debounces at 300ms; wait comfortably past that before
        // the DOM is considered settled for follow-up assertions.
        $this->getSession()->wait(600, 'true');
    }
}
