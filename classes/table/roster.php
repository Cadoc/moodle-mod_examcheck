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
use mod_examcheck\local\exemption_manager;
use mod_examcheck\local\flag_manager;
use mod_examcheck\local\scanfield;
use mod_examcheck\local\steps;
use mod_examcheck\local\uncheck_reason;
use moodle_url;
use stdClass;

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

    /** @var array<int, array<int, stdClass>> Exemptions indexed [stepid][userid]. */
    protected array $exemptions = [];

    /** @var array<int, stdClass[]> Flags indexed by userid. */
    protected array $flags = [];

    /** @var array<int, stdClass> Step records keyed by step id (includes uncheckmode). */
    protected array $steprecords = [];

    /** @var int Effective group constraint: 0 = all, -1 = none, otherwise a group id. */
    protected int $effectivegroup = 0;

    /** @var int Grouping id restricting which groups belong to this activity (0 = all course groups). */
    protected int $groupingid = 0;

    /** @var array<int, stdClass> All groups for this activity, keyed by group id. */
    protected array $activitygroups = [];

    /** @var array<int, string[]> User id => group names for the current page, populated when groups exist. */
    protected array $usergroups = [];

    /**
     * Constructor: derive the course module id from the unique id.
     *
     * The cmid travels via the unique id rather than the filterset because the
     * dynamic-table AJAX endpoint instantiates the table from the unique id
     * alone, before the filterset has been deserialised. The strict regex below
     * makes the format explicit and fails fast on misuse.
     *
     * @param string $uniqueid Of the form "examcheck-roster-{cmid}".
     * @throws \coding_exception When the unique id does not encode a cmid.
     */
    public function __construct(string $uniqueid) {
        parent::__construct($uniqueid);
        if (!preg_match('/^examcheck-roster-(\d+)$/', $uniqueid, $matches)) {
            throw new \coding_exception(
                "mod_examcheck\\table\\roster unique id must match 'examcheck-roster-<cmid>', got: '$uniqueid'"
            );
        }
        $this->cmid = (int) $matches[1];
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
        $this->marks      = $this->checker->get_marks();
        $this->exemptions = exemption_manager::get_exemptions_for_instance((int) $this->examcheck->id);
        $this->flags      = flag_manager::get_flags_for_instance((int) $this->examcheck->id);
        foreach ($this->steps as $step) {
            $this->steprecords[(int) $step->id] = $step;
        }
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
        $this->groupingid = (int) $cm->groupingid;
        $this->activitygroups = groups_get_all_groups($course->id, 0, $this->groupingid);
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

        if (!empty($this->activitygroups)) {
            $columns[] = 'groups';
            $headers[] = get_string('groups');
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
        foreach ($this->steps as $step) {
            $key = 'step_' . (int) $step->id;
            $this->column_class($key, 'text-center examcheck-stepcol');
        }

        $this->collapsible(true);
        $this->pageable(true);
        $this->set_attribute('class', 'generaltable examcheck-roster align-middle');
    }

    /**
     * Load the roster (honouring the group constraint and keyword search) into rawdata.
     *
     * @param int $pagesize Rows per page.
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

        $this->apply_checkstatus_filter($users);

        if (!empty($this->activitygroups)) {
            $this->usergroups = $this->load_user_groups($users);
        }

        // Single-column sort. The roster is already in memory so we sort the array
        // in place; fullname keeps its DB order or array_reverse.
        $sortcolumns = $this->get_sort_columns();
        if (isset($sortcolumns['matchfield'])) {
            $dir = (int) $sortcolumns['matchfield'] === SORT_DESC ? -1 : 1;
            uasort($users, fn($a, $b) => $dir * strnatcasecmp(
                $this->matchvalues[$a->id] ?? '',
                $this->matchvalues[$b->id] ?? ''
            ));
        } else {
            foreach ($sortcolumns as $col => $dir) {
                $sign = (int) $dir === SORT_DESC ? -1 : 1;
                if (in_array($col, $this->extrafields, true)) {
                    uasort($users, fn($a, $b) => $sign * strnatcasecmp(
                        (string) ($a->{$col} ?? ''),
                        (string) ($b->{$col} ?? '')
                    ));
                    break;
                }
                if ($col === 'groups') {
                    uasort($users, fn($a, $b) => $sign * strnatcasecmp(
                        implode(', ', $this->usergroups[(int) $a->id] ?? []),
                        implode(', ', $this->usergroups[(int) $b->id] ?? [])
                    ));
                    break;
                }
                if (str_starts_with($col, 'step_')) {
                    $stepid = (int) substr($col, 5);
                    if (isset($this->stepnames[$stepid])) {
                        uasort($users, fn($a, $b) => $sign * (
                            (isset($this->marks[$stepid][(int) $a->id]) ? 1 : 0) -
                            (isset($this->marks[$stepid][(int) $b->id]) ? 1 : 0)
                        ));
                        break;
                    }
                }
            }
            if (isset($sortcolumns['fullname']) && (int) $sortcolumns['fullname'] === SORT_DESC) {
                $users = array_reverse($users, true);
            }
        }

        $this->totalrows = count($users);
        $this->rawdata = array_slice($users, $this->get_page_start(), $this->get_page_size(), true);
    }

    /**
     * Drop users from $users that don't match every selected check-status chip.
     *
     * Each chip is "{stepid}:{checked|notchecked}". Several chips are AND-ed, so
     * picking "Attendance: not checked" + "ID: not checked" shows only students
     * still missing both. Reuses the already-loaded marks map — no extra DB.
     *
     * @param stdClass[] $users Roster keyed by user id. Modified in place.
     */
    protected function apply_checkstatus_filter(array &$users): void {
        $filterset = $this->get_filterset();
        if (!$filterset->has_filter('checkstatus')) {
            return;
        }
        $values = $filterset->get_filter('checkstatus')->get_filter_values();
        if (empty($values)) {
            return;
        }

        $conditions = [];
        foreach ($values as $value) {
            if (!is_string($value) || strpos($value, ':') === false) {
                continue;
            }
            [$stepid, $status] = explode(':', $value, 2);
            $stepid = (int) $stepid;
            if ($stepid <= 0 || !isset($this->stepnames[$stepid])) {
                continue;
            }
            $conditions[] = [$stepid, $status === 'checked'];
        }
        if (empty($conditions)) {
            return;
        }

        foreach ($users as $id => $user) {
            foreach ($conditions as [$stepid, $mustbechecked]) {
                $ischecked = isset($this->marks[$stepid][$id]);
                if ($ischecked !== $mustbechecked) {
                    unset($users[$id]);
                    continue 2;
                }
            }
        }
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
     * Build a map of user id => sorted group name list for the given users.
     *
     * Only groups that belong to this activity's grouping (or all course groups
     * when no grouping is set) are included, matching the access logic used elsewhere.
     *
     * @param stdClass[] $users Roster users keyed by id.
     * @return array<int, string[]>
     */
    protected function load_user_groups(array $users): array {
        global $DB;

        if (empty($users) || empty($this->activitygroups)) {
            return [];
        }

        [$groupsql, $groupparams] = $DB->get_in_or_equal(array_keys($this->activitygroups), SQL_PARAMS_NAMED, 'g');
        [$usersql, $userparams] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED, 'u');

        $records = $DB->get_records_sql(
            "SELECT gm.userid, g.name
               FROM {groups_members} gm
               JOIN {groups} g ON g.id = gm.groupid
              WHERE gm.groupid $groupsql AND gm.userid $usersql
              ORDER BY g.name ASC",
            array_merge($groupparams, $userparams)
        );

        $map = array_fill_keys(array_keys($users), []);
        foreach ($records as $record) {
            $map[(int) $record->userid][] = $record->name;
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

        $userflags = $this->flags[(int) $row->id] ?? [];
        if (!empty($userflags) && has_capability('mod/examcheck:viewflags', $this->context)) {
            $flaglabels = implode(', ', array_map(
                fn(stdClass $f): string => flag_manager::get_type_label($f->flagtype),
                $userflags
            ));
            $flagicon = html_writer::tag('i', '', [
                'class'       => 'fa fa-flag text-danger ms-1',
                'aria-hidden' => 'true',
                'title'       => get_string('studentisflagged', 'mod_examcheck', $flaglabels),
            ]);
            return html_writer::tag('span', $picture . $name . $flagicon, ['class' => 'd-inline-flex align-items-center gap-2']);
        }

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
     * The groups column: comma-separated list of the student's groups.
     *
     * @param stdClass $row The user record.
     * @return string
     */
    public function col_groups($row): string {
        $names = $this->usergroups[(int) $row->id] ?? [];
        return s(implode(', ', $names));
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

        // FA classes are toggled directly by checker.js setCellChecked on click;
        // routing through pix_icon would force a core/templates round-trip per click
        // (or a dual-icon swap), which is too much weight for a glyph change.
        $icon = html_writer::tag('i', '', [
            'class' => 'fa ' . ($checked ? 'fa-check' : 'fa-square-o'),
            'aria-hidden' => 'true',
        ]);
        $srtext = html_writer::tag('span', $sr, ['class' => 'sr-only']);

        $step            = $this->steprecords[$stepid] ?? null;
        $uncheckmode     = (int) ($step->uncheckmode ?? 0);
        $uncheckfreetext = (int) ($step->uncheckfreetext ?? 1)
            && has_capability('mod/examcheck:uncheckfreetext', $this->context)
            ? 1 : 0;
        $isexempt        = isset($this->exemptions[$stepid][$userid]);

        if ($isexempt) {
            $exemptlabel = get_string('exempt_studentisexempt', 'mod_examcheck');
            $exicon  = html_writer::tag('i', '', ['class' => 'fa fa-minus-circle', 'aria-hidden' => 'true']);
            $exsr    = html_writer::tag('span', $exemptlabel, ['class' => 'sr-only']);
            return html_writer::tag('button', $exicon . $exsr, [
                'type'         => 'button',
                'class'        => 'btn examcheck-cell btn-outline-warning disabled',
                'data-action'  => 'examcheck-toggle',
                'data-stepid'  => $stepid,
                'data-userid'  => $userid,
                'data-checked' => '0',
                'data-groupid' => max(0, $this->effectivegroup),
                'data-exempt'  => '1',
                'aria-pressed' => 'false',
                'title'        => $exemptlabel,
                'disabled'     => 'disabled',
            ]);
        }

        return html_writer::tag('button', $icon . $srtext, [
            'type'                 => 'button',
            'class'                => 'btn examcheck-cell ' . ($checked ? 'btn-success' : 'btn-outline-secondary'),
            'data-action'          => 'examcheck-toggle',
            'data-stepid'          => $stepid,
            'data-userid'          => $userid,
            'data-checked'         => $checked ? '1' : '0',
            // The group the roster was rendered for, so marking/polling validates access correctly.
            'data-groupid'         => max(0, $this->effectivegroup),
            'data-uncheckmode'     => $uncheckmode,
            'data-uncheckfreetext' => $uncheckfreetext,
            'aria-pressed'         => $checked ? 'true' : 'false',
            'title'                => $title,
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
