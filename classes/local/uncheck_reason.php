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

/**
 * Predefined uncheck reason management for mod_examcheck.
 *
 * Reasons are keyed strings whose labels come from the plugin lang pack.
 * Teachers can restrict the list per activity; an empty restriction means all
 * defaults are offered.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class uncheck_reason {

    /**
     * All built-in reason keys shipped with the plugin.
     *
     * The order here is the display order in the dialog.
     */
    public const DEFAULT_KEYS = [
        'markedinerror',
        'fallill',
        'excluded',
        'escortedout',
        'exemptiongranted',
        'suspectedirregularity',
        'other',
    ];

    /**
     * Return the reasons to offer for a given activity instance.
     *
     * If the instance has a custom comma-separated list in `uncheckreasons`,
     * only those keys (filtered to known defaults) are returned. Otherwise all
     * default reasons are returned.
     *
     * Each element in the returned array has:
     *   - key   (string) — the stable identifier stored in the DB
     *   - label (string) — the localised display label
     *
     * @param \stdClass $examcheck The examcheck instance record.
     * @return array<array{key: string, label: string}>
     */
    public static function get_for_instance(\stdClass $examcheck): array {
        $custom = trim((string) ($examcheck->uncheckreasons ?? ''));
        if ($custom === '') {
            $keys = self::DEFAULT_KEYS;
        } else {
            $requested = array_map('trim', explode(',', $custom));
            $keys = array_values(array_intersect(self::DEFAULT_KEYS, $requested));
        }
        return self::keys_to_options($keys);
    }

    /**
     * Return the full list of default reasons as select-menu options.
     *
     * Keyed by reason key for use in Moodle form select elements.
     *
     * @return array<string, string>
     */
    public static function get_default_menu(): array {
        $options = [];
        foreach (self::DEFAULT_KEYS as $key) {
            $options[$key] = get_string('uncheckreason_' . $key, 'mod_examcheck');
        }
        return $options;
    }

    /**
     * Validate that a submitted reason key is known.
     *
     * An empty key is allowed (reason was optional and not supplied).
     *
     * @param string $key The key to validate.
     * @return bool
     */
    public static function is_valid_key(string $key): bool {
        return $key === '' || in_array($key, self::DEFAULT_KEYS, true);
    }

    /**
     * Convert a list of reason keys to the option-array format used by the
     * dashboard template (key + localised label pairs).
     *
     * @param string[] $keys
     * @return array<array{key: string, label: string}>
     */
    protected static function keys_to_options(array $keys): array {
        return array_map(static function (string $key): array {
            return [
                'key'   => $key,
                'label' => get_string('uncheckreason_' . $key, 'mod_examcheck'),
            ];
        }, $keys);
    }
}
