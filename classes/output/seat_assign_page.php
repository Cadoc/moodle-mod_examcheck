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
use core_user;
use mod_examcheck\local\seats;

/**
 * Renderable for the seat assignment page: one row per seat, each with a
 * light-DOM select that the JS enhances into a student autocomplete.
 *
 * Only the currently assigned option is server-rendered per select; the
 * autocomplete loads candidates over AJAX, so a large roster never bloats the
 * initial page.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seat_assign_page implements renderable, templatable {
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
     * Export the seat rows for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $assignments = seats::get_assignments($this->examcheckid);

        $rows = [];
        foreach (seats::get_seats($this->examcheckid) as $seat) {
            $assignment = $assignments[(int) $seat->id] ?? null;
            $user = null;
            if ($assignment) {
                $record = core_user::get_user((int) $assignment->userid, '*', IGNORE_MISSING);
                $user = [
                    'id'    => (int) $assignment->userid,
                    'label' => $record ? fullname($record) : (string) $assignment->userid,
                ];
            }
            $rows[] = [
                'seatid' => (int) $seat->id,
                'label'  => $seat->label,
                'user'   => $user,
            ];
        }

        return [
            'cmid'          => $this->cmid,
            'seats'         => $rows,
            'assignedcount' => count($assignments),
            'seatcount'     => count($rows),
        ];
    }
}
