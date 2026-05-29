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

use core\output\renderer_base;
use core\output\renderable;
use core\output\templatable;
use mod_examcheck\local\steps;
use mod_examcheck\table\roster;
use mod_examcheck\table\roster_filterset;
use moodle_url;

/**
 * Renderable for the checking dashboard: the datafilter bar plus the roster dynamic table.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard implements renderable, templatable {
    /** @var int Course module id. */
    protected int $cmid;

    /**
     * Constructor.
     *
     * @param int $cmid Course module id.
     */
    public function __construct(int $cmid) {
        $this->cmid = $cmid;
    }

    /**
     * Export the dashboard for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        global $DB;

        [$course, $cm] = get_course_and_cm_from_cmid($this->cmid, 'examcheck');
        $context = \context_module::instance($cm->id);
        $examcheck = $DB->get_record('examcheck', ['id' => $cm->instance], '*', MUST_EXIST);
        $hassteps = !empty(steps::get_steps((int) $examcheck->id));

        // Render the roster dynamic table (its body reloads over AJAX on filter/sort/hide).
        $table = new roster("examcheck-roster-{$this->cmid}");
        $table->set_filterset(new roster_filterset());
        ob_start();
        $table->out(1000, false);
        $tablehtml = ob_get_clean();

        // Render the datafilter (keyword + group) bar bound to that table.
        $filter = new roster_filter($context, $table->uniqueid, $this->cmid);
        $filterhtml = $output->render_from_template('mod_examcheck/roster_filter', $filter->export_for_template($output));

        // Export honours the viewer's default group so restricted users stay in their room.
        $exportgroup = $this->default_export_group($cm, $context);
        $exportform = ($hassteps && $exportgroup !== -1) ? $output->download_dataformat_selector(
            get_string('export', 'mod_examcheck'),
            new moodle_url('/mod/examcheck/export.php'),
            'dataformat',
            ['id' => $this->cmid, 'group' => $exportgroup]
        ) : '';

        return [
            'cmid'         => $this->cmid,
            'hassteps'     => $hassteps,
            'filter'       => $filterhtml,
            'table'        => $tablehtml,
            'exportform'   => $exportform,
            'pollinterval' => (int) (get_config('mod_examcheck', 'pollinterval') ?? 5),
        ];
    }

    /**
     * The group to export, mirroring the roster's separate-groups restriction.
     *
     * @param \cm_info $cm The course module.
     * @param \context_module $context The module context.
     * @return int 0 = all, -1 = none, otherwise a group id.
     */
    protected function default_export_group(\cm_info $cm, \context_module $context): int {
        if (
            groups_get_activity_groupmode($cm) != SEPARATEGROUPS
                || has_capability('moodle/site:accessallgroups', $context)
        ) {
            return 0;
        }
        $allowed = groups_get_activity_allowed_groups($cm);
        return empty($allowed) ? -1 : (int) array_key_first($allowed);
    }
}
