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
 * Student flag management for mod_examcheck.
 *
 * A flag is a per-student, per-activity marker noting an exceptional situation
 * during an exam sitting (e.g. suspected malpractice, exclusion). Multiple
 * flags can exist for the same student in the same activity.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class flag_manager {

    /** Flag type: suspected malpractice. */
    public const TYPE_MALPRACTICE = 'malpractice';

    /** Flag type: student excluded from the exam. */
    public const TYPE_EXCLUDED = 'excluded';

    /** Flag type: administrative note. */
    public const TYPE_ADMINISTRATIVE = 'administrative';

    /** Flag type: other / unspecified. */
    public const TYPE_OTHER = 'other';

    /** All valid flag types. */
    public const VALID_TYPES = [
        self::TYPE_MALPRACTICE,
        self::TYPE_EXCLUDED,
        self::TYPE_ADMINISTRATIVE,
        self::TYPE_OTHER,
    ];

    /**
     * Return all flags for an activity, indexed by user id.
     *
     * @param int $examcheckid The examcheck instance id.
     * @return array<int, stdClass[]> Flags indexed by student user id.
     */
    public static function get_flags_for_instance(int $examcheckid): array {
        global $DB;
        $records = $DB->get_records('examcheck_flags', ['examcheckid' => $examcheckid], 'timecreated ASC');
        $indexed = [];
        foreach ($records as $flag) {
            $indexed[(int) $flag->userid][] = $flag;
        }
        return $indexed;
    }

    /**
     * Return all flags for a specific student in an activity.
     *
     * @param int $examcheckid The examcheck instance id.
     * @param int $userid The student user id.
     * @return stdClass[]
     */
    public static function get_flags_for_user(int $examcheckid, int $userid): array {
        global $DB;
        return array_values(
            $DB->get_records('examcheck_flags', ['examcheckid' => $examcheckid, 'userid' => $userid], 'timecreated ASC')
        );
    }

    /**
     * Add a flag for a student.
     *
     * @param int    $examcheckid The examcheck instance id.
     * @param int    $userid      The student user id.
     * @param string $flagtype    One of the TYPE_* constants.
     * @param string $note        Optional free-text note.
     * @param int    $flaggedby   The user id of the teacher raising the flag.
     * @return stdClass The newly created flag record.
     * @throws \coding_exception When the flag type is invalid.
     */
    public static function add_flag(
        int $examcheckid,
        int $userid,
        string $flagtype,
        string $note,
        int $flaggedby
    ): stdClass {
        global $DB;

        if (!in_array($flagtype, self::VALID_TYPES, true)) {
            throw new \coding_exception("Invalid flag type: {$flagtype}");
        }

        $flag = (object) [
            'examcheckid' => $examcheckid,
            'userid'      => $userid,
            'flagtype'    => $flagtype,
            'note'        => clean_param($note, PARAM_TEXT),
            'flaggedby'   => $flaggedby,
            'timecreated' => time(),
        ];
        $flag->id = $DB->insert_record('examcheck_flags', $flag);
        return $flag;
    }

    /**
     * Remove a specific flag record.
     *
     * @param int $flagid The flag record id.
     * @param int $examcheckid The examcheck instance id (ownership guard).
     * @return bool Whether a record was deleted.
     */
    public static function remove_flag(int $flagid, int $examcheckid): bool {
        global $DB;
        return $DB->delete_records('examcheck_flags', ['id' => $flagid, 'examcheckid' => $examcheckid]) > 0;
    }

    /**
     * Check whether a flag type string is valid.
     *
     * @param string $flagtype
     * @return bool
     */
    public static function is_valid_type(string $flagtype): bool {
        return in_array($flagtype, self::VALID_TYPES, true);
    }

    /**
     * Return the localised label for a flag type.
     *
     * @param string $flagtype
     * @return string
     */
    public static function get_type_label(string $flagtype): string {
        return get_string('flagtype_' . $flagtype, 'mod_examcheck');
    }
}
