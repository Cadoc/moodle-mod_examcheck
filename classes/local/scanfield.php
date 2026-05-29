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

/**
 * Helper for the user fields a teacher can match scanned codes against.
 *
 * Supported field keys:
 *  - "idnumber"           the standard user ID number;
 *  - "userid"             the internal numeric user id;
 *  - "profile_field_xxx"  any custom user profile field (by shortname).
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class scanfield {
    /** @var string Prefix marking a custom profile field key. */
    const PROFILE_PREFIX = 'profile_field_';

    /**
     * Build the menu of selectable scan fields, keyed by field key.
     *
     * @return array<string, string> Map of field key => human readable label.
     */
    public static function get_field_menu(): array {
        global $DB;

        $menu = [
            'idnumber' => get_string('field_idnumber', 'mod_examcheck'),
            'userid'   => get_string('field_userid', 'mod_examcheck'),
        ];

        $fields = $DB->get_records('user_info_field', null, 'sortorder ASC', 'id, shortname, name');
        foreach ($fields as $field) {
            $key = self::PROFILE_PREFIX . $field->shortname;
            $menu[$key] = get_string('field_profile', 'mod_examcheck', format_string($field->name));
        }

        return $menu;
    }

    /**
     * Whether the given field key is one this plugin understands.
     *
     * @param string $key The field key.
     * @return bool
     */
    public static function is_valid(string $key): bool {
        return array_key_exists($key, self::get_field_menu());
    }

    /**
     * Human readable label for a field key, falling back to the raw key.
     *
     * @param string $key The field key.
     * @return string
     */
    public static function get_label(string $key): string {
        $menu = self::get_field_menu();
        return $menu[$key] ?? $key;
    }

    /**
     * Extract the part of a scanned value to match against, using an optional
     * regular expression.
     *
     * This lets a teacher pull a student number out of a barcode that also
     * encodes other information. The regex is entered without delimiters; the
     * first capturing group is used, or the whole match when there is no group.
     *
     * @param string $regex The regular expression (without delimiters), or '' to skip.
     * @param string $value The raw scanned value.
     * @return string|null The extracted value, the trimmed value when no regex is
     *                     set, or null when the regex is invalid or does not match.
     */
    public static function apply_regex(string $regex, string $value): ?string {
        $value = trim($value);
        if ($regex === '') {
            return $value;
        }

        $pattern = '~' . str_replace('~', '\~', $regex) . '~';
        $result = @preg_match($pattern, $value, $matches);
        if ($result === false || $result === 0) {
            // Invalid pattern or no match: nothing to compare against.
            return null;
        }

        return $matches[1] ?? $matches[0];
    }

    /**
     * Whether a regular expression (entered without delimiters) is valid.
     *
     * @param string $regex The regular expression.
     * @return bool
     */
    public static function is_valid_regex(string $regex): bool {
        if ($regex === '') {
            return true;
        }
        $pattern = '~' . str_replace('~', '\~', $regex) . '~';
        return @preg_match($pattern, '') !== false;
    }

    /**
     * Find a single user, among a set of candidate ids, whose value for the
     * configured scan field matches the scanned value.
     *
     * Matching is case-insensitive and trims surrounding whitespace so that a
     * stray space or newline from the scanner does not prevent a match.
     *
     * @param string $fieldkey The scan field key.
     * @param string $value The raw value read from the QR code or barcode.
     * @param int[] $candidateids User ids that are allowed to match (the roster).
     * @return int The matching user id, or 0 when there is no unambiguous match.
     */
    public static function find_user(string $fieldkey, string $value, array $candidateids): int {
        global $DB;

        $value = trim($value);
        if ($value === '' || empty($candidateids)) {
            return 0;
        }

        [$insql, $inparams] = $DB->get_in_or_equal($candidateids, SQL_PARAMS_NAMED, 'cand');

        if ($fieldkey === 'userid') {
            if (!ctype_digit($value)) {
                return 0;
            }
            $params = $inparams + ['value' => (int) $value];
            $found = $DB->get_fieldset_select('user', 'id', "id $insql AND id = :value", $params);
            return (count($found) === 1) ? (int) reset($found) : 0;
        }

        if ($fieldkey === 'idnumber') {
            $params = $inparams + ['value' => \core_text::strtolower($value)];
            $sql = "id $insql AND " . $DB->sql_equal($DB->sql_compare_text('idnumber'), ':value', false, false);
            $found = $DB->get_fieldset_sql("SELECT id FROM {user} WHERE $sql", $params);
            return (count($found) === 1) ? (int) reset($found) : 0;
        }

        if (strpos($fieldkey, self::PROFILE_PREFIX) === 0) {
            $shortname = substr($fieldkey, strlen(self::PROFILE_PREFIX));
            if (!$field = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id')) {
                return 0;
            }
            $params = $inparams + ['fieldid' => $field->id, 'value' => \core_text::strtolower($value)];
            $sql = "SELECT userid
                      FROM {user_info_data}
                     WHERE fieldid = :fieldid
                       AND userid $insql
                       AND " . $DB->sql_equal($DB->sql_compare_text('data'), ':value', false, false);
            $found = $DB->get_fieldset_sql($sql, $params);
            return (count($found) === 1) ? (int) reset($found) : 0;
        }

        return 0;
    }
}
