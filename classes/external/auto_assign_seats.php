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
use core_external\external_single_structure;
use core_external\external_value;
use mod_examcheck\local\checker;
use mod_examcheck\local\seats;
use moodle_exception;

/**
 * Web service: seat every remaining student on a remaining seat, at random.
 *
 * Returns a counter block rather than {@see \mod_examcheck\local\seat_outcome},
 * which is shaped around a single seat. The counters let the page update its
 * "N of M seats assigned" line and hide the trigger once there is nothing left
 * to assign, without a reload.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class auto_assign_seats extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'Course module id'),
        ]);
    }

    /**
     * Seat the caller's unseated students on the free seats, at random.
     *
     * @param int $cmid Course module id.
     * @return array Counters plus a localised summary message.
     */
    public static function execute(int $cmid): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['cmid' => $cmid]);

        $checker = checker::from_cmid($params['cmid']);
        self::validate_context($checker->get_context());
        require_capability('mod/examcheck:manageseats', $checker->get_context());

        if (empty($checker->get_instance()->enableseats)) {
            throw new moodle_exception('seatsdisabled', 'mod_examcheck');
        }

        $examcheckid = (int) $checker->get_instance()->id;

        // Resolve the group the caller is effectively confined to, so a
        // separate-groups teacher only ever seats their own students. Never call
        // require_group_access(0) here: it throws for group-restricted teachers.
        $group = $checker->resolve_effective_group(0);
        $pool = [];
        if ($group !== -1) {
            $seated = seats::get_user_seat_labels($examcheckid);
            $pool = array_keys(array_diff_key($checker->get_roster($group), $seated));
        }

        $assigned = seats::auto_assign($examcheckid, $pool, (int) $USER->id);

        $seatcount = seats::count_seats($examcheckid);
        $left = count($pool) - $assigned;

        return [
            'assigned'         => $assigned,
            'unseatedstudents' => $left,
            'freeseats'        => $seatcount - seats::count_assignments($examcheckid),
            'seatcount'        => $seatcount,
            'message'          => get_string(
                $left > 0 ? 'autoassignresultwithleft' : 'autoassignresult',
                'mod_examcheck',
                (object) ['assigned' => $assigned, 'left' => $left]
            ),
        ];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'assigned'         => new external_value(PARAM_INT, 'Students seated by this call.'),
            'unseatedstudents' => new external_value(PARAM_INT, 'Reachable students still without a seat.'),
            'freeseats'        => new external_value(PARAM_INT, 'Seats still empty.'),
            'seatcount'        => new external_value(PARAM_INT, 'Total seats in the activity.'),
            'message'          => new external_value(PARAM_TEXT, 'Localised summary message.'),
        ]);
    }
}
