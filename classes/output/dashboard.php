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
use mod_examcheck\local\checker;
use mod_examcheck\local\steps;
use moodle_url;

/**
 * Renderable for the checking dashboard (roster grid).
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class dashboard implements renderable, templatable {

    /** @var checker The checker for this instance. */
    protected checker $checker;

    /** @var int Course module id. */
    protected int $cmid;

    /** @var int Selected group id (0 = all). */
    protected int $groupid;

    /** @var string Rendered group selector HTML. */
    protected string $groupmenu;

    /** @var bool Whether the current user may manage steps. */
    protected bool $canmanage;

    /**
     * Constructor.
     *
     * @param checker $checker The checker for this instance.
     * @param int $cmid Course module id.
     * @param int $groupid Selected group id.
     * @param string $groupmenu Rendered group selector HTML (may be empty).
     * @param bool $canmanage Whether the user may manage steps.
     */
    public function __construct(checker $checker, int $cmid, int $groupid, string $groupmenu, bool $canmanage) {
        $this->checker = $checker;
        $this->cmid = $cmid;
        $this->groupid = $groupid;
        $this->groupmenu = $groupmenu;
        $this->canmanage = $canmanage;
    }

    /**
     * Export the dashboard for the mustache template.
     *
     * @param renderer_base $output The renderer.
     * @return array Template context.
     */
    public function export_for_template(renderer_base $output): array {
        $steps = array_values(steps::get_steps($this->checker->get_instance()->id));
        $roster = $this->checker->get_roster($this->groupid);
        $marks = $this->checker->get_marks();

        // Build the step header with its live progress count.
        $progresscounts = $this->checker->get_progress($this->groupid);
        $total = count($roster);
        $stepheaders = [];
        foreach ($steps as $step) {
            $stepheaders[] = [
                'id'    => (int) $step->id,
                'name'  => format_string($step->name),
                'count' => $progresscounts[$step->id] ?? 0,
                'total' => $total,
            ];
        }

        // Build a row per student with one cell per step.
        $students = [];
        foreach ($roster as $user) {
            $cells = [];
            foreach ($steps as $step) {
                $mark = $marks[$step->id][$user->id] ?? null;
                $cells[] = [
                    'stepid'        => (int) $step->id,
                    'stepname'      => format_string($step->name),
                    'checked'       => (bool) $mark,
                    'checkedbyname' => $mark ? checker::user_label((int) $mark->checkedby) : '',
                    'ago'           => $mark ? checker::relative_time((int) $mark->timecreated) : '',
                    'timecreated'   => $mark ? (int) $mark->timecreated : 0,
                ];
            }
            $students[] = [
                'userid'      => (int) $user->id,
                'fullname'    => fullname($user),
                'picture'     => $output->user_picture($user, ['size' => 35, 'link' => false]),
                'idnumber'    => $user->idnumber ?? '',
                'hasidnumber' => !empty($user->idnumber),
                'cells'       => $cells,
            ];
        }

        return [
            'cmid'        => $this->cmid,
            'groupid'     => $this->groupid,
            'groupmenu'   => $this->groupmenu,
            'hasgroupmenu' => trim($this->groupmenu) !== '',
            'steps'       => $stepheaders,
            'students'    => $students,
            'total'       => count($roster),
            'hassteps'    => !empty($steps),
            'hasstudents' => !empty($roster),
            'canmanage'   => $this->canmanage,
            'scanurl'     => (new moodle_url('/mod/examcheck/scan.php',
                ['id' => $this->cmid, 'group' => $this->groupid]))->out(false),
            'manageurl'   => (new moodle_url('/mod/examcheck/manage.php', ['id' => $this->cmid]))->out(false),
            'pollinterval' => (int) (get_config('mod_examcheck', 'pollinterval') ?? 5),
            'exportform'  => (!empty($roster) && !empty($steps)) ? $output->download_dataformat_selector(
                get_string('export', 'mod_examcheck'),
                new moodle_url('/mod/examcheck/export.php'),
                'dataformat',
                ['id' => $this->cmid, 'group' => $this->groupid]
            ) : '',
        ];
    }
}
