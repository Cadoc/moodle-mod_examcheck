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

declare(strict_types=1);

namespace mod_examcheck\completion;

use core_completion\activity_custom_completion;
use mod_examcheck\local\steps;

/**
 * Custom completion rules for the examcheck activity.
 *
 * The "completionchecked" rule completes the activity for a student once they
 * have been checked on every required step. The required steps are configured
 * on the instance ("completionsteps"); an empty configuration means all steps.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class custom_completion extends activity_custom_completion {

    /**
     * Compute the completion state of a rule for the current user.
     *
     * @param string $rule The rule name.
     * @return int COMPLETION_COMPLETE or COMPLETION_INCOMPLETE.
     */
    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $examcheck = $DB->get_record('examcheck', ['id' => $this->cm->instance], '*', MUST_EXIST);
        $required = self::resolve_required_steps($examcheck);

        if (empty($required)) {
            // No steps to satisfy: treat as not yet complete.
            return COMPLETION_INCOMPLETE;
        }

        foreach ($required as $stepid) {
            if (!$DB->record_exists('examcheck_marks', ['stepid' => $stepid, 'userid' => $this->userid])) {
                return COMPLETION_INCOMPLETE;
            }
        }

        return COMPLETION_COMPLETE;
    }

    /**
     * Resolve the step ids that must be checked for completion.
     *
     * @param \stdClass $examcheck The instance record.
     * @return int[] The required step ids.
     */
    public static function resolve_required_steps(\stdClass $examcheck): array {
        $allsteps = array_map('intval', array_keys(steps::get_steps((int) $examcheck->id)));

        $configured = array_filter(array_map('intval',
            array_filter(array_map('trim', explode(',', (string) ($examcheck->completionsteps ?? ''))), 'strlen')));

        if (empty($configured)) {
            return $allsteps;
        }

        // Only keep configured steps that still exist.
        return array_values(array_intersect($configured, $allsteps));
    }

    /**
     * The custom rules defined by this module.
     *
     * @return string[]
     */
    public static function get_defined_custom_rules(): array {
        return ['completionchecked'];
    }

    /**
     * Descriptions of each custom rule.
     *
     * @return array<string, string>
     */
    public function get_custom_rule_descriptions(): array {
        return [
            'completionchecked' => get_string('completionchecked_desc', 'mod_examcheck'),
        ];
    }

    /**
     * The display order of all completion rules.
     *
     * @return string[]
     */
    public function get_sort_order(): array {
        return [
            'completionview',
            'completionchecked',
        ];
    }
}
