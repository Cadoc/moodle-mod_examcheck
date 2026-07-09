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

use core\output\renderable;
use core\output\renderer_base;
use core\output\templatable;
use mod_examcheck\local\checker;
use mod_examcheck\local\seats;
use mod_examcheck\table\seat_assign;
use mod_examcheck\table\seat_assign_filterset;

/**
 * Renderable for the seat assignment page: the assignment count, the auto-assign
 * trigger and the seat table.
 *
 * The table itself is the {@see seat_assign} dynamic table, captured here the same way
 * {@see dashboard} captures the roster, so its body reloads over AJAX on sort and paging.
 *
 * The free-seat and unseated-student counts are handed to the page so the JS can size the
 * auto-assign confirmation without a roundtrip, and keep them current as seats are filled.
 * They are not derivable from each other: under separate groups the assignment count
 * includes students the caller cannot reach, so the unseated count is measured against
 * the caller's own roster.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seat_assign_page implements renderable, templatable {
    /** @var int Rows per page. */
    const PAGE_SIZE = 50;

    /** @var int Course module id. */
    protected int $cmid;

    /** @var int The examcheck instance id. */
    protected int $examcheckid;

    /**
     * Constructor.
     *
     * @param int $cmid Course module id.
     * @param int $examcheckid The examcheck instance id.
     */
    public function __construct(int $cmid, int $examcheckid) {
        $this->cmid = $cmid;
        $this->examcheckid = $examcheckid;
    }

    /**
     * Export the seat table for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $table = new seat_assign("examcheck-seatassign-{$this->cmid}");
        $table->set_filterset(new seat_assign_filterset());
        ob_start();
        $table->out(self::PAGE_SIZE, false);
        $tablehtml = ob_get_clean();

        $assignedcount = seats::count_assignments($this->examcheckid);
        $seatcount = seats::count_seats($this->examcheckid);
        $freeseats = $seatcount - $assignedcount;

        // The students this caller may seat: their reachable roster, minus whoever
        // already sits somewhere. A group-restricted teacher sees only their own.
        $checker = checker::from_cmid($this->cmid);
        $group = $checker->resolve_effective_group(0);
        $roster = $group === -1 ? [] : $checker->get_roster($group);
        $unseatedstudents = count(array_diff_key($roster, seats::get_user_seat_labels($this->examcheckid)));

        return [
            'cmid'             => $this->cmid,
            'assignedcount'    => $assignedcount,
            'seatcount'        => $seatcount,
            'freeseats'        => $freeseats,
            'unseatedstudents' => $unseatedstudents,
            'canautoassign'    => $unseatedstudents > 0 && $freeseats > 0,
            'table'            => $tablehtml,
        ];
    }
}
