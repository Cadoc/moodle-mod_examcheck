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

namespace mod_examcheck\local;

use stdClass;

/**
 * Step exemption management for mod_examcheck.
 *
 * An exemption records that a specific student is excused from completing a
 * particular step (e.g. a student with a disability who enters via a different
 * route and cannot queue at the main door for the attendance check).
 *
 * There is at most one exemption per (step, student) pair.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class exemption_manager {

    /**
     * Return all exemptions for an activity, indexed by step id then user id.
     *
     * @param int $examcheckid The examcheck instance id.
     * @return array<int, array<int, stdClass>> Indexed [stepid][userid].
     */
    public static function get_exemptions_for_instance(int $examcheckid): array {
        global $DB;
        $records = $DB->get_records('examcheck_exemptions', ['examcheckid' => $examcheckid]);
        $indexed = [];
        foreach ($records as $ex) {
            $indexed[(int) $ex->stepid][(int) $ex->userid] = $ex;
        }
        return $indexed;
    }

    /**
     * Check whether a student is exempt from a specific step.
     *
     * @param int $stepid  The step id.
     * @param int $userid  The student user id.
     * @return bool
     */
    public static function is_exempt(int $stepid, int $userid): bool {
        global $DB;
        return $DB->record_exists('examcheck_exemptions', ['stepid' => $stepid, 'userid' => $userid]);
    }

    /**
     * Grant an exemption for a student on a step.
     *
     * If the student is already exempt, the existing record is returned without
     * change. There is at most one exemption per (step, student) pair.
     *
     * @param int    $examcheckid The examcheck instance id.
     * @param int    $stepid      The step id.
     * @param int    $userid      The student user id.
     * @param string $reason      Optional free-text reason.
     * @param int    $exemptedby  The teacher granting the exemption.
     * @return stdClass The exemption record (new or existing).
     */
    public static function grant_exemption(
        int $examcheckid,
        int $stepid,
        int $userid,
        string $reason,
        int $exemptedby
    ): stdClass {
        global $DB;

        $existing = $DB->get_record('examcheck_exemptions', ['stepid' => $stepid, 'userid' => $userid]);
        if ($existing) {
            return $existing;
        }

        $record = (object) [
            'examcheckid' => $examcheckid,
            'stepid'      => $stepid,
            'userid'      => $userid,
            'reason'      => clean_param($reason, PARAM_TEXT),
            'exemptedby'  => $exemptedby,
            'timecreated' => time(),
        ];
        $record->id = $DB->insert_record('examcheck_exemptions', $record);
        return $record;
    }

    /**
     * Revoke an exemption for a student on a step.
     *
     * @param int $stepid The step id.
     * @param int $userid The student user id.
     * @return bool Whether a record was deleted.
     */
    public static function revoke_exemption(int $stepid, int $userid): bool {
        global $DB;
        return $DB->delete_records('examcheck_exemptions', ['stepid' => $stepid, 'userid' => $userid]) > 0;
    }
}
