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
 * Behat step definitions for mod_examcheck: uncheck documentation, student
 * flags, and step exemptions (issue #16).
 *
 * If this file is ever merged alongside the behat_mod_examcheck.php built for
 * issue #10 (roster quick-filter), the two will need to be combined by hand —
 * both define the class `behat_mod_examcheck` and both define "the following
 * mod_examcheck steps exist:", "I mark :studentname as checked on step
 * :stepname", since both branches were built independently against main.
 *
 * The Save/Cancel button selectors below (data-action="save",
 * data-action="cancel") were verified against Moodle core's actual
 * lib/templates/modal_save_cancel.mustache rather than assumed.
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
        $button = $this->find_toggle_button($studentname, $stepname);
        $button->click();
        $this->getSession()->wait(2000, "document.readyState === 'complete'");
    }

    /**
     * Set the uncheck documentation mode for a step directly via the DB,
     * bypassing the manage.php step-edit form.
     *
     * @Given the :stepname step has uncheck mode :mode
     *
     * @param string $stepname Name of the step.
     * @param string $mode     One of "none", "optional", "mandatory".
     */
    public function the_step_has_uncheck_mode(string $stepname, string $mode): void {
        global $DB;

        $map = ['none' => 0, 'optional' => 1, 'mandatory' => 2];
        if (!isset($map[$mode])) {
            throw new \Behat\Behat\Tester\Exception\PendingException(
                'Uncheck mode must be one of: none, optional, mandatory.'
            );
        }

        $step = $DB->get_record('examcheck_steps', ['name' => $stepname], '*', MUST_EXIST);
        \mod_examcheck\local\steps::save_step_uncheck_settings((int) $step->id, $map[$mode], 1);
    }

    /**
     * Click a named student's toggle button for a named step, without
     * asserting the resulting state (used to trigger the uncheck dialog).
     *
     * @When I click on the :stepname toggle for :studentname
     *
     * @param string $stepname    Name of the step.
     * @param string $studentname Full name of the student.
     */
    public function i_click_the_toggle_for(string $stepname, string $studentname): void {
        $button = $this->find_toggle_button($studentname, $stepname);
        $button->click();
    }

    /**
     * Assert the checked/unchecked/exempt state of a student's toggle button
     * for a named step.
     *
     * @Then the :stepname toggle for :studentname should be :state
     *
     * @param string $stepname    Name of the step.
     * @param string $studentname Full name of the student.
     * @param string $state       One of "checked", "unchecked", "exempt".
     */
    public function the_toggle_for_should_be(string $stepname, string $studentname, string $state): void {
        $session = $this->getSession();
        $button  = $this->find_toggle_button($studentname, $stepname);

        if ($state === 'exempt') {
            $exempt = $button->getAttribute('data-exempt');
            if ($exempt !== '1') {
                throw new \Behat\Mink\Exception\ExpectationException(
                    "Expected the \"{$stepname}\" toggle for \"{$studentname}\" to be exempt.", $session
                );
            }
            return;
        }

        $expected = ($state === 'checked') ? '1' : '0';
        $actual   = $button->getAttribute('data-checked');
        if ($actual !== $expected) {
            throw new \Behat\Mink\Exception\ExpectationException(
                "Expected the \"{$stepname}\" toggle for \"{$studentname}\" to be {$state} " .
                "(data-checked=\"{$expected}\"), found \"{$actual}\".",
                $session
            );
        }
    }

    /**
     * Add a student flag directly via the plugin's business logic.
     *
     * @Given student :studentname is flagged as :flagtype with note :note in :activityname
     *
     * @param string $studentname Full name of the student.
     * @param string $flagtype    One of the flag_manager TYPE_* values (e.g. "malpractice").
     * @param string $note        The note to attach to the flag.
     * @param string $activityname The examcheck activity name.
     */
    public function student_is_flagged(string $studentname, string $flagtype, string $note, string $activityname): void {
        global $DB, $USER;

        [$examcheckid, $userid] = $this->resolve_examcheck_and_user($activityname, $studentname);

        \mod_examcheck\local\flag_manager::add_flag($examcheckid, $userid, $flagtype, $note, (int) $USER->id);
    }

    /**
     * Grant a step exemption directly via the plugin's business logic.
     *
     * @Given student :studentname is exempt from step :stepname in :activityname
     *
     * @param string $studentname  Full name of the student.
     * @param string $stepname     Name of the step.
     * @param string $activityname The examcheck activity name.
     */
    public function student_is_exempt(string $studentname, string $stepname, string $activityname): void {
        global $DB, $USER;

        [$examcheckid, $userid] = $this->resolve_examcheck_and_user($activityname, $studentname);
        $step = $DB->get_record('examcheck_steps', ['examcheckid' => $examcheckid, 'name' => $stepname], '*', MUST_EXIST);

        \mod_examcheck\local\exemption_manager::grant_exemption(
            $examcheckid, (int) $step->id, $userid, '', (int) $USER->id
        );
    }

    /**
     * Resolve an examcheck instance id and a student's user id from their
     * visible names, for use by the flag/exemption Given steps.
     *
     * @param string $activityname The examcheck activity name.
     * @param string $studentname  The student's full name ("Firstname Lastname").
     * @return array{0: int, 1: int} [examcheckid, userid]
     */
    protected function resolve_examcheck_and_user(string $activityname, string $studentname): array {
        global $DB;

        $examcheck = $DB->get_record('examcheck', ['name' => $activityname], '*', MUST_EXIST);

        [$firstname, $lastname] = array_pad(explode(' ', $studentname, 2), 2, '');
        $user = $DB->get_record('user', ['firstname' => $firstname, 'lastname' => $lastname], '*', MUST_EXIST);

        return [(int) $examcheck->id, (int) $user->id];
    }

    /**
     * Locate a student's toggle button for a named step in the roster.
     *
     * Matches by the button's data-stepid attribute (resolved from the step's
     * name via the DB) rather than its title text: the title text only
     * contains the step name while UNCHECKED ("Mark \"Attendance\""); once
     * checked it becomes "Checked by {user} ({time})" with no step name at
     * all, which would silently fail to find the button in that state.
     *
     * @param string $studentname Full name of the student.
     * @param string $stepname    Name of the step.
     * @return \Behat\Mink\Element\NodeElement
     */
    protected function find_toggle_button(string $studentname, string $stepname): \Behat\Mink\Element\NodeElement {
        global $DB;

        $session = $this->getSession();
        $page    = $session->getPage();

        $step = $DB->get_record('examcheck_steps', ['name' => $stepname], '*', MUST_EXIST);

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

        $button = $targetrow->find('css', '[data-action="examcheck-toggle"][data-stepid="' . (int) $step->id . '"]');
        if (!$button) {
            throw new \Behat\Mink\Exception\ElementNotFoundException(
                $session, 'toggle button for step', 'css',
                '[data-action="examcheck-toggle"][data-stepid="' . (int) $step->id . '"]'
            );
        }

        return $button;
    }
}
