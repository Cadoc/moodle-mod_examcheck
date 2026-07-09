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
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use mod_examcheck\local\checker;
use mod_examcheck\local\seats;

/**
 * Web service: search students who can still be assigned to a seat.
 *
 * Returns the reachable roster minus already-assigned students, filtered
 * against the full name and the viewer-visible identity fields. The display
 * label is built server-side so identity-field visibility stays authoritative.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class search_seat_candidates extends external_api {
    /** @var int Cap on the number of returned candidates. */
    const MAX_RESULTS = 100;

    /**
     * Parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid'  => new external_value(PARAM_INT, 'Course module id'),
            'query' => new external_value(PARAM_RAW_TRIMMED, 'Search text', VALUE_DEFAULT, ''),
        ]);
    }

    /**
     * Search unassigned roster students matching the query.
     *
     * @param int $cmid Course module id.
     * @param string $query Search text.
     * @return array With "overflow" (bool) and "list" of {id, label}.
     */
    public static function execute(int $cmid, string $query = ''): array {
        $params = self::validate_parameters(self::execute_parameters(), [
            'cmid' => $cmid, 'query' => $query,
        ]);

        $checker = checker::from_cmid($params['cmid']);
        $context = $checker->get_context();
        self::validate_context($context);
        require_capability('mod/examcheck:manageseats', $context);

        // Resolve the group the caller is effectively confined to. Never call
        // require_group_access(0) here: it throws for group-restricted teachers.
        $group = $checker->resolve_effective_group(0);
        if ($group === -1) {
            return ['overflow' => false, 'list' => []];
        }

        $identityfields = \core_user\fields::get_identity_fields($context, false);
        $users = $checker->get_roster($group, $identityfields);
        $assigned = seats::get_user_seat_labels((int) $checker->get_instance()->id);

        $needle = \core_text::strtolower($params['query']);
        $list = [];
        $overflow = false;
        foreach ($users as $user) {
            if (isset($assigned[(int) $user->id])) {
                continue;
            }

            // Match against the name and every identity value the viewer may see.
            $identityvalues = [];
            foreach ($identityfields as $field) {
                $value = (string) ($user->$field ?? '');
                if ($value !== '') {
                    $identityvalues[] = $value;
                }
            }
            $haystack = \core_text::strtolower(implode(' ', array_merge([fullname($user)], $identityvalues)));
            if ($needle !== '' && strpos($haystack, $needle) === false) {
                continue;
            }

            if (count($list) >= self::MAX_RESULTS) {
                $overflow = true;
                break;
            }

            $label = fullname($user);
            if (!empty($identityvalues)) {
                $label .= ' (' . reset($identityvalues) . ')';
            }
            $list[] = ['id' => (int) $user->id, 'label' => $label];
        }

        return ['overflow' => $overflow, 'list' => $list];
    }

    /**
     * Return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'overflow' => new external_value(PARAM_BOOL, 'Whether more students matched than were returned.'),
            'list'     => new external_multiple_structure(
                new external_single_structure([
                    'id'    => new external_value(PARAM_INT, 'User id.'),
                    'label' => new external_value(PARAM_TEXT, 'Display label (name plus visible identity value).'),
                ]),
                'Matching unassigned students.'
            ),
        ]);
    }
}
