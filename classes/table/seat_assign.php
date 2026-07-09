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

namespace mod_examcheck\table;

use context;
use context_module;
use core_table\dynamic as dynamic_table;
use core_table\local\filter\filterset;
use core_user;
use html_writer;
use mod_examcheck\local\seats;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Seat assignment as a core dynamic table: one row per seat, one student selector per row.
 *
 * Built like {@see roster}: the course module id travels in the unique id
 * (examcheck-seatassign-{cmid}) so the dynamic-table AJAX endpoint can rebuild the
 * table from the request alone, and the whole seat list is loaded into memory and
 * sorted there. The framework provides the sortable headers and the paging bar.
 *
 * Each student cell server-renders only the currently assigned option; the AMD module
 * enhances that select into an AJAX autocomplete, and re-enhances every cell after the
 * framework swaps the table body on sort or paging.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seat_assign extends \table_sql implements dynamic_table {
    /** @var int Course module id, parsed from the unique id. */
    protected int $cmid = 0;

    /** @var context_module The module context. */
    protected context_module $context;

    /** @var stdClass The examcheck instance record. */
    protected stdClass $examcheck;

    /**
     * Constructor: derive the course module id from the unique id.
     *
     * The cmid travels via the unique id rather than the filterset because the
     * dynamic-table AJAX endpoint instantiates the table from the unique id alone,
     * before the filterset has been deserialised.
     *
     * @param string $uniqueid Of the form "examcheck-seatassign-{cmid}".
     * @throws \coding_exception When the unique id does not encode a cmid.
     */
    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);
        if (!preg_match('/^examcheck-seatassign-(\d+)$/', $uniqueid, $matches)) {
            throw new \coding_exception(
                "mod_examcheck\\table\\seat_assign unique id must match 'examcheck-seatassign-<cmid>', got: '$uniqueid'"
            );
        }
        $this->cmid = (int) $matches[1];
    }

    /**
     * Resolve the instance and its context.
     *
     * @param filterset $filterset The filterset from the request (this table has no filters).
     */
    public function set_filterset(filterset $filterset): void {
        global $DB;

        [, $cm] = get_course_and_cm_from_cmid($this->cmid, 'examcheck');
        $this->context = context_module::instance($cm->id);
        $this->examcheck = $DB->get_record('examcheck', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->guess_base_url();

        parent::set_filterset($filterset);
    }

    /**
     * Define the columns then render.
     *
     * @param int $pagesize Rows per page.
     * @param bool $useinitialsbar Whether to show the initials bar.
     * @param string $downloadhelpbutton Download help button.
     */
    public function out($pagesize, $useinitialsbar, $downloadhelpbutton = '') {
        $this->define_table_columns();
        parent::out($pagesize, $useinitialsbar, $downloadhelpbutton);
    }

    /**
     * The seat column plus the student selector column.
     */
    protected function define_table_columns(): void {
        $this->define_columns(['seat', 'student']);
        $this->define_headers([
            get_string('seat', 'mod_examcheck'),
            get_string('student', 'mod_examcheck'),
        ]);
        $this->define_header_column('seat');

        $this->sortable(true, 'seat');
        $this->column_class('student', 'w-75');

        // Two columns, both meaningful: nothing to collapse.
        $this->collapsible(false);
        $this->pageable(true);
        $this->set_attribute('class', 'generaltable examcheck-seat-assign align-middle');
    }

    /**
     * Load every seat with its current occupant into rawdata.
     *
     * @param int $pagesize Rows per page.
     * @param bool $useinitialsbar Unused.
     */
    public function query_db($pagesize, $useinitialsbar = true): void {
        $examcheckid = (int) $this->examcheck->id;
        $assignments = seats::get_assignments($examcheckid);

        $rows = [];
        foreach (seats::get_seats($examcheckid) as $seat) {
            $assignment = $assignments[(int) $seat->id] ?? null;
            $userid = $assignment ? (int) $assignment->userid : 0;
            $userlabel = '';
            if ($userid) {
                $record = core_user::get_user($userid, '*', IGNORE_MISSING);
                $userlabel = $record ? fullname($record) : (string) $userid;
            }
            $rows[(int) $seat->id] = (object) [
                'seatid'    => (int) $seat->id,
                'label'     => $seat->label,
                'userid'    => $userid,
                'userlabel' => $userlabel,
            ];
        }

        // Single-column sort. The seat list is already in memory so we sort the array in
        // place. Seats keep the sortorder the teacher authored them in (so "Room 2" stays
        // ahead of "Room 10" without relying on the labels being sortable at all), which
        // means ascending is the query order and descending is its reverse. Students sort
        // naturally by name, with the unassigned seats grouped at the empty-label end.
        $sortcolumns = $this->get_sort_columns();
        if (isset($sortcolumns['student'])) {
            $dir = (int) $sortcolumns['student'] === SORT_DESC ? -1 : 1;
            uasort($rows, fn($a, $b) => $dir * strnatcasecmp($a->userlabel, $b->userlabel));
        } else if (isset($sortcolumns['seat']) && (int) $sortcolumns['seat'] === SORT_DESC) {
            $rows = array_reverse($rows, true);
        }

        $this->totalrows = count($rows);
        $this->rawdata = array_slice($rows, $this->get_page_start(), $this->get_page_size(), true);
    }

    /**
     * The seat column: the seat label.
     *
     * @param stdClass $row The seat row.
     * @return string
     */
    public function col_seat($row): string {
        return s($row->label);
    }

    /**
     * The student column: a light-DOM select the AMD module enhances into an autocomplete.
     *
     * Only the currently assigned option is rendered, so a large roster never bloats the
     * page; the candidates are loaded over AJAX. Deliberately shows the full name alone —
     * an identity value here would leak to viewers who may not see one.
     *
     * @param stdClass $row The seat row.
     * @return string
     */
    public function col_student($row): string {
        $selectid = 'examcheck-seat-select-' . $row->seatid;

        $label = html_writer::tag('label', get_string('seatselectfor', 'mod_examcheck', $row->label), [
            'class' => 'visually-hidden',
            'for'   => $selectid,
        ]);

        $options = html_writer::tag('option', '', ['value' => '']);
        if ($row->userid) {
            $options .= html_writer::tag('option', s($row->userlabel), [
                'value'    => $row->userid,
                'selected' => 'selected',
            ]);
        }
        $select = html_writer::tag('select', $options, [
            'id'          => $selectid,
            'data-region' => 'examcheck-seat-select',
            'data-seatid' => $row->seatid,
        ]);

        return html_writer::div($label . $select, '', [
            'data-region'        => 'examcheck-seat-cell',
            'data-seatid'        => $row->seatid,
            'data-previousid'    => $row->userid,
            'data-previouslabel' => $row->userlabel,
        ]);
    }

    /**
     * Base url for non-dynamic fallbacks (sorting links).
     */
    public function guess_base_url(): void {
        $this->baseurl = new moodle_url('/mod/examcheck/seats.php', [
            'id'     => $this->cmid,
            'action' => 'assign',
        ]);
    }

    /**
     * The module context (available after set_filterset).
     *
     * @return context
     */
    public function get_context(): context {
        return $this->context;
    }

    /**
     * Only seat managers of an activity with the feature enabled may load the table.
     *
     * @return bool
     */
    public function has_capability(): bool {
        return !empty($this->examcheck->enableseats)
            && has_capability('mod/examcheck:manageseats', $this->context);
    }
}
