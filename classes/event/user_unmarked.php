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

namespace mod_examcheck\event;

use context_module;
use core\event\base;
use stdClass;

/**
 * Event fired when a student's check is removed for a step.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @property-read array $other {
 *      Extra information about the event.
 *      - int stepid: the step the check was removed from.
 *      - string stepname: the name of the step.
 * }
 */
class user_unmarked extends base {
    /**
     * Build the event from the mark that was removed.
     *
     * @param context_module $context The module context.
     * @param stdClass $mark The mark record that was deleted.
     * @param stdClass $step The step record.
     * @return self
     */
    public static function create_from_mark(context_module $context, stdClass $mark, stdClass $step): self {
        /** @var self $event */
        $event = self::create([
            'context'       => $context,
            'objectid'      => $mark->id,
            'relateduserid' => (int) $mark->userid,
            'other'         => [
                'stepid'   => (int) $step->id,
                'stepname' => $step->name,
            ],
        ]);
        return $event;
    }

    /**
     * Initialise the event metadata.
     */
    protected function init(): void {
        $this->data['crud'] = 'd';
        $this->data['edulevel'] = self::LEVEL_TEACHING;
        $this->data['objecttable'] = 'examcheck_marks';
    }

    /**
     * The localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventuserunmarked', 'mod_examcheck');
    }

    /**
     * A human readable description of what happened.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' removed the check on the user with id '{$this->relateduserid}' " .
            "for step '{$this->other['stepname']}' (id {$this->other['stepid']}) " .
            "in the examcheck activity with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Mapping of object id for backup/restore.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'examcheck_marks', 'restore' => base::NOT_MAPPED];
    }
}
