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

use core_external\external_single_structure;
use core_external\external_value;

/**
 * Shared web service return structure for a seat assignment outcome.
 *
 * The seat services return their own small structure rather than overloading
 * {@see outcome}, which is shaped around check marks. Like outcome, this
 * helper lives in classes/local/ so classes/external/ holds only the classes
 * registered as web service entry points.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seat_outcome {
    /**
     * The shared external return structure.
     *
     * @return external_single_structure
     */
    public static function structure(): external_single_structure {
        return new external_single_structure([
            'status'    => new external_value(PARAM_ALPHA, 'Outcome: assigned, conflict, unassigned or notassigned.'),
            'message'   => new external_value(PARAM_TEXT, 'Localised message ready to show to the teacher.'),
            'seatid'    => new external_value(PARAM_INT, 'The seat the action targeted.'),
            'userid'    => new external_value(PARAM_INT, 'The affected student id, or 0 when none.', VALUE_DEFAULT, 0),
            'userlabel' => new external_value(PARAM_TEXT, 'The student full name, when known.', VALUE_DEFAULT, ''),
            'conflictseatlabel' => new external_value(
                PARAM_TEXT,
                'For conflicts: the seat the student already occupies.',
                VALUE_DEFAULT,
                ''
            ),
        ]);
    }

    /**
     * Convert a {@see seats} result array into the external return structure,
     * building the localised message.
     *
     * @param array $result The seats result array (has a "status" key).
     * @param int $seatid The seat the action targeted.
     * @return array The normalised web service response.
     */
    public static function format(array $result, int $seatid): array {
        $status = $result['status'];
        $userlabel = $result['user'] ?? '';

        $response = [
            'status'    => $status,
            'message'   => '',
            'seatid'    => $seatid,
            'userid'    => (int) ($result['userid'] ?? 0),
            'userlabel' => $userlabel,
            'conflictseatlabel' => '',
        ];

        switch ($status) {
            case 'assigned':
                $response['message'] = get_string('seatresult_assigned', 'mod_examcheck', (object) [
                    'user' => $userlabel,
                    'seat' => $result['seatlabel'] ?? '',
                ]);
                break;

            case 'conflict':
                $response['conflictseatlabel'] = $result['seatlabel'] ?? '';
                $response['message'] = get_string('seatresult_conflict', 'mod_examcheck', (object) [
                    'user' => $userlabel,
                    'seat' => $result['seatlabel'] ?? '',
                    'by'   => $result['by'] ?? '',
                    'ago'  => $result['ago'] ?? '',
                ]);
                break;

            case 'unassigned':
                $response['message'] = get_string('seatresult_unassigned', 'mod_examcheck', $userlabel);
                break;

            case 'notassigned':
                $response['message'] = get_string('seatresult_notassigned', 'mod_examcheck');
                break;
        }

        return $response;
    }
}
