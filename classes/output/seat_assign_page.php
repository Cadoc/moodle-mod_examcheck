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
use mod_examcheck\local\seats;
use mod_examcheck\table\seat_assign;
use mod_examcheck\table\seat_assign_filterset;

/**
 * Renderable for the seat assignment page: the assignment count and the seat table.
 *
 * The table itself is the {@see seat_assign} dynamic table, captured here the same way
 * {@see dashboard} captures the roster, so its body reloads over AJAX on sort and paging.
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

        return [
            'cmid'          => $this->cmid,
            'assignedcount' => seats::count_assignments($this->examcheckid),
            'seatcount'     => seats::count_seats($this->examcheckid),
            'table'         => $tablehtml,
        ];
    }
}
