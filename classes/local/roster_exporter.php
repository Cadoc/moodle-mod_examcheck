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
use stdClass;

/**
 * Build the roster export: one row per student, with their identity, seat and check marks.
 *
 * The identity columns are exactly the ones the exporting user may see, resolved through
 * {@see \core_user\fields::get_identity_fields()} in the module context, so the file never
 * carries an ID number (or any other identity field) to a viewer the roster table itself
 * would hide it from. Custom profile fields are included, which is why the values are
 * loaded through the identity fields SQL rather than read off the roster records.
 *
 * Kept output-free so the identity gate is unit-testable; export.php streams the result.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roster_exporter {
    /**
     * Build the export columns and rows.
     *
     * @param stdClass[] $roster Roster users keyed by id, in display order.
     * @param stdClass[] $steplist Ordered step records.
     * @param array $marks Marks indexed [stepid][userid].
     * @param bool $hasseats Whether to include the seat column (seats exist, even if none are assigned).
     * @param array $seatlabels User id => seat label.
     * @param context_module $context The module context (identity visibility is evaluated
     *        for the current user in this context).
     * @return array Two elements: string[] $columns and string[][] $rows.
     */
    public static function columns_and_rows(
        array $roster,
        array $steplist,
        array $marks,
        bool $hasseats,
        array $seatlabels,
        context_module $context
    ): array {
        $identityfields = \core_user\fields::get_identity_fields($context, true);

        $columns = [get_string('lastname'), get_string('firstname')];
        foreach ($identityfields as $field) {
            $columns[] = \core_user\fields::get_display_name($field);
        }
        if ($hasseats) {
            $columns[] = get_string('seat', 'mod_examcheck');
        }
        foreach ($steplist as $step) {
            $name = format_string($step->name, true, ['context' => $context]);
            $columns[] = get_string('col_checked', 'mod_examcheck', $name);
            $columns[] = get_string('col_checkedby', 'mod_examcheck', $name);
            $columns[] = get_string('col_checkedat', 'mod_examcheck', $name);
        }

        $identityvalues = $identityfields ? self::load_identity_values(array_keys($roster), $context) : [];

        $rows = [];
        foreach ($roster as $user) {
            $row = [$user->lastname, $user->firstname];
            foreach ($identityfields as $field) {
                $row[] = (string) ($identityvalues[(int) $user->id]->$field ?? '');
            }
            if ($hasseats) {
                $row[] = $seatlabels[$user->id] ?? '';
            }
            foreach ($steplist as $step) {
                $mark = $marks[$step->id][$user->id] ?? null;
                $row[] = $mark ? get_string('yes') : get_string('no');
                $row[] = $mark ? checker::user_label((int) $mark->checkedby) : '';
                $row[] = $mark
                    ? userdate((int) $mark->timecreated, get_string('strftimedatetimeshort', 'langconfig'))
                    : '';
            }
            $rows[] = $row;
        }

        return [$columns, $rows];
    }

    /**
     * Load the visible identity values for the given users, custom profile fields included.
     *
     * @param int[] $userids The roster user ids.
     * @param context_module $context The module context.
     * @return array<int, stdClass> User id => record carrying one property per identity field.
     */
    protected static function load_identity_values(array $userids, context_module $context): array {
        global $DB;

        if (empty($userids)) {
            return [];
        }

        [$insql, $inparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED, 'uid');
        $fieldssql = \core_user\fields::for_identity($context, true)->get_sql('u', true);

        return $DB->get_records_sql(
            "SELECT u.id{$fieldssql->selects}
               FROM {user} u {$fieldssql->joins}
              WHERE u.id $insql",
            array_merge($fieldssql->params, $inparams)
        );
    }
}
