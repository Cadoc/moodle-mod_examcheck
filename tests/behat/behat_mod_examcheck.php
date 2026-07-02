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

use Behat\Gherkin\Node\TableNode;

/**
 * Behat step definitions for mod_examcheck.
 *
 * The core datafilter interaction steps below were written against the actual
 * markup in core (lib/templates/datafilter/*.mustache and
 * lib/amd/src/datafilter.js), not guessed: the "Add condition" button
 * (data-filteraction="add") appends a filter row containing a real
 * <select data-filterfield="type"> populated from the filtertypes passed to
 * the template; choosing an option there is what reveals the value picker in
 * that row's [data-filterregion="value"]. Applying uses
 * button[data-filteraction="apply"] (type=submit); clearing a single row uses
 * button[data-filteraction="remove"] inside that row; clearing everything
 * uses button[data-filteraction="reset"].
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
     * This avoids relying on Moodle's generic "the following '$component >
     * $entity' exist" generator step resolving correctly to this plugin's
     * create_step() method, which could not be verified without a full
     * Moodle install to run Behat against.
     *
     * @Given the following mod_examcheck steps exist:
     *
     * @param \Behat\Gherkin\Node\TableNode $data Table with columns: examcheck, name.
     *                                             "examcheck" must match the idnumber of an
     *                                             existing mod_examcheck course module.
     */
    public function the_following_examcheck_steps_exist(TableNode $data): void {
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
     * Mark a named student as checked on the named step by clicking their
     * toggle button in the roster.
     *
     * @When I mark :studentname as checked on step :stepname
     *
     * @param string $studentname Full name of the student.
     * @param string $stepname    Name of the step to mark.
     */
    public function i_mark_student_as_checked_on_step(string $studentname, string $stepname): void {
        $session = $this->getSession();
        $page    = $session->getPage();

        $rows = $page->findAll('css', '.examcheck-roster tbody tr');
        $targetrow = null;
        foreach ($rows as $row) {
            $link = $row->find('css', '.examcheck-studentname');
            if ($link && trim($link->getText()) === $studentname) {
                $targetrow = $row;
                break;
            }
        }

        if (!$targetrow) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'roster row for student', 'text', $studentname
            );
        }

        $buttons = $targetrow->findAll('css', '[data-action="examcheck-toggle"]');
        $button  = null;
        foreach ($buttons as $btn) {
            if (str_contains((string) $btn->getAttribute('title'), $stepname)) {
                $button = $btn;
                break;
            }
        }

        if (!$button) {
            foreach ($buttons as $btn) {
                if ($btn->getAttribute('data-checked') === '0') {
                    $button = $btn;
                    break;
                }
            }
        }

        if (!$button) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'unchecked toggle button', 'css', '[data-action="examcheck-toggle"][data-checked="0"]'
            );
        }

        $button->click();
        $this->getSession()->wait(2000, "document.querySelector('[data-action=\"examcheck-toggle\"]') !== null");
    }

    /**
     * Apply a "check status" filter chip for a given step and status via the
     * real core datafilter UI: click "Add condition", choose "Check status"
     * in the new row's type select, then select the matching value option
     * and click Apply.
     *
     * @When I apply the :stepname check status filter set to :status in the roster
     *
     * @param string $stepname Name of the step (e.g. "Attendance").
     * @param string $status   Either "checked" or "not checked".
     */
    public function i_apply_check_status_filter(string $stepname, string $status): void {
        $session = $this->getSession();
        $page    = $session->getPage();

        $dashboard = $page->find('css', '[data-region="examcheck-dashboard"]');
        if (!$dashboard) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'examcheck dashboard', 'css', '[data-region="examcheck-dashboard"]'
            );
        }

        // "Add condition" appends a new [data-filterregion="filter"] row.
        $existingrows = count($dashboard->findAll('css', '[data-filterregion="filter"]'));
        $addbutton = $dashboard->find('css', '[data-filteraction="add"]');
        if (!$addbutton) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'Add condition button', 'css', '[data-filteraction="add"]'
            );
        }
        $addbutton->click();
        $this->getSession()->wait(1000, sprintf(
            "document.querySelectorAll('[data-filterregion=\"filter\"]').length > %d", $existingrows
        ));

        // The newest row is the one we just added.
        $rows = $dashboard->findAll('css', '[data-filterregion="filter"]');
        $newrow = end($rows);

        $typeselect = $newrow->find('css', 'select[data-filterfield="type"]');
        if (!$typeselect) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'filter type select', 'css', 'select[data-filterfield="type"]'
            );
        }
        $typeselect->selectOption(get_string('checkstatus', 'mod_examcheck'));
        $this->getSession()->wait(1000);

        // Selecting the type populates [data-filterregion="value"] with the value picker.
        $statuskey = ($status === 'checked') ? 'checkstatus_optionchecked' : 'checkstatus_optionnotchecked';
        $optionlabel = get_string($statuskey, 'mod_examcheck', $stepname);

        $valueregion = $newrow->find('css', '[data-filterregion="value"]');
        if (!$valueregion) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'filter value region', 'css', '[data-filterregion="value"]'
            );
        }

        // The value picker may render as a native <select> (multiple) or as a
        // core/form/autocomplete widget backed by a hidden <select>; try the
        // visible option element first, which covers both by matching the
        // option text inside whichever control is present.
        $option = $valueregion->find('xpath', './/option[contains(., "' . $optionlabel . '")]');
        if (!$option) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'filter value option', 'text', $optionlabel
            );
        }
        $option->click();

        $applybutton = $dashboard->find('css', '[data-filteraction="apply"]');
        if (!$applybutton) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'Apply filters button', 'css', '[data-filteraction="apply"]'
            );
        }
        $applybutton->click();

        $this->getSession()->wait(3000, "document.querySelector('.examcheck-roster tbody tr') !== null");
    }

    /**
     * Remove every active filter via the single "Clear filters" reset button.
     *
     * @When I clear all roster filters
     */
    public function i_clear_all_roster_filters(): void {
        $session = $this->getSession();
        $page    = $session->getPage();

        $resetbutton = $page->find('css', '[data-region="examcheck-dashboard"] [data-filteraction="reset"]');
        if (!$resetbutton) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'Clear filters button', 'css', '[data-filteraction="reset"]'
            );
        }
        $resetbutton->click();

        $this->getSession()->wait(2000);
    }

    /**
     * Assert that the named quick-filter tab button is visually active
     * (has the btn-secondary CSS class, not btn-outline-secondary).
     *
     * @Then the :tabname quick filter tab should be active
     *
     * @param string $tabname The visible text of the tab button.
     */
    public function the_quick_filter_tab_should_be_active(string $tabname): void {
        $session = $this->getSession();
        $page    = $session->getPage();

        $button = $page->find('xpath',
            '//div[@data-region="examcheck-quickfilter"]//button[contains(., "' . $tabname . '")]'
        );

        if (!$button) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'quick filter tab button', 'text', $tabname
            );
        }

        $classes = $button->getAttribute('class') ?? '';
        if (!str_contains($classes, 'btn-secondary') || str_contains($classes, 'btn-outline-secondary')) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "The quick filter tab \"{$tabname}\" is not active. Classes: {$classes}",
                $session
            );
        }

        $pressed = $button->getAttribute('aria-pressed');
        if ($pressed !== 'true') {
            throw new \Behat\Mink\Exception\ExpectationException(
                "The quick filter tab \"{$tabname}\" has aria-pressed=\"{$pressed}\", expected \"true\".",
                $session
            );
        }
    }
}
