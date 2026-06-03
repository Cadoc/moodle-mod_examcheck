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

namespace mod_examcheck\output;

use context;
use mod_examcheck\local\steps;
use renderer_base;
use stdClass;

/**
 * Datafilter for the checking roster: a keyword search and a group filter.
 *
 * Modelled on {@see \core_user\output\participants_filter}, trimmed to the two
 * filters relevant to the roster and aware of the activity-level group mode.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roster_filter extends \core\output\datafilter {
    /** @var int Course module id. */
    protected int $cmid;

    /**
     * Constructor.
     *
     * @param context $context The module context.
     * @param string|null $tableregionid The dynamic table region id.
     * @param int $cmid The course module id.
     */
    public function __construct(context $context, ?string $tableregionid, int $cmid) {
        parent::__construct($context, $tableregionid);
        $this->cmid = $cmid;
        $this->course = get_course($context->get_course_context()->instanceid);
    }

    /**
     * The available filter types.
     *
     * @return array
     */
    protected function get_filtertypes(): array {
        $filtertypes = [$this->get_keyword_filter()];
        if ($groupfilter = $this->get_groups_filter()) {
            $filtertypes[] = $groupfilter;
        }
        if ($statusfilter = $this->get_checkstatus_filter()) {
            $filtertypes[] = $statusfilter;
        }
        return $filtertypes;
    }

    /**
     * The keyword (name / id number) search filter.
     *
     * @return stdClass|null
     */
    protected function get_keyword_filter(): ?stdClass {
        return $this->get_filter_object(
            'keywords',
            get_string('searchstudents', 'mod_examcheck'),
            true,
            true,
            'core/datafilter/filtertypes/keyword',
            [],
            true
        );
    }

    /**
     * The group filter, honouring the activity group mode and access-all-groups.
     *
     * @return stdClass|null
     */
    protected function get_groups_filter(): ?stdClass {
        $cm = get_coursemodule_from_id('examcheck', $this->cmid, 0, false, MUST_EXIST);
        $groupmode = groups_get_activity_groupmode($cm);

        // Offer the group filter whenever the course has groups, even with no group mode:
        // selecting one simply narrows the roster to that group's members. Under separate
        // groups a restricted user is still limited to their own groups.
        $separate = $groupmode == SEPARATEGROUPS && !has_capability('moodle/site:accessallgroups', $this->context);
        $groups = $separate ? groups_get_activity_allowed_groups($cm) : groups_get_all_groups($this->course->id);
        if (empty($groups)) {
            return null;
        }

        return $this->get_filter_object(
            'groups',
            get_string('groups', 'core_group'),
            false,
            true,
            null,
            array_map(function ($group) {
                return (object) [
                    'value' => $group->id,
                    'title' => format_string($group->name, true, ['context' => $this->context]),
                ];
            }, array_values($groups))
        );
    }

    /**
     * The "check status" filter: one option per step + status (checked / not checked).
     *
     * Multiple chips can be selected at once and are AND-ed in {@see roster::query_db()}.
     * Returns null when the activity has no steps yet (nothing to filter on).
     *
     * @return stdClass|null
     */
    protected function get_checkstatus_filter(): ?stdClass {
        $cm = get_coursemodule_from_id('examcheck', $this->cmid, 0, false, MUST_EXIST);
        $steps = steps::get_steps((int) $cm->instance);
        if (empty($steps)) {
            return null;
        }

        $options = [];
        foreach ($steps as $step) {
            $name = format_string($step->name, true, ['context' => $this->context]);
            $options[] = (object) [
                'value' => $step->id . ':notchecked',
                'title' => get_string('checkstatus_optionnotchecked', 'mod_examcheck', $name),
            ];
            $options[] = (object) [
                'value' => $step->id . ':checked',
                'title' => get_string('checkstatus_optionchecked', 'mod_examcheck', $name),
            ];
        }

        return $this->get_filter_object(
            'checkstatus',
            get_string('checkstatus', 'mod_examcheck'),
            false,
            true,
            // The default JS filter type runs parseInt on every value, which would mangle
            // our "stepid:status" tokens; our module skips that cast.
            'mod_examcheck/datafilter/filtertypes/checkstatus',
            $options
        );
    }

    /**
     * Export for the mustache template.
     *
     * @param renderer_base $output Unused.
     * @return stdClass
     */
    public function export_for_template(renderer_base $output): stdClass {
        return (object) [
            'tableregionid' => $this->tableregionid,
            'courseid'      => $this->course->id,
            'filtertypes'   => $this->get_filtertypes(),
            'rownumber'     => 1,
        ];
    }
}
