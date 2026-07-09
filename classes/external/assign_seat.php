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
use mod_examcheck\local\seat_outcome;
use mod_examcheck\local\seats;
use moodle_exception;

/**
 * Web service: assign a student to a seat.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class assign_seat extends external_api {
    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'   => new external_value(PARAM_INT, 'Course module id'),
            'seatid' => new external_value(PARAM_INT, 'Seat id'),
            'userid' => new external_value(PARAM_INT, 'Student user id'),
        ]);
    }

    /**
     * Assign a student to a seat.
     *
     * @param int $cmid Course module id.
     * @param int $seatid Seat id.
     * @param int $userid Student user id.
     * @return array Outcome (see {@see seat_outcome::structure()}).
     */
    public static function execute(int $cmid, int $seatid, int $userid): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid, 'seatid' => $seatid, 'userid' => $userid,
        ]);

        $checker = checker::from_cmid($params['cmid']);
        self::validate_context($checker->get_context());
        require_capability('mod/examcheck:manageseats', $checker->get_context());

        // The seat must belong to this instance.
        $DB->get_record(
            'examcheck_seats',
            ['id' => $params['seatid'], 'examcheckid' => $checker->get_instance()->id],
            'id',
            MUST_EXIST
        );

        // The student must be on the roster and within the caller's group reach.
        if (!in_array($params['userid'], $checker->get_roster_ids(), true)) {
            throw new moodle_exception('error_usernotonroster', 'mod_examcheck');
        }
        $checker->require_user_access($params['userid']);

        $result = seats::assign($params['seatid'], $params['userid'], (int) $USER->id);

        return seat_outcome::format($result, $params['seatid']);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return seat_outcome::structure();
    }
}
