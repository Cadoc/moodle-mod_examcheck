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
 * @package    mod_examcheck
 * @category   test
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_mod_examcheck extends behat_base {

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

        // Find the roster row by student name.
        $namelink = $page->find('css', '.examcheck-studentname');
        if (!$namelink) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'student name link', 'css', '.examcheck-studentname'
            );
        }

        // Find all student rows and locate the one matching the student name.
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

        // Find the toggle button for the named step. The button title contains
        // the step name (via markaction or checkedbyon strings).
        $buttons = $targetrow->findAll('css', '[data-action="examcheck-toggle"]');
        $button  = null;
        foreach ($buttons as $btn) {
            if (str_contains((string) $btn->getAttribute('title'), $stepname)) {
                $button = $btn;
                break;
            }
        }

        if (!$button) {
            // Fall back: click the first unchecked toggle in the row.
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

        // Wait for the AJAX response and any confirmation modal to resolve.
        $this->getSession()->wait(2000, "document.querySelector('[data-action=\"examcheck-toggle\"]') !== null");
    }

    /**
     * Apply a named check status filter chip via the datafilter bar.
     *
     * @When I apply the :optionlabel check status filter in the roster
     *
     * @param string $optionlabel The visible label of the filter option (e.g. "Attendance: not checked").
     */
    public function i_apply_check_status_filter(string $optionlabel): void {
        $session = $this->getSession();
        $page    = $session->getPage();

        // Open the "Add filter" dropdown in the datafilter bar.
        $addfilter = $page->find('css', '[data-filterregion="filteroptions"] button, .datafilter .btn');
        if (!$addfilter) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'Add filter button', 'css', '[data-filterregion="filteroptions"] button'
            );
        }
        $addfilter->click();

        // Wait for the dropdown to open and click the "Check status" option.
        $this->getSession()->wait(1000);

        $checkstatuslink = $page->find('xpath',
            '//a[contains(., "' . get_string('checkstatus', 'mod_examcheck') . '")]'
            . '|//button[contains(., "' . get_string('checkstatus', 'mod_examcheck') . '")]'
        );
        if (!$checkstatuslink) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'Check status filter option', 'text', get_string('checkstatus', 'mod_examcheck')
            );
        }
        $checkstatuslink->click();

        $this->getSession()->wait(1000);

        // Select the specific option (e.g. "Attendance: not checked").
        $option = $page->find('xpath', '//option[contains(., "' . $optionlabel . '")]');
        if (!$option) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'filter option', 'text', $optionlabel
            );
        }
        $option->click();

        // Click the Apply button.
        $apply = $page->find('css', '[data-filterregion="filter"] [data-action="filter-add"],'
            . '[data-filteraction="save"]');
        if ($apply) {
            $apply->click();
        }

        // Wait for the dynamic table to reload.
        $this->getSession()->wait(3000,
            "document.querySelector('.examcheck-roster tbody tr') !== null"
        );
    }

    /**
     * Search for a term using the datafilter keyword chip.
     *
     * @When I search for :term in the roster keyword filter
     *
     * @param string $term The search term.
     */
    public function i_search_keyword_in_roster(string $term): void {
        $session = $this->getSession();
        $page    = $session->getPage();

        $addfilter = $page->find('css', '[data-filterregion="filteroptions"] button');
        if (!$addfilter) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'Add filter button', 'css', '[data-filterregion="filteroptions"] button'
            );
        }
        $addfilter->click();
        $this->getSession()->wait(500);

        $keywordlink = $page->find('xpath',
            '//a[contains(., "' . get_string('searchstudents', 'mod_examcheck') . '")]'
        );
        if ($keywordlink) {
            $keywordlink->click();
            $this->getSession()->wait(500);
        }

        $input = $page->find('css', '[data-filterregion="filter"] input[type="text"]');
        if (!$input) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'keyword filter input', 'css', 'input[type="text"]'
            );
        }
        $input->setValue($term);
        $input->keyPress("\n");

        $this->getSession()->wait(3000,
            "document.querySelector('.examcheck-roster tbody tr') !== null"
        );
    }

    /**
     * Remove all active check status filter chips from the datafilter bar.
     *
     * @When I remove all check status filter chips from the roster
     */
    public function i_remove_all_checkstatus_chips(): void {
        $page = $this->getSession()->getPage();

        $chips = $page->findAll('css',
            '[data-filterregion="filter"][data-filter-type="checkstatus"] [data-filteraction="remove"],'
            . '[data-filterregion="filter"][data-filter-type="checkstatus"] [data-action="remove"]'
        );

        foreach ($chips as $chip) {
            $chip->click();
            $this->getSession()->wait(1000);
        }

        // Wait for the dynamic table to reload after the last chip is removed.
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
