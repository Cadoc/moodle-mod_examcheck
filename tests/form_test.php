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

use mod_examcheck\form\step_form;
use mod_examcheck\local\steps;

/**
 * Tests for the add/edit step form's "another step checked" requirement.
 *
 * @package    mod_examcheck
 * @category   test
 * @covers     \mod_examcheck\form\step_form
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class form_test extends \advanced_testcase {
    /**
     * The "Another step checked" type is not offered when the activity has no other
     * step to depend on (a single-step activity, or adding the very first step).
     */
    public function test_step_option_hidden_when_no_other_steps(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $only = (int) array_values(steps::get_steps($examcheck->id))[0]->id;

        $form = $this->build_form($course, $examcheck, $only);

        $this->assertNotContains('step', $this->option_values($form, 'requirementtype'));
    }

    /**
     * With another step present, the type is offered and the prerequisite picker
     * lists every other step but never the one being edited.
     */
    public function test_step_option_shown_and_excludes_current_step(): void {
        $this->resetAfterTest();
        $this->setAdminUser();

        $course = $this->getDataGenerator()->create_course();
        $examcheck = $this->getDataGenerator()->create_module('examcheck', ['course' => $course->id]);
        $first = (int) array_values(steps::get_steps($examcheck->id))[0]->id;
        $second = steps::add_step($examcheck->id, 'Identity');

        // Editing the second step: only the first step is a selectable prerequisite.
        $form = $this->build_form($course, $examcheck, $second);

        $this->assertContains('step', $this->option_values($form, 'requirementtype'));

        $stepoptions = $this->option_values($form, 'requirementstepid');
        $this->assertContains((string) $first, $stepoptions);
        $this->assertNotContains((string) $second, $stepoptions);
    }

    /**
     * Build the step form for a given "current" step, wiring the same custom data
     * that manage.php passes.
     *
     * @param \stdClass $course The course.
     * @param \stdClass $examcheck The instance (with ->cmid).
     * @param int $currentstepid The step being edited (0 when adding).
     * @return step_form
     */
    private function build_form(\stdClass $course, \stdClass $examcheck, int $currentstepid): step_form {
        $cm = get_coursemodule_from_instance('examcheck', $examcheck->id, $course->id, false, MUST_EXIST);
        return new step_form(new \moodle_url('/mod/examcheck/manage.php'), [
            'courseid'      => $course->id,
            'cmid'          => $cm->id,
            'examcheckid'   => $examcheck->id,
            'currentstepid' => $currentstepid,
        ]);
    }

    /**
     * Read the option values of a select element from a built form.
     *
     * @param step_form $form The built form.
     * @param string $element The element name.
     * @return string[] The option values, or [] when the element is not a select.
     */
    private function option_values(step_form $form, string $element): array {
        $property = new \ReflectionProperty(\moodleform::class, '_form');
        $property->setAccessible(true);
        $mform = $property->getValue($form);

        if (!$mform->elementExists($element)) {
            return [];
        }
        $el = $mform->getElement($element);
        if (!($el instanceof \MoodleQuickForm_select)) {
            return [];
        }
        return array_map(static fn($option) => (string) ($option['attr']['value'] ?? ''), $el->_options);
    }
}
