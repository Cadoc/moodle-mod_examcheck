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

namespace mod_examcheck\local;

use moodle_exception;

/**
 * Create, read, reorder and delete the ordered check steps of an instance.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class steps {
    /**
     * Create the single default "Attendance" step for a brand new instance.
     *
     * @param int $examcheckid The instance id.
     * @return int The id of the created step.
     */
    public static function seed_default_step(int $examcheckid): int {
        return self::add_step($examcheckid, get_string('defaultstepname', 'mod_examcheck'));
    }

    /**
     * Return all steps for an instance, ordered by sortorder.
     *
     * @param int $examcheckid The instance id.
     * @return \stdClass[] Step records keyed by step id.
     */
    public static function get_steps(int $examcheckid): array {
        global $DB;
        return $DB->get_records('examcheck_steps', ['examcheckid' => $examcheckid], 'sortorder ASC, id ASC');
    }

    /**
     * Add a step to the end of the list.
     *
     * @param int $examcheckid The instance id.
     * @param string $name The step name.
     * @return int The id of the created step.
     */
    public static function add_step(int $examcheckid, string $name): int {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            throw new moodle_exception('required');
        }

        $now = time();
        $step = (object) [
            'examcheckid' => $examcheckid,
            'name'        => $name,
            'sortorder'   => self::next_sortorder($examcheckid),
            'timecreated' => $now,
            'timemodified' => $now,
        ];

        return (int) $DB->insert_record('examcheck_steps', $step);
    }

    /**
     * Rename an existing step.
     *
     * @param int $stepid The step id.
     * @param string $name The new step name.
     */
    public static function rename_step(int $stepid, string $name): void {
        global $DB;

        $name = trim($name);
        if ($name === '') {
            throw new moodle_exception('required');
        }

        $DB->update_record('examcheck_steps', (object) [
            'id'           => $stepid,
            'name'         => $name,
            'timemodified' => time(),
        ]);
    }

    /**
     * Delete a step and every mark recorded against it.
     *
     * An instance must always keep at least one step, so deleting the last
     * remaining step is refused.
     *
     * @param int $stepid The step id.
     * @throws moodle_exception When attempting to delete the last step.
     */
    public static function delete_step(int $stepid): void {
        global $DB;

        $step = $DB->get_record('examcheck_steps', ['id' => $stepid], '*', MUST_EXIST);

        if (self::count_steps($step->examcheckid) <= 1) {
            throw new moodle_exception('cannotdeletelaststep', 'mod_examcheck');
        }

        $DB->delete_records('examcheck_marks', ['stepid' => $stepid]);
        $DB->delete_records('examcheck_steps', ['id' => $stepid]);

        self::normalise_sortorder($step->examcheckid);
    }

    /**
     * Move a step one position up or down in the ordering.
     *
     * @param int $stepid The step id.
     * @param int $direction -1 to move up, +1 to move down.
     */
    public static function move_step(int $stepid, int $direction): void {
        global $DB;

        if ($direction !== -1 && $direction !== 1) {
            return;
        }

        $step = $DB->get_record('examcheck_steps', ['id' => $stepid], '*', MUST_EXIST);
        $steps = array_values(self::get_steps($step->examcheckid));

        // Locate the step within the ordered list.
        $index = null;
        foreach ($steps as $i => $candidate) {
            if ((int) $candidate->id === $stepid) {
                $index = $i;
                break;
            }
        }

        $swapwith = $index + $direction;
        if ($index === null || $swapwith < 0 || $swapwith >= count($steps)) {
            return;
        }

        // Swap the sortorder of the two neighbouring steps.
        $a = $steps[$index];
        $b = $steps[$swapwith];
        $now = time();
        $DB->update_record('examcheck_steps', (object) ['id' => $a->id, 'sortorder' => $b->sortorder, 'timemodified' => $now]);
        $DB->update_record('examcheck_steps', (object) ['id' => $b->id, 'sortorder' => $a->sortorder, 'timemodified' => $now]);
    }

    /**
     * Count the steps belonging to an instance.
     *
     * @param int $examcheckid The instance id.
     * @return int
     */
    public static function count_steps(int $examcheckid): int {
        global $DB;
        return $DB->count_records('examcheck_steps', ['examcheckid' => $examcheckid]);
    }

    /**
     * Compute the next sortorder value for a new step.
     *
     * @param int $examcheckid The instance id.
     * @return int
     */
    protected static function next_sortorder(int $examcheckid): int {
        global $DB;
        $max = $DB->get_field('examcheck_steps', 'MAX(sortorder)', ['examcheckid' => $examcheckid]);
        return ($max === null || $max === false) ? 0 : ((int) $max + 1);
    }

    /**
     * Re-pack sortorder values into a contiguous 0..n-1 sequence.
     *
     * @param int $examcheckid The instance id.
     */
    protected static function normalise_sortorder(int $examcheckid): void {
        global $DB;
        $steps = self::get_steps($examcheckid);
        $order = 0;
        foreach ($steps as $step) {
            if ((int) $step->sortorder !== $order) {
                $DB->set_field('examcheck_steps', 'sortorder', $order, ['id' => $step->id]);
            }
            $order++;
        }
    }
}
