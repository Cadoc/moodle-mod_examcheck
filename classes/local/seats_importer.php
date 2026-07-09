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
use csv_import_reader;
use moodle_exception;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/csvlib.class.php');

/**
 * Validate and apply a CSV import of seats and seat assignments.
 *
 * The file needs a "seat" column; students are matched through the highest
 * priority identity column present in the header (username, then email, then
 * idnumber — a decision confirmed with the plugin owner); every other column
 * is ignored. The whole file is validated first and nothing is applied unless
 * every line is clean; applying runs in one transaction through the
 * {@see seats} API so events and validation stay consistent.
 *
 * Two modes:
 * - override: the file defines the new seat list (existing seats and
 *   assignments are replaced).
 * - normal: every seat in the file must already exist; only assignments change.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seats_importer {
    /** @var string[] Student match columns, in priority order. */
    const MATCH_COLUMNS = ['username', 'email', 'idnumber'];

    /** @var int The examcheck instance id. */
    protected int $examcheckid;

    /** @var context_module The module context. */
    protected context_module $context;

    /** @var bool Whether the file replaces the seat list. */
    protected bool $override;

    /** @var array<int, array{label: string, userid: int}>|null Parsed rows, populated by validate(). */
    protected ?array $rows = null;

    /**
     * Constructor.
     *
     * @param int $examcheckid The instance id.
     * @param context_module $context The module context.
     * @param bool $override Whether the file replaces the seat list.
     */
    public function __construct(int $examcheckid, context_module $context, bool $override) {
        $this->examcheckid = $examcheckid;
        $this->context = $context;
        $this->override = $override;
    }

    /**
     * Validate the entire file, collecting every problem before giving up.
     *
     * @param csv_import_reader $cir An initialised reader with loaded content.
     * @return array<int, string[]> Errors keyed by line number (header = 1); empty when clean.
     */
    public function validate(csv_import_reader $cir): array {
        $errors = [];
        $this->rows = null;

        // Header: locate the seat column and the highest-priority match column.
        $header = array_map(
            fn($column) => \core_text::strtolower(trim((string) $column)),
            $cir->get_columns() ?: []
        );
        $seatindex = array_search('seat', $header, true);
        if ($seatindex === false) {
            return [1 => [get_string('importerror_noseatcolumn', 'mod_examcheck')]];
        }
        $matchfield = null;
        $matchindex = false;
        foreach (self::MATCH_COLUMNS as $candidate) {
            $matchindex = array_search($candidate, $header, true);
            if ($matchindex !== false) {
                $matchfield = $candidate;
                break;
            }
        }

        // Existing seats (for the non-override mode) keyed by lowercased label.
        $existingseats = [];
        foreach (seats::get_seats($this->examcheckid) as $seat) {
            $existingseats[\core_text::strtolower($seat->label)] = (int) $seat->id;
        }

        $rostermaps = $matchfield !== null ? $this->build_roster_maps($matchfield) : null;

        $rows = [];
        $seenseats = [];
        $seenusers = [];
        $line = 1;
        $cir->init();
        while ($record = $cir->next()) {
            $line++;
            $lineerrors = [];

            $label = trim((string) ($record[$seatindex] ?? ''));
            if ($label === '') {
                $lineerrors[] = get_string('importerror_missingseat', 'mod_examcheck');
            } else if (\core_text::strlen($label) > seats::LABEL_MAX_LENGTH) {
                $lineerrors[] = get_string('importerror_seatlabeltoolong', 'mod_examcheck', $label);
            } else {
                $labelkey = \core_text::strtolower($label);
                if (isset($seenseats[$labelkey])) {
                    $lineerrors[] = get_string('importerror_duplicateseat', 'mod_examcheck', $label);
                }
                $seenseats[$labelkey] = true;
                if (!$this->override && !isset($existingseats[$labelkey])) {
                    $lineerrors[] = get_string('importerror_unknownseat', 'mod_examcheck', $label);
                }
            }

            $userid = 0;
            $value = $matchindex !== false ? trim((string) ($record[$matchindex] ?? '')) : '';
            if ($value !== '') {
                $valuekey = \core_text::strtolower($value);
                if (isset($rostermaps['ambiguous'][$valuekey])) {
                    $lineerrors[] = get_string('importerror_ambiguousstudent', 'mod_examcheck', $value);
                } else if (isset($rostermaps['roster'][$valuekey])) {
                    $userid = $rostermaps['roster'][$valuekey];
                    if (isset($seenusers[$userid])) {
                        $lineerrors[] = get_string('importerror_duplicatestudent', 'mod_examcheck', $value);
                    }
                    $seenusers[$userid] = true;
                } else if ($this->value_matches_any_user($matchfield, $value)) {
                    $lineerrors[] = get_string('importerror_notonroster', 'mod_examcheck', $value);
                } else {
                    $lineerrors[] = get_string('importerror_unmatchedstudent', 'mod_examcheck', $value);
                }
            }

            if ($lineerrors) {
                $errors[$line] = $lineerrors;
            }
            $rows[] = ['label' => $label, 'userid' => $userid];
        }

        if (!$errors) {
            $this->rows = $rows;
        }
        return $errors;
    }

    /**
     * Apply a previously validated file: replace the list in override mode,
     * then align every listed seat's assignment with the file, all in one
     * transaction (events fired inside are dispatched only on commit).
     *
     * @return array{seats: int, assigned: int, unassigned: int} Result counts.
     * @throws \coding_exception When called without a clean validate() first.
     * @throws moodle_exception When a concurrent change makes a row unappliable.
     */
    public function apply(): array {
        global $DB, $USER;

        if ($this->rows === null) {
            throw new \coding_exception('seats_importer::apply() requires a successful validate() first.');
        }

        $transaction = $DB->start_delegated_transaction();

        if ($this->override) {
            seats::replace_list($this->examcheckid, array_map(fn($row) => $row['label'], $this->rows));
        }

        // Seat ids keyed by lowercased label (fresh list in override mode).
        $seatids = [];
        foreach (seats::get_seats($this->examcheckid) as $seat) {
            $seatids[\core_text::strtolower($seat->label)] = (int) $seat->id;
        }

        // First pass: free every student the file reseats elsewhere, so swaps
        // (A1<->A2) never trip the one-seat-per-student conflict.
        $currentbyuser = [];
        foreach (seats::get_assignments($this->examcheckid) as $assignment) {
            $currentbyuser[(int) $assignment->userid] = (int) $assignment->seatid;
        }
        foreach ($this->rows as $row) {
            $seatid = $seatids[\core_text::strtolower($row['label'])];
            if ($row['userid'] && isset($currentbyuser[$row['userid']]) && $currentbyuser[$row['userid']] !== $seatid) {
                seats::unassign($currentbyuser[$row['userid']]);
            }
        }

        // Second pass: align each listed seat with the file.
        $assigned = 0;
        $unassigned = 0;
        foreach ($this->rows as $row) {
            $seatid = $seatids[\core_text::strtolower($row['label'])];
            if ($row['userid']) {
                $result = seats::assign($seatid, $row['userid'], (int) $USER->id);
                if ($result['status'] !== 'assigned') {
                    // Only reachable through a concurrent change: abort the lot.
                    throw new moodle_exception('error_usernotonroster', 'mod_examcheck');
                }
                $assigned++;
            } else if (seats::unassign($seatid)['status'] === 'unassigned') {
                $unassigned++;
            }
        }

        $transaction->allow_commit();

        return [
            'seats'      => count($seatids),
            'assigned'   => $assigned,
            'unassigned' => $unassigned,
        ];
    }

    /**
     * Build the roster lookup for the chosen match field: lowercased value =>
     * user id, plus the set of values shared by several roster students.
     *
     * @param string $matchfield One of username, email or idnumber.
     * @return array{roster: array<string, int>, ambiguous: array<string, bool>}
     */
    protected function build_roster_maps(string $matchfield): array {
        global $DB;

        $examcheck = $DB->get_record('examcheck', ['id' => $this->examcheckid], '*', MUST_EXIST);
        $checker = new checker($examcheck, $this->context);

        $roster = [];
        $ambiguous = [];
        foreach ($checker->get_roster(0, [$matchfield]) as $user) {
            $value = \core_text::strtolower(trim((string) ($user->$matchfield ?? '')));
            if ($value === '') {
                continue;
            }
            if (isset($roster[$value])) {
                $ambiguous[$value] = true;
                continue;
            }
            $roster[$value] = (int) $user->id;
        }
        return ['roster' => $roster, 'ambiguous' => $ambiguous];
    }

    /**
     * Whether any user account (roster or not) carries this match value, to
     * distinguish "not on the roster" from "no such user".
     *
     * @param string $matchfield One of username, email or idnumber.
     * @param string $value The raw value from the file.
     * @return bool
     */
    protected function value_matches_any_user(string $matchfield, string $value): bool {
        global $DB;

        $comparison = $DB->sql_equal($matchfield, ':value', false);
        return $DB->record_exists_select('user', "deleted = 0 AND $comparison", ['value' => $value]);
    }
}
