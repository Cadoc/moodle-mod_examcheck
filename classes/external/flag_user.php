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

namespace mod_examcheck\external;

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use mod_examcheck\local\checker;
use mod_examcheck\local\flag_manager;

/**
 * Web service: add or remove a student flag.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flag_user extends external_api {

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'     => new external_value(PARAM_INT,   'Course module id'),
            'userid'   => new external_value(PARAM_INT,   'Student user id'),
            'flagtype' => new external_value(PARAM_ALPHA, 'Flag type: malpractice|excluded|administrative|other',
                VALUE_DEFAULT, flag_manager::TYPE_OTHER),
            'note'     => new external_value(PARAM_TEXT,  'Optional note', VALUE_DEFAULT, ''),
            'flagid'   => new external_value(PARAM_INT,   'Flag id to remove (0 = add a new flag)', VALUE_DEFAULT, 0),
        ]);
    }

    /**
     * Add or remove a student flag.
     *
     * Pass flagid = 0 to add a new flag; pass a positive flagid to remove that flag.
     *
     * @param int    $cmid     Course module id.
     * @param int    $userid   Student user id.
     * @param string $flagtype Flag type string.
     * @param string $note     Optional note.
     * @param int    $flagid   Flag id to remove, or 0 to add.
     * @return array Outcome.
     */
    public static function execute(
        int $cmid,
        int $userid,
        string $flagtype,
        string $note,
        int $flagid
    ): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid'     => $cmid,
            'userid'   => $userid,
            'flagtype' => $flagtype,
            'note'     => $note,
            'flagid'   => $flagid,
        ]);

        $checker = checker::from_cmid($params['cmid']);
        self::validate_context($checker->get_context());
        $checker->require_user_access($params['userid']);

        if ($params['flagid'] > 0) {
            return $checker->remove_flag($params['flagid']);
        }

        return $checker->add_flag(
            $params['userid'],
            $params['flagtype'],
            $params['note'],
            (int) $USER->id
        );
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'status' => new external_value(PARAM_ALPHA, 'Result: flagged|removed|notfound'),
            'user'   => new external_value(PARAM_TEXT,  'Student full name', VALUE_OPTIONAL),
        ]);
    }
}
