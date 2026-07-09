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
use core\output\select_menu;
use core\output\templatable;
use moodle_url;

/**
 * Tertiary navigation for the Seats tab: a gradebook-style grouped selector
 * switching between the seat subpages (assignment, edit, import, export).
 *
 * Follows the core_grades general_action_bar pattern: a {@see select_menu}
 * rendered through the core/tertiary_navigation_selector partial, which ships
 * its own change-to-navigate JS so no plugin AMD module is needed.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class seats_action_bar implements renderable, templatable {
    /** @var int Course module id. */
    protected int $cmid;

    /** @var string The active seats action (assign, edit, import or export). */
    protected string $action;

    /**
     * Constructor.
     *
     * @param int $cmid Course module id.
     * @param string $action The active seats action.
     */
    public function __construct(int $cmid, string $action) {
        $this->cmid = $cmid;
        $this->action = $action;
    }

    /**
     * Export the action selector for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $url = fn(string $action) => (new moodle_url(
            '/mod/examcheck/seats.php',
            ['id' => $this->cmid, 'action' => $action]
        ))->out(false);

        // Top-level entry plus two groups, matching the gradebook selector layout.
        $menu = [];
        $menu[$url('assign')] = get_string('seatassignment', 'mod_examcheck');
        $menu[][get_string('seatsetup', 'mod_examcheck')] = [
            $url('edit') => get_string('editseats', 'mod_examcheck'),
        ];
        $menu[][get_string('moremenu')] = [
            $url('import') => get_string('importseats', 'mod_examcheck'),
            $url('export') => get_string('exportseats', 'mod_examcheck'),
        ];

        $selectmenu = new select_menu('examcheckseatsactionselect', $menu, $url($this->action), true);
        $selectmenu->set_label(get_string('seatsnavigationmenu', 'mod_examcheck'), ['class' => 'visually-hidden']);

        return [
            'seatsnavselector' => $selectmenu->export_for_template($output),
        ];
    }
}
