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
use mod_examcheck\local\uncheck_reason;
use mod_examcheck\table\roster;
use mod_examcheck\table\roster_filterset;
use moodle_url;

/**
 * Renderable for the checking dashboard: the datafilter bar, the roster dynamic table,
 * and the bottom "With selected students" bulk-action bar.
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
        $steps = array_values(steps::get_steps((int) $examcheck->id));
        $hassteps = !empty($steps);

        // Render the roster dynamic table (its body reloads over AJAX on filter/sort/hide).
        $table = new roster("examcheck-roster-{$this->cmid}");
        $table->set_filterset(new roster_filterset());
        ob_start();
        $table->out(50, false);
        $tablehtml = ob_get_clean();

        // Render the datafilter (keyword + group) bar bound to that table.
        $filter = new roster_filter($context, $table->uniqueid, $this->cmid);
        $filterhtml = $output->render_from_template('mod_examcheck/roster_filter', $filter->export_for_template($output));

        // Note get_config() returns false (not null) when the key is unset, so fall
        // back to the admin default explicitly rather than relying on null coalescing.
        $poll = get_config('mod_examcheck', 'pollinterval');

        $reasons = uncheck_reason::get_for_instance($examcheck);

        return [
            'cmid'           => $this->cmid,
            'uncheckreasons' => json_encode($reasons),
            'hassteps'     => $hassteps,
            'filter'       => $filterhtml,
            'table'        => $tablehtml,
            'withselected' => $hassteps ? $this->render_actions_menu($output, $context, $steps) : '',
            'exporturl'    => (new moodle_url('/mod/examcheck/export.php'))->out(false),
            'sesskey'      => sesskey(),
            'pollinterval' => $poll === false ? 5 : (int) $poll,
        ];
    }

    /**
     * Render the "With selected students" action dropdown via a Mustache template.
     *
     * @param renderer_base $output The renderer.
     * @param \context_module $context The module context.
     * @param \stdClass[] $steps The ordered step records.
     * @return string The rendered label + select.
     */
    protected function render_actions_menu(renderer_base $output, \context_module $context, array $steps): string {
        $exports = [
            ['value' => 'csv', 'label' => get_string('dataformat', 'dataformat_csv')],
            ['value' => 'excel', 'label' => get_string('dataformat', 'dataformat_excel')],
            ['value' => 'pdf', 'label' => get_string('dataformat', 'dataformat_pdf')],
        ];

        $stepoptions = [];
        $cancheck = has_capability('mod/examcheck:check', $context);
        if ($cancheck) {
            foreach ($steps as $step) {
                $name = format_string($step->name, true, ['context' => $context]);
                $stepoptions[] = [
                    'stepid'      => (int) $step->id,
                    'marklabel'   => get_string('bulkcheck', 'mod_examcheck', $name),
                    'unmarklabel' => get_string('bulkuncheck', 'mod_examcheck', $name),
                ];
            }
        }

        return $output->render_from_template('mod_examcheck/actions_menu', [
            'cancheck' => $cancheck,
            'exports'  => $exports,
            'steps'    => $stepoptions,
        ]);
    }
}
