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

use context_module;

/**
 * Build the seat export: the seat list, and the roster with each student's seat.
 *
 * Two files, because they answer two different needs and both are meant to be edited
 * and fed back to {@see seats_importer}:
 *
 * - seats.csv: one row per seat, single "seat" column. Re-import it with "Replace the
 *   seat list" to recreate the list.
 * - students.csv: one row per roster student, with their seat (empty when unassigned).
 *   Fill the empty seats in and re-import to seat a whole cohort in one pass.
 *
 * Column policy for students.csv (confirmed with the plugin owner): "seat" and
 * "username" always; then email / idnumber / custom profile fields only as the
 * exporting user's identity-field visibility allows (including custom profile fields,
 * unlike the roster table); then "fullname" as informational.
 *
 * Headers are stable machine names ("seat", "username", "email", "profile_field_x",
 * "fullname") rather than localised labels, so {@see seats_importer} can match columns
 * by name regardless of language.
 *
 * Kept output-free so the export/import round-trip is unit-testable; build_zip() returns
 * a path rather than streaming, for the same reason.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seats_exporter {
    /** @var string Name of the seat list file inside the archive. */
    const SEATS_FILENAME = 'seats.csv';

    /** @var string Name of the roster file inside the archive. */
    const STUDENTS_FILENAME = 'students.csv';

    /**
     * The seat list: one row per seat, in the order the teacher authored it.
     *
     * @param int $examcheckid The instance id.
     * @return array Two elements: string[] $columns and string[][] $rows.
     */
    public static function seat_columns_and_rows(int $examcheckid): array {
        $rows = [];
        foreach (seats::get_seats($examcheckid) as $seat) {
            $rows[] = [$seat->label];
        }
        return [['seat'], $rows];
    }

    /**
     * The roster: one row per student, carrying the seat they sit on (empty when unassigned).
     *
     * Restricted to the students the exporting user may reach, using the same group
     * resolution as the seat candidate search, so a separate-groups teacher never
     * exports students outside their own groups.
     *
     * @param int $examcheckid The instance id.
     * @param context_module $context The module context (identity visibility is evaluated
     *        for the current user in this context).
     * @return array Two elements: string[] $columns and string[][] $rows.
     */
    public static function student_columns_and_rows(int $examcheckid, context_module $context): array {
        global $DB;

        // Identity fields the exporting user may see, custom profile fields included.
        // Username gets its own fixed column, so drop it from here.
        $identityfields = array_values(array_filter(
            \core_user\fields::for_identity($context, true)->get_required_fields(),
            fn($field) => $field !== 'username'
        ));

        $columns = array_merge(['seat', 'username'], $identityfields, ['fullname']);

        $checker = new checker($DB->get_record('examcheck', ['id' => $examcheckid], '*', MUST_EXIST), $context);
        $group = $checker->resolve_effective_group(0);
        if ($group === -1) {
            return [$columns, []];
        }
        $roster = $checker->get_roster($group);
        if (empty($roster)) {
            return [$columns, []];
        }

        // Fetch username + identity values for every roster student in one query. Custom
        // profile_field_* values are not {user} columns, so the fields SQL (with its
        // joins) is required here.
        [$insql, $inparams] = $DB->get_in_or_equal(array_keys($roster), SQL_PARAMS_NAMED, 'uid');
        $fieldssql = \core_user\fields::for_identity($context, true)
            ->with_name()
            ->including('username')
            ->get_sql('u', true);
        $users = $DB->get_records_sql(
            "SELECT u.id{$fieldssql->selects}
               FROM {user} u {$fieldssql->joins}
              WHERE u.id $insql",
            array_merge($fieldssql->params, $inparams)
        );

        $seatlabels = seats::get_user_seat_labels($examcheckid);

        // Iterate the roster, not $users: it carries the lastname/firstname ordering.
        $rows = [];
        foreach (array_keys($roster) as $userid) {
            $user = $users[$userid] ?? null;
            if (!$user) {
                continue;
            }
            $row = [$seatlabels[$userid] ?? '', (string) $user->username];
            foreach ($identityfields as $field) {
                $row[] = (string) ($user->$field ?? '');
            }
            $row[] = fullname($user);
            $rows[] = $row;
        }

        return [$columns, $rows];
    }

    /**
     * Write both files into a zip archive and return its path.
     *
     * @param int $examcheckid The instance id.
     * @param context_module $context The module context.
     * @param string $basename The archive basename, without extension.
     * @return string Absolute path to the archive, in a per-request temp directory.
     */
    public static function build_zip(int $examcheckid, context_module $context, string $basename): string {
        [$seatcolumns, $seatrows] = self::seat_columns_and_rows($examcheckid);
        [$studentcolumns, $studentrows] = self::student_columns_and_rows($examcheckid, $context);

        // Each write_data() call drops its file in its own request directory, and returns the path.
        $files = [
            self::SEATS_FILENAME => \core\dataformat::write_data(
                pathinfo(self::SEATS_FILENAME, PATHINFO_FILENAME),
                'csv',
                $seatcolumns,
                $seatrows
            ),
            self::STUDENTS_FILENAME => \core\dataformat::write_data(
                pathinfo(self::STUDENTS_FILENAME, PATHINFO_FILENAME),
                'csv',
                $studentcolumns,
                $studentrows
            ),
        ];

        $zippath = make_request_directory() . '/' . $basename . '.zip';
        get_file_packer('application/zip')->archive_to_pathname($files, $zippath);

        return $zippath;
    }
}
