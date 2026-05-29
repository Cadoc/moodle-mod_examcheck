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

namespace mod_examcheck\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_examcheck\local\checker;

/**
 * Web service: mark or unmark a step for many students at once.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class bulk_action extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT, 'Course module id'),
            'stepid'  => new external_value(PARAM_INT, 'Check step id'),
            'userids' => new external_multiple_structure(new external_value(PARAM_INT, 'Student user id')),
            'action'  => new external_value(PARAM_ALPHA, 'Either "mark" or "unmark"'),
            'groupid' => new external_value(PARAM_INT, 'Group context (0 for all)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Apply a check or uncheck to each of the given students for one step.
     *
     * Marks already recorded by another teacher are reported as conflicts (mark) or skipped
     * (unmark without the override capability); neither aborts the batch.
     *
     * @param int $cmid Course module id.
     * @param int $stepid Check step id.
     * @param int[] $userids Student user ids.
     * @param string $action "mark" or "unmark".
     * @param int $groupid Group context.
     * @return array Tally of the outcome.
     */
    public static function execute(int $cmid, int $stepid, array $userids, string $action, int $groupid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid, 'stepid' => $stepid, 'userids' => $userids, 'action' => $action, 'groupid' => $groupid,
        ]);

        $checker = checker::from_cmid($params['cmid']);
        self::validate_context($checker->get_context());
        require_capability('mod/examcheck:check', $checker->get_context());
        $checker->require_group_access($params['groupid']);

        $domark = $params['action'] !== 'unmark';
        $done = $conflicts = $skipped = $notinroster = 0;

        foreach ($params['userids'] as $userid) {
            if ($domark) {
                $result = $checker->mark_user($params['stepid'], $userid, (int) $USER->id, 'list', $params['groupid']);
                switch ($result['status']) {
                    case 'marked':
                        $done++;
                        break;
                    case 'conflict':
                        $conflicts++;
                        break;
                    default:
                        $notinroster++;
                }
            } else {
                try {
                    $result = $checker->unmark_user($params['stepid'], $userid, (int) $USER->id);
                    if ($result['status'] === 'unmarked') {
                        $done++;
                    } else {
                        $skipped++;
                    }
                } catch (\moodle_exception $e) {
                    // Removing another teacher's mark without the override capability lands here.
                    $skipped++;
                }
            }
        }

        $total = count($params['userids']);
        return [
            'done'        => $done,
            'conflicts'   => $conflicts,
            'skipped'     => $skipped,
            'notinroster' => $notinroster,
            'total'       => $total,
            'message'     => get_string('bulkresult', 'mod_examcheck', (object) [
                'done'    => $done,
                'skipped' => $conflicts + $skipped + $notinroster,
            ]),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'done'        => new external_value(PARAM_INT, 'Number of students updated'),
            'conflicts'   => new external_value(PARAM_INT, 'Already checked by someone else (mark only)'),
            'skipped'     => new external_value(PARAM_INT, 'Skipped (e.g. not checked, or override denied)'),
            'notinroster' => new external_value(PARAM_INT, 'Not on the roster for this group'),
            'total'       => new external_value(PARAM_INT, 'Number of students requested'),
            'message'     => new external_value(PARAM_TEXT, 'Localised summary message'),
        ]);
    }
}
