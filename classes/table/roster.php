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
use core\output\checkbox_toggleall;
use core_table\dynamic as dynamic_table;
use core_table\local\filter\filterset;
use html_writer;
use mod_examcheck\local\checker;
use mod_examcheck\local\scanfield;
use mod_examcheck\local\steps;
use moodle_url;
use stdClass;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/tablelib.php');

/**
 * Checking roster as a core dynamic table: one row per student, one column per step.
 *
 * The course module id is carried in the unique id (examcheck-roster-{cmid}) so the
 * dynamic-table AJAX endpoint can rebuild the table from the request alone. Each step
 * cell holds the live toggle button; the framework provides column show/hide, sorting
 * and the datafilter search bar.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class roster extends \table_sql implements dynamic_table {
    /** @var int Course module id, parsed from the unique id. */
    protected int $cmid = 0;

    /** @var context_module The module context. */
    protected context_module $context;

    /** @var stdClass The examcheck instance record. */
    protected stdClass $examcheck;

    /** @var checker The checker for this instance. */
    protected checker $checker;

    /** @var stdClass[] Ordered step records. */
    protected array $steps = [];

    /** @var string[] Identity fields (email, etc.) the current user may see. */
    protected array $extrafields = [];

    /** @var string The activity's scan match field key (idnumber, userid or profile_field_x). */
    protected string $matchfield = 'idnumber';

    /** @var array<int, string> User id => their value for the match field. */
    protected array $matchvalues = [];

    /** @var array<int, string> Step id => display name. */
    protected array $stepnames = [];

    /** @var array<int, array<int, stdClass>> Marks indexed [stepid][userid]. */
    protected array $marks = [];

    /** @var int Effective group constraint: 0 = all, -1 = none, otherwise a group id. */
    protected int $effectivegroup = 0;

    /**
     * Constructor: derive the course module id from the unique id.
     *
     * @param string $uniqueid Of the form "examcheck-roster-{cmid}".
     */
    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);
        if (preg_match('/(\d+)$/', $uniqueid, $matches)) {
            $this->cmid = (int) $matches[1];
        }
    }

    /**
     * Resolve the instance, load marks, work out the group constraint and define columns.
     *
     * @param filterset $filterset The filterset from the request.
     */
    public function set_filterset(filterset $filterset): void {
        global $DB;

        [$course, $cm] = get_course_and_cm_from_cmid($this->cmid, 'examcheck');
        $this->context = context_module::instance($cm->id);
        $this->examcheck = $DB->get_record('examcheck', ['id' => $cm->instance], '*', MUST_EXIST);
        $this->checker = new checker($this->examcheck, $this->context);
        $this->steps = array_values(steps::get_steps((int) $this->examcheck->id));
        $this->marks = $this->checker->get_marks();
        // The scan match field (idnumber, userid or a custom profile field) gets its own column.
        $this->matchfield = (string) ($this->examcheck->scanfield ?: 'idnumber');

        // Identity fields (email, etc.) shown only to viewers with permission to see them.
        // Drop the match field from here so it is not shown twice.
        $this->extrafields = array_values(array_filter(
            \core_user\fields::get_identity_fields($this->context, false),
            fn($field) => $field !== $this->matchfield
        ));
        foreach ($this->steps as $step) {
            // Pass the context explicitly: the AJAX endpoint has not set $PAGE->context yet.
            $this->stepnames[(int) $step->id] = format_string($step->name, true, ['context' => $this->context]);
        }

        $this->effectivegroup = $this->resolve_group($cm, $filterset);
        $this->guess_base_url();

        parent::set_filterset($filterset);
    }

    /**
     * Define the columns then render, so the select-all header (which needs a validated
     * page context) is built only once the dynamic-table endpoint has set the context.
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
     * Work out which group the roster is restricted to.
     *
     * Under separate groups a user without accessallgroups is confined to their own
     * groups: an out-of-reach group selection falls back to one of their groups, and a
     * user in no group sees no students. This mirrors the dashboard's access control.
     *
     * @param \cm_info|stdClass $cm The course module.
     * @param filterset $filterset The request filterset.
     * @return int 0 = all participants, -1 = none, otherwise a group id.
     */
    protected function resolve_group($cm, filterset $filterset): int {
        $requested = 0;
        if ($filterset->has_filter('groups')) {
            $values = $filterset->get_filter('groups')->get_filter_values();
            if (!empty($values)) {
                $requested = (int) reset($values);
            }
        }

        $separate = groups_get_activity_groupmode($cm) == SEPARATEGROUPS
            && !has_capability('moodle/site:accessallgroups', $this->context);
        if (!$separate) {
            return $requested;
        }

        $allowed = groups_get_activity_allowed_groups($cm);
        if ($requested && isset($allowed[$requested])) {
            return $requested;
        }
        return empty($allowed) ? -1 : (int) array_key_first($allowed);
    }

    /**
     * Define the student column plus one column per step.
     */
    protected function define_table_columns(): void {
        global $OUTPUT;

        // Row-selection column with a select-all toggler in the header.
        $selectall = new checkbox_toggleall('examcheck-roster', true, [
            'id'           => 'examcheck-selectall',
            'name'         => 'examcheck-selectall',
            'label'        => get_string('selectall'),
            'labelclasses' => 'visually-hidden',
            'checked'      => false,
        ]);
        // The scan match field gets its own sortable, hideable column, labelled per the field.
        $columns = ['select', 'fullname', 'matchfield'];
        $headers = [$OUTPUT->render($selectall), get_string('student', 'mod_examcheck'),
            scanfield::get_label($this->matchfield)];

        // Identity columns (email, etc.) the viewer is permitted to see.
        foreach ($this->extrafields as $field) {
            $columns[] = $field;
            $headers[] = \core_user\fields::get_display_name($field);
        }

        foreach ($this->steps as $step) {
            $key = 'step_' . (int) $step->id;
            $columns[] = $key;
            // Pass the context explicitly: the AJAX endpoint has not set $PAGE->context yet.
            $headers[] = format_string($step->name, true, ['context' => $this->context]);
        }

        $this->define_columns($columns);
        $this->define_headers($headers);
        $this->define_header_column('fullname');

        $this->sortable(true, 'fullname');
        $this->no_sorting('select');
        foreach ($this->extrafields as $field) {
            $this->no_sorting($field);
        }
        foreach ($this->steps as $step) {
            $key = 'step_' . (int) $step->id;
            $this->no_sorting($key);
            $this->column_class($key, 'text-center examcheck-stepcol');
        }

        $this->collapsible(true);
        $this->pageable(false);
        $this->set_attribute('class', 'generaltable examcheck-roster align-middle');
    }

    /**
     * Load the roster (honouring the group constraint and keyword search) into rawdata.
     *
     * @param int $pagesize Unused: the whole roster is shown.
     * @param bool $useinitialsbar Unused.
     */
    public function query_db($pagesize, $useinitialsbar = true): void {
        $this->rawdata = [];
        if ($this->effectivegroup === -1) {
            $this->totalrows = 0;
            return;
        }

        $users = $this->checker->get_roster($this->effectivegroup, $this->extrafields);
        $this->matchvalues = $this->load_match_values($users);

        $keywords = [];
        if ($this->get_filterset()->has_filter('keywords')) {
            $keywords = $this->get_filterset()->get_filter('keywords')->get_filter_values();
        }
        foreach ($keywords as $keyword) {
            $needle = \core_text::strtolower(trim($keyword));
            if ($needle === '') {
                continue;
            }
            foreach ($users as $id => $user) {
                // Match name, the match field, and the visible identity fields.
                $parts = [fullname($user), $this->matchvalues[$id] ?? ''];
                foreach ($this->extrafields as $field) {
                    $parts[] = (string) ($user->$field ?? '');
                }
                if (strpos(\core_text::strtolower(implode(' ', $parts)), $needle) === false) {
                    unset($users[$id]);
                }
            }
        }

        $sortcolumns = $this->get_sort_columns();
        if (isset($sortcolumns['matchfield'])) {
            $dir = (int) $sortcolumns['matchfield'] === SORT_DESC ? -1 : 1;
            uasort($users, fn($a, $b) => $dir * strnatcasecmp(
                $this->matchvalues[$a->id] ?? '',
                $this->matchvalues[$b->id] ?? ''
            ));
        } else if (isset($sortcolumns['fullname']) && (int) $sortcolumns['fullname'] === SORT_DESC) {
            $users = array_reverse($users, true);
        }

        $this->rawdata = $users;
        $this->totalrows = count($users);
    }

    /**
     * Build a map of user id => their value for the configured scan match field.
     *
     * @param stdClass[] $users Roster users keyed by id.
     * @return array<int, string>
     */
    protected function load_match_values(array $users): array {
        global $DB;

        $map = [];
        if ($this->matchfield === 'userid') {
            foreach ($users as $user) {
                $map[(int) $user->id] = (string) $user->id;
            }
            return $map;
        }

        if (strpos($this->matchfield, scanfield::PROFILE_PREFIX) === 0) {
            foreach ($users as $user) {
                $map[(int) $user->id] = '';
            }
            $shortname = substr($this->matchfield, strlen(scanfield::PROFILE_PREFIX));
            $field = $DB->get_record('user_info_field', ['shortname' => $shortname], 'id');
            if ($field && $users) {
                [$insql, $params] = $DB->get_in_or_equal(array_keys($map), SQL_PARAMS_NAMED, 'u');
                $params['fieldid'] = $field->id;
                $records = $DB->get_records_select(
                    'user_info_data',
                    "fieldid = :fieldid AND userid $insql",
                    $params,
                    '',
                    'userid, data'
                );
                foreach ($records as $record) {
                    $map[(int) $record->userid] = (string) $record->data;
                }
            }
            return $map;
        }

        // Default: a standard user-table field (idnumber) already loaded on the record.
        foreach ($users as $user) {
            $map[(int) $user->id] = (string) ($user->{$this->matchfield} ?? '');
        }
        return $map;
    }

    /**
     * The row-selection checkbox.
     *
     * @param stdClass $row The user record.
     * @return string
     */
    public function col_select($row): string {
        global $OUTPUT;

        $checkbox = new checkbox_toggleall('examcheck-roster', false, [
            'classes'      => 'usercheckbox',
            'id'           => 'examcheck-select-' . (int) $row->id,
            'name'         => 'userid[]',
            'value'        => (int) $row->id,
            'checked'      => false,
            'label'        => get_string('selectitem', 'moodle', fullname($row)),
            'labelclasses' => 'accesshide',
        ]);
        return $OUTPUT->render($checkbox);
    }

    /**
     * The student column: picture and profile-linked name.
     *
     * @param stdClass $row The user record.
     * @return string
     */
    public function col_fullname($row): string {
        global $OUTPUT;

        $picture = $OUTPUT->user_picture($row, ['size' => 35, 'link' => false]);
        $profileurl = new moodle_url('/user/view.php', ['id' => (int) $row->id, 'course' => $this->examcheck->course]);
        $name = html_writer::link($profileurl, fullname($row), ['class' => 'examcheck-studentname']);

        return html_writer::tag(
            'span',
            $picture . $name,
            ['class' => 'd-inline-flex align-items-center gap-2']
        );
    }

    /**
     * The scan match field column (the value matched against scanned codes).
     *
     * @param stdClass $row The user record.
     * @return string
     */
    public function col_matchfield($row): string {
        return s($this->matchvalues[(int) $row->id] ?? '');
    }

    /**
     * Step columns: render the live toggle button from the preloaded mark, if any.
     *
     * @param string $colname The column name.
     * @param stdClass $row The user record.
     * @return string|null Cell HTML, or null when not a step column.
     */
    public function other_cols($colname, $row) {
        if (in_array($colname, $this->extrafields, true)) {
            return s($row->$colname ?? '');
        }
        if (strpos($colname, 'step_') !== 0) {
            return null;
        }
        $stepid = (int) substr($colname, strlen('step_'));
        $mark = $this->marks[$stepid][$row->id] ?? null;
        return $this->render_toggle($stepid, (int) $row->id, $mark);
    }

    /**
     * Build a single toggle button cell.
     *
     * @param int $stepid The step id.
     * @param int $userid The student id.
     * @param stdClass|null $mark The existing mark, or null.
     * @return string
     */
    protected function render_toggle(int $stepid, int $userid, ?stdClass $mark): string {
        $checked = (bool) $mark;
        $stepname = $this->stepnames[$stepid] ?? '';

        if ($checked) {
            $title = get_string('checkedbyon', 'mod_examcheck', (object) [
                'user' => checker::user_label((int) $mark->checkedby),
                'ago'  => checker::relative_time((int) $mark->timecreated),
            ]);
            $sr = get_string('checked', 'mod_examcheck');
        } else {
            $title = get_string('markaction', 'mod_examcheck', $stepname);
            $sr = get_string('notchecked', 'mod_examcheck');
        }

        $icon = html_writer::tag('i', '', [
            'class' => 'fa ' . ($checked ? 'fa-check' : 'fa-square-o'),
            'aria-hidden' => 'true',
        ]);
        $srtext = html_writer::tag('span', $sr, ['class' => 'sr-only']);

        return html_writer::tag('button', $icon . $srtext, [
            'type' => 'button',
            'class' => 'btn examcheck-cell ' . ($checked ? 'btn-success' : 'btn-outline-secondary'),
            'data-action' => 'toggle',
            'data-stepid' => $stepid,
            'data-userid' => $userid,
            'data-checked' => $checked ? '1' : '0',
            // The group the roster was rendered for, so marking/polling validates access correctly.
            'data-groupid' => max(0, $this->effectivegroup),
            'aria-pressed' => $checked ? 'true' : 'false',
            'title' => $title,
        ]);
    }

    /**
     * Never offer the hide control on the student (header) column.
     *
     * @param string $column The column name.
     * @param int $index The column index.
     * @return string
     */
    protected function show_hide_link($column, $index) {
        if ($index === 0) {
            return '';
        }
        return parent::show_hide_link($column, $index);
    }

    /**
     * Base url for non-dynamic fallbacks (sorting/hiding links).
     */
    public function guess_base_url(): void {
        $this->baseurl = new moodle_url('/mod/examcheck/view.php', ['id' => $this->cmid]);
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
     * Only users who can view the activity may load the table.
     *
     * @return bool
     */
    public function has_capability(): bool {
        return has_capability('mod/examcheck:view', $this->context);
    }
}
