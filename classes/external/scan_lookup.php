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
use mod_examcheck\local\scanfield;

/**
 * Web service: resolve a scanned QR/barcode value to a student and mark them.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scan_lookup extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'           => new external_value(PARAM_INT, 'Course module id'),
            'stepid'         => new external_value(PARAM_INT, 'Check step id'),
            'scanfield'      => new external_value(PARAM_RAW_TRIMMED, 'Scan field key'),
            'value'          => new external_value(PARAM_RAW, 'Raw value read from the QR code or barcode'),
            'confirm'        => new external_value(PARAM_BOOL, 'Teacher confirmed the matched student', VALUE_DEFAULT, false),
            'requireconfirm' => new external_value(PARAM_BOOL, 'Session requires confirmation before marking',
                VALUE_DEFAULT, false),
            'groupid'        => new external_value(PARAM_INT, 'Group context (0 for all)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Resolve a scanned value and (when appropriate) mark the student.
     *
     * @param int $cmid Course module id.
     * @param int $stepid Check step id.
     * @param string $scanfield Scan field key.
     * @param string $value Raw scanned value.
     * @param bool $confirm Whether the teacher confirmed the match.
     * @param bool $requireconfirm Whether confirmation is required this session.
     * @param int $groupid Group context.
     * @return array Outcome (see {@see outcome::structure()}).
     */
    public static function execute(int $cmid, int $stepid, string $scanfield, string $value,
            bool $confirm = false, bool $requireconfirm = false, int $groupid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid, 'stepid' => $stepid, 'scanfield' => $scanfield, 'value' => $value,
            'confirm' => $confirm, 'requireconfirm' => $requireconfirm, 'groupid' => $groupid,
        ]);

        $checker = checker::from_cmid($params['cmid']);
        self::validate_context($checker->get_context());
        require_capability('mod/examcheck:check', $checker->get_context());

        // Fall back to the instance default field if an unknown key is supplied.
        $fieldkey = scanfield::is_valid($params['scanfield'])
            ? $params['scanfield']
            : $checker->get_instance()->scanfield;

        $result = $checker->scan(
            $params['stepid'], $fieldkey, $params['value'],
            (bool) $params['confirm'], (bool) $params['requireconfirm'],
            (int) $USER->id, $params['groupid']);

        return outcome::format($result, $params['stepid']);
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return outcome::structure();
    }
}
