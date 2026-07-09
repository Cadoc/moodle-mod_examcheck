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
use moodle_exception;
use stdClass;

/**
 * Manage the seat list of an instance and the 1:1 user/seat assignments.
 *
 * Seats are free-text labels ("A1", "Room 2 - 14", ...) ordered by sortorder.
 * Each seat holds at most one student and each student sits on at most one
 * seat, both enforced by unique keys on examcheck_seat_users (which double as
 * the race backstop, same pattern as {@see checker::mark_user()}).
 *
 * This API is UI-independent on purpose: future features (random distribution,
 * check-time seat capture) can reuse assign()/unassign() unchanged.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seats {
    /** @var int Maximum length of a seat label, matching the column definition. */
    const LABEL_MAX_LENGTH = 100;

    /**
     * Return all seats for an instance, ordered by sortorder.
     *
     * @param int $examcheckid The instance id.
     * @return stdClass[] Seat records keyed by seat id.
     */
    public static function get_seats(int $examcheckid): array {
        global $DB;
        return $DB->get_records('examcheck_seats', ['examcheckid' => $examcheckid], 'sortorder ASC, id ASC');
    }

    /**
     * Return all seat assignments for an instance, keyed by seat id.
     *
     * @param int $examcheckid The instance id.
     * @return stdClass[] Assignment records keyed by seat id.
     */
    public static function get_assignments(int $examcheckid): array {
        global $DB;

        $indexed = [];
        foreach ($DB->get_records('examcheck_seat_users', ['examcheckid' => $examcheckid]) as $assignment) {
            $indexed[(int) $assignment->seatid] = $assignment;
        }
        return $indexed;
    }

    /**
     * Map every assigned student to their seat label, in one query.
     *
     * Used by the roster column, the exports and the scanner modals, so it must
     * stay a single cheap lookup.
     *
     * @param int $examcheckid The instance id.
     * @return array<int, string> User id => seat label.
     */
    public static function get_user_seat_labels(int $examcheckid): array {
        global $DB;

        return $DB->get_records_sql_menu(
            "SELECT su.userid, s.label
               FROM {examcheck_seat_users} su
               JOIN {examcheck_seats} s ON s.id = su.seatid
              WHERE su.examcheckid = :examcheckid",
            ['examcheckid' => $examcheckid]
        );
    }

    /**
     * Count the seats belonging to an instance.
     *
     * @param int $examcheckid The instance id.
     * @return int
     */
    public static function count_seats(int $examcheckid): int {
        global $DB;
        return $DB->count_records('examcheck_seats', ['examcheckid' => $examcheckid]);
    }

    /**
     * Count the seat assignments of an instance.
     *
     * @param int $examcheckid The instance id.
     * @return int
     */
    public static function count_assignments(int $examcheckid): int {
        global $DB;
        return $DB->count_records('examcheck_seat_users', ['examcheckid' => $examcheckid]);
    }

    /**
     * Replace the whole seat list of an instance.
     *
     * Labels are trimmed and empty lines dropped. Overlong labels and
     * case-insensitive duplicates are rejected. Saving a list identical to the
     * current one (same labels, same order) is a no-op, so an unchanged save
     * never destroys assignments. Any real change deletes every assignment and
     * every seat, then re-inserts the new list, all inside one transaction; a
     * seat_unassigned event is fired per removed assignment afterwards.
     *
     * @param int $examcheckid The instance id.
     * @param string[] $labels The new seat labels, in display order.
     * @throws moodle_exception When a label is too long or duplicated.
     */
    public static function replace_list(int $examcheckid, array $labels): void {
        global $DB;

        $labels = self::clean_labels($labels);

        // Unchanged list: keep the existing seats (and their assignments) untouched.
        $current = array_map(fn($seat) => $seat->label, array_values(self::get_seats($examcheckid)));
        if ($labels === $current) {
            return;
        }

        // Collect the assignments about to be destroyed so their events can be
        // fired after the transaction commits.
        $removed = [];
        foreach (self::get_assignments($examcheckid) as $assignment) {
            $removed[] = $assignment;
        }
        $seatlabels = array_map(fn($seat) => $seat->label, self::get_seats($examcheckid));

        $transaction = $DB->start_delegated_transaction();
        $DB->delete_records('examcheck_seat_users', ['examcheckid' => $examcheckid]);
        $DB->delete_records('examcheck_seats', ['examcheckid' => $examcheckid]);

        $now = time();
        foreach ($labels as $order => $label) {
            $DB->insert_record('examcheck_seats', (object) [
                'examcheckid'  => $examcheckid,
                'label'        => $label,
                'sortorder'    => $order,
                'timecreated'  => $now,
                'timemodified' => $now,
            ]);
        }
        $transaction->allow_commit();

        $context = self::context_for($examcheckid);
        foreach ($removed as $assignment) {
            \mod_examcheck\event\seat_unassigned::create_from_assignment(
                $context,
                $assignment,
                $seatlabels[(int) $assignment->seatid] ?? ''
            )->trigger();
        }
    }

    /**
     * Assign a student to a seat.
     *
     * A student already sitting on another seat is reported as a conflict
     * (mirroring {@see checker::conflict_result()}: who assigned them and when)
     * rather than silently moved. Assigning over a seat's current occupant
     * replaces them (unassign event + assign event). The unique keys on
     * examcheck_seat_users are the backstop for concurrent assignments.
     *
     * @param int $seatid The seat id.
     * @param int $userid The student user id.
     * @param int $assignedby The teacher recording the assignment.
     * @return array Result with a "status" key (assigned|conflict), plus seat/user data.
     */
    public static function assign(int $seatid, int $userid, int $assignedby): array {
        global $DB;

        $seat = $DB->get_record('examcheck_seats', ['id' => $seatid], '*', MUST_EXIST);
        $examcheckid = (int) $seat->examcheckid;

        // A student can only sit on one seat: report where they already are.
        $existing = $DB->get_record('examcheck_seat_users', ['examcheckid' => $examcheckid, 'userid' => $userid]);
        if ($existing && (int) $existing->seatid !== $seatid) {
            return self::conflict_result($existing, $userid);
        }
        if ($existing) {
            // Already on this very seat: idempotent success.
            return self::assigned_result($existing, $seat);
        }

        $context = self::context_for($examcheckid);

        // Replace the seat's current occupant, if any.
        if ($occupant = $DB->get_record('examcheck_seat_users', ['seatid' => $seatid])) {
            $DB->delete_records('examcheck_seat_users', ['id' => $occupant->id]);
            \mod_examcheck\event\seat_unassigned::create_from_assignment($context, $occupant, $seat->label)->trigger();
        }

        $assignment = (object) [
            'examcheckid' => $examcheckid,
            'seatid'      => $seatid,
            'userid'      => $userid,
            'assignedby'  => $assignedby,
            'timecreated' => time(),
        ];

        try {
            $assignment->id = $DB->insert_record('examcheck_seat_users', $assignment);
        } catch (\dml_exception $e) {
            // Another teacher won the race on one of the unique keys.
            if ($other = $DB->get_record('examcheck_seat_users', ['examcheckid' => $examcheckid, 'userid' => $userid])) {
                if ((int) $other->seatid === $seatid) {
                    return self::assigned_result($other, $seat);
                }
                return self::conflict_result($other, $userid);
            }
            if ($occupant = $DB->get_record('examcheck_seat_users', ['seatid' => $seatid])) {
                return self::conflict_result($occupant, $userid);
            }
            throw $e;
        }

        \mod_examcheck\event\seat_assigned::create_from_assignment($context, $assignment, $seat->label)->trigger();

        return self::assigned_result($assignment, $seat);
    }

    /**
     * Remove the assignment of a seat, if any.
     *
     * @param int $seatid The seat id.
     * @return array Result with a "status" key (unassigned|notassigned).
     */
    public static function unassign(int $seatid): array {
        global $DB;

        $seat = $DB->get_record('examcheck_seats', ['id' => $seatid], '*', MUST_EXIST);

        if (!$assignment = $DB->get_record('examcheck_seat_users', ['seatid' => $seatid])) {
            return ['status' => 'notassigned', 'seatid' => $seatid];
        }

        $DB->delete_records('examcheck_seat_users', ['id' => $assignment->id]);

        $context = self::context_for((int) $seat->examcheckid);
        \mod_examcheck\event\seat_unassigned::create_from_assignment($context, $assignment, $seat->label)->trigger();

        return [
            'status' => 'unassigned',
            'seatid' => $seatid,
            'userid' => (int) $assignment->userid,
            'user'   => checker::user_label((int) $assignment->userid),
        ];
    }

    /**
     * Seat a pool of students on the free seats, at random.
     *
     * The students are shuffled and the free seats are taken in the order the
     * teacher authored them, so the seat list keeps its meaning ("fill the room
     * from the front") while nobody can predict who lands where. Students
     * already sitting somewhere are dropped from the pool and never moved.
     *
     * When the pool is larger than the number of free seats, only as many
     * students as there are seats get one; the caller decides what to tell the
     * teacher about the rest.
     *
     * A student who was seated by someone else between the free-seat read and
     * the write is skipped rather than aborting the batch, mirroring
     * {@see \mod_examcheck\external\bulk_action} rather than
     * {@see seats_importer::apply()}: a half-seated room is still progress.
     *
     * @param int $examcheckid The instance id.
     * @param int[] $userids The candidate students, in any order.
     * @param int $assignedby The teacher recording the assignments.
     * @return int The number of students actually seated.
     */
    public static function auto_assign(int $examcheckid, array $userids, int $assignedby): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        // Free seats, still in "sortorder ASC, id ASC" order.
        $assignments = self::get_assignments($examcheckid);
        $freeseats = [];
        foreach (self::get_seats($examcheckid) as $seat) {
            if (!isset($assignments[(int) $seat->id])) {
                $freeseats[] = (int) $seat->id;
            }
        }

        // Never move a student who already sits somewhere.
        $seated = self::get_user_seat_labels($examcheckid);
        $pool = array_values(array_filter(
            array_map('intval', $userids),
            fn($userid) => !isset($seated[$userid])
        ));

        shuffle($pool);

        $assigned = 0;
        $count = min(count($freeseats), count($pool));
        for ($i = 0; $i < $count; $i++) {
            if (self::assign($freeseats[$i], $pool[$i], $assignedby)['status'] === 'assigned') {
                $assigned++;
            }
        }

        $transaction->allow_commit();

        return $assigned;
    }

    /**
     * Normalise and validate a raw list of seat labels.
     *
     * @param string[] $labels Raw labels (e.g. textarea lines).
     * @return string[] Trimmed, non-empty labels in the given order.
     * @throws moodle_exception When a label is too long or duplicated (case-insensitively).
     */
    public static function clean_labels(array $labels): array {
        $clean = [];
        $seen = [];
        foreach ($labels as $label) {
            $label = trim($label);
            if ($label === '') {
                continue;
            }
            if (\core_text::strlen($label) > self::LABEL_MAX_LENGTH) {
                throw new moodle_exception('error_seatlabeltoolong', 'mod_examcheck', '', $label);
            }
            $key = \core_text::strtolower($label);
            if (isset($seen[$key])) {
                throw new moodle_exception('error_duplicateseat', 'mod_examcheck', '', $label);
            }
            $seen[$key] = true;
            $clean[] = $label;
        }
        return $clean;
    }

    /**
     * Build a structured "assigned" result.
     *
     * @param stdClass $assignment The assignment record.
     * @param stdClass $seat The seat record.
     * @return array
     */
    protected static function assigned_result(stdClass $assignment, stdClass $seat): array {
        return [
            'status'    => 'assigned',
            'seatid'    => (int) $seat->id,
            'seatlabel' => $seat->label,
            'userid'    => (int) $assignment->userid,
            'user'      => checker::user_label((int) $assignment->userid),
        ];
    }

    /**
     * Build a structured conflict result for a student who is already seated elsewhere.
     *
     * @param stdClass $assignment The student's existing assignment (on another seat).
     * @param int $userid The student user id.
     * @return array
     */
    protected static function conflict_result(stdClass $assignment, int $userid): array {
        global $DB;

        $seatlabel = $DB->get_field('examcheck_seats', 'label', ['id' => $assignment->seatid]);
        return [
            'status'    => 'conflict',
            'seatid'    => (int) $assignment->seatid,
            'seatlabel' => (string) $seatlabel,
            'userid'    => $userid,
            'user'      => checker::user_label($userid),
            'by'        => checker::user_label((int) $assignment->assignedby),
            'ago'       => checker::relative_time((int) $assignment->timecreated),
            'timestamp' => (int) $assignment->timecreated,
        ];
    }

    /**
     * Resolve the module context of an instance, for firing events from this static API.
     *
     * @param int $examcheckid The instance id.
     * @return context_module
     */
    protected static function context_for(int $examcheckid): context_module {
        $cm = get_coursemodule_from_instance('examcheck', $examcheckid, 0, false, MUST_EXIST);
        return context_module::instance($cm->id);
    }
}
