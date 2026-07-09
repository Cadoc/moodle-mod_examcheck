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
 * Event fired when a student's seat assignment is removed.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 *
 * @property-read array $other {
 *      Extra information about the event.
 *      - int seatid: the seat the student was removed from.
 *      - string seatlabel: the label of the seat.
 * }
 */
class seat_unassigned extends base {
    /**
     * Build the event from the assignment being removed.
     *
     * @param context_module $context The module context.
     * @param stdClass $assignment The assignment record being removed.
     * @param string $seatlabel The seat label.
     * @return self
     */
    public static function create_from_assignment(context_module $context, stdClass $assignment, string $seatlabel): self {
        /** @var self $event */
        $event = self::create([
            'context'       => $context,
            'objectid'      => $assignment->id,
            'relateduserid' => (int) $assignment->userid,
            'other'         => [
                'seatid'    => (int) $assignment->seatid,
                'seatlabel' => $seatlabel,
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
        $this->data['objecttable'] = 'examcheck_seat_users';
    }

    /**
     * The localised event name.
     *
     * @return string
     */
    public static function get_name(): string {
        return get_string('eventseatunassigned', 'mod_examcheck');
    }

    /**
     * A human readable description of what happened.
     *
     * @return string
     */
    public function get_description(): string {
        return "The user with id '{$this->userid}' removed the user with id '{$this->relateduserid}' " .
            "from seat '{$this->other['seatlabel']}' (id {$this->other['seatid']}) " .
            "in the examcheck activity with course module id '{$this->contextinstanceid}'.";
    }

    /**
     * Mapping of object id for backup/restore.
     *
     * @return array
     */
    public static function get_objectid_mapping(): array {
        return ['db' => 'examcheck_seat_users', 'restore' => base::NOT_MAPPED];
    }
}
