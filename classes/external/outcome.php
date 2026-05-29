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

use core_external\external_single_structure;
use core_external\external_value;

/**
 * Shared web service return structure for a check outcome.
 *
 * Every marking, unmarking and scanning web service returns the same shape so
 * the JavaScript can handle them uniformly: a machine readable status, a
 * ready-to-display localised message, and the involved user details.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class outcome {

    /**
     * The shared external return structure.
     *
     * @return external_single_structure
     */
    public static function structure(): external_single_structure {
        return new external_single_structure([
            'status'      => new external_value(PARAM_ALPHA, 'Outcome: marked, conflict, notinroster, unmarked, '
                . 'notchecked, notfound or needsconfirm.'),
            'message'     => new external_value(PARAM_TEXT, 'Localised message ready to show to the teacher.'),
            'stepid'      => new external_value(PARAM_INT, 'The step the outcome relates to.'),
            'userid'      => new external_value(PARAM_INT, 'The matched/affected student id, or 0 when none.', VALUE_DEFAULT, 0),
            'userlabel'   => new external_value(PARAM_TEXT, 'The student full name, when known.', VALUE_DEFAULT, ''),
            'checkedby'   => new external_value(PARAM_INT, 'For conflicts: the teacher who recorded the existing mark.',
                VALUE_DEFAULT, 0),
            'checkedbyname' => new external_value(PARAM_TEXT, 'For conflicts: that teacher\'s name.', VALUE_DEFAULT, ''),
            'timecreated' => new external_value(PARAM_INT, 'For conflicts/marks: when it was recorded.', VALUE_DEFAULT, 0),
            'ago'         => new external_value(PARAM_TEXT, 'For conflicts: how long ago, in words.', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Convert a {@see \mod_examcheck\local\checker} result array into the
     * external return structure, building the localised message.
     *
     * @param array $result The checker result array (has a "status" key).
     * @param int $stepid The step the action targeted.
     * @return array The normalised web service response.
     */
    public static function format(array $result, int $stepid): array {
        $status = $result['status'];
        $userlabel = $result['user'] ?? '';

        $response = [
            'status'        => $status,
            'message'       => '',
            'stepid'        => $stepid,
            'userid'        => 0,
            'userlabel'     => $userlabel,
            'checkedby'     => 0,
            'checkedbyname' => '',
            'timecreated'   => 0,
            'ago'           => '',
        ];

        switch ($status) {
            case 'marked':
                $response['userid'] = (int) $result['mark']->userid;
                $response['timecreated'] = (int) $result['mark']->timecreated;
                $response['message'] = get_string('result_marked', 'mod_examcheck', $userlabel);
                break;

            case 'conflict':
                $response['userid'] = (int) $result['mark']->userid;
                $response['checkedby'] = (int) $result['mark']->checkedby;
                $response['checkedbyname'] = $result['by'];
                $response['timecreated'] = (int) $result['mark']->timecreated;
                $response['ago'] = $result['ago'];
                $response['message'] = get_string('result_conflict', 'mod_examcheck', (object) [
                    'user' => $userlabel,
                    'by'   => $result['by'],
                    'ago'  => $result['ago'],
                ]);
                break;

            case 'notinroster':
                $response['message'] = get_string('result_notinroster', 'mod_examcheck', $userlabel);
                break;

            case 'unmarked':
                $response['message'] = get_string('result_unmarked', 'mod_examcheck', $userlabel);
                break;

            case 'notchecked':
                $response['message'] = get_string('result_notchecked', 'mod_examcheck', $userlabel);
                break;

            case 'notfound':
                $response['message'] = get_string('result_notfound', 'mod_examcheck', $result['value'] ?? '');
                break;

            case 'needsconfirm':
                $response['userid'] = (int) $result['userid'];
                $response['message'] = get_string('result_needsconfirm', 'mod_examcheck', $userlabel);
                break;
        }

        return $response;
    }
}
