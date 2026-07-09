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
 * Build the seat export: one row per seat, with the assigned student's
 * identity columns.
 *
 * Column policy (confirmed with the plugin owner): "seat" and "username"
 * always; then email / idnumber / custom profile fields only as the exporting
 * user's identity-field visibility allows (including custom profile fields,
 * unlike the roster table); then "fullname" as informational. Unassigned seats
 * export with empty user columns so a round-trip import recreates the exact
 * state.
 *
 * Headers are stable machine names ("seat", "username", "email",
 * "profile_field_x", "fullname") rather than localised labels, so
 * {@see seats_importer} can match columns by name regardless of language.
 *
 * Kept output-free so the export/import round-trip is unit-testable.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seats_exporter {
    /**
     * Build the export columns and rows for an instance.
     *
     * @param int $examcheckid The instance id.
     * @param context_module $context The module context (identity visibility is
     *        evaluated for the current user in this context).
     * @return array Two elements: string[] $columns and string[][] $rows.
     */
    public static function columns_and_rows(int $examcheckid, context_module $context): array {
        global $DB;

        // Identity fields the exporting user may see, custom profile fields
        // included. Username gets its own fixed column, so drop it from here.
        $identityfields = array_values(array_filter(
            \core_user\fields::for_identity($context, true)->get_required_fields(),
            fn($field) => $field !== 'username'
        ));

        $columns = array_merge(['seat', 'username'], $identityfields, ['fullname']);

        // Fetch username + identity values for every assigned student in one query.
        // Custom profile_field_* values are not {user} columns, so the fields SQL
        // (with its joins) is required here.
        $assignments = seats::get_assignments($examcheckid);
        $users = [];
        if ($assignments) {
            $userids = array_map(fn($assignment) => (int) $assignment->userid, $assignments);
            [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
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
        }

        $rows = [];
        foreach (seats::get_seats($examcheckid) as $seat) {
            $assignment = $assignments[(int) $seat->id] ?? null;
            $user = $assignment ? ($users[(int) $assignment->userid] ?? null) : null;

            $row = [$seat->label, $user ? (string) $user->username : ''];
            foreach ($identityfields as $field) {
                $row[] = $user ? (string) ($user->$field ?? '') : '';
            }
            $row[] = $user ? fullname($user) : '';
            $rows[] = $row;
        }

        return [$columns, $rows];
    }
}
