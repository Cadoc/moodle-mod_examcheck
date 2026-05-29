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
 * Web service: return the full current mark state for live dashboard refresh.
 *
 * Returning the complete state (rather than a delta) lets the dashboard
 * reconcile both new marks and marks removed by other teachers in one call.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_marks extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'    => new external_value(PARAM_INT, 'Course module id'),
            'groupid' => new external_value(PARAM_INT, 'Group context (0 for all)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Return the current marks and per-step progress for the group's roster.
     *
     * @param int $cmid Course module id.
     * @param int $groupid Group context.
     * @return array
     */
    public static function execute(int $cmid, int $groupid = 0): array {
        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid, 'groupid' => $groupid]);

        $checker = checker::from_cmid($params['cmid']);
        self::validate_context($checker->get_context());
        require_capability('mod/examcheck:view', $checker->get_context());
        $checker->require_group_access($params['groupid']);

        $rostermap = array_fill_keys($checker->get_roster_ids($params['groupid']), true);

        $marks = [];
        foreach ($checker->get_marks() as $stepid => $byuser) {
            foreach ($byuser as $userid => $mark) {
                if (!isset($rostermap[$userid])) {
                    continue;
                }
                $marks[] = [
                    'stepid'        => (int) $mark->stepid,
                    'userid'        => (int) $mark->userid,
                    'checkedby'     => (int) $mark->checkedby,
                    'checkedbyname' => checker::user_label((int) $mark->checkedby),
                    'timecreated'   => (int) $mark->timecreated,
                    'ago'           => checker::relative_time((int) $mark->timecreated),
                ];
            }
        }

        $progress = [];
        foreach ($checker->get_progress($params['groupid']) as $stepid => $count) {
            $progress[] = ['stepid' => (int) $stepid, 'count' => (int) $count];
        }

        return [
            'marks'    => $marks,
            'progress' => $progress,
            'total'    => count($rostermap),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'marks' => new external_multiple_structure(new external_single_structure([
                'stepid'        => new external_value(PARAM_INT, 'Step id'),
                'userid'        => new external_value(PARAM_INT, 'Student id'),
                'checkedby'     => new external_value(PARAM_INT, 'Teacher who recorded the mark'),
                'checkedbyname' => new external_value(PARAM_TEXT, 'Teacher full name'),
                'timecreated'   => new external_value(PARAM_INT, 'When the mark was recorded'),
                'ago'           => new external_value(PARAM_TEXT, 'How long ago, in words'),
            ])),
            'progress' => new external_multiple_structure(new external_single_structure([
                'stepid' => new external_value(PARAM_INT, 'Step id'),
                'count'  => new external_value(PARAM_INT, 'Number of checked students'),
            ])),
            'total' => new external_value(PARAM_INT, 'Number of students on the roster'),
        ]);
    }
}
