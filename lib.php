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

/**
 * Library of interface functions and constants for mod_examcheck.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Return whether a feature is supported by this module.
 *
 * @param string $feature FEATURE_xx constant.
 * @return mixed True/false for supported features, null for unknown ones.
 */
function examcheck_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_SHOW_DESCRIPTION:
            return true;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_ADMINISTRATION;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_NO_VIEW_LINK:
            return false;
        default:
            return null;
    }
}

/**
 * Add a new examcheck instance.
 *
 * @param stdClass $data Form data from {@see mod_examcheck_mod_form}.
 * @param mod_examcheck_mod_form|null $mform The form instance.
 * @return int The id of the newly created instance.
 */
function examcheck_add_instance($data, $mform = null) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = $data->timecreated;
    examcheck_prepare_completion_fields($data);
    $data->id = $DB->insert_record('examcheck', $data);

    // Every new instance starts with a single "Attendance" step. Teachers add more later.
    \mod_examcheck\local\steps::seed_default_step($data->id);

    return $data->id;
}

/**
 * Update an existing examcheck instance.
 *
 * @param stdClass $data Form data from {@see mod_examcheck_mod_form}.
 * @param mod_examcheck_mod_form|null $mform The form instance.
 * @return bool Always true.
 */
function examcheck_update_instance($data, $mform = null) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    examcheck_prepare_completion_fields($data);

    return $DB->update_record('examcheck', $data);
}

/**
 * Normalise the completion form fields into the values stored on the instance.
 *
 * The "completionsteps" multi-select arrives as an array of step ids; an empty
 * selection means "all steps count towards completion".
 *
 * @param stdClass $data Form data, modified in place.
 */
function examcheck_prepare_completion_fields($data) {
    $enabled = !empty($data->completionchecked);
    $data->completionchecked = $enabled ? 1 : 0;

    if (!$enabled) {
        $data->completionsteps = '';
        return;
    }

    $steps = $data->completionsteps ?? [];
    if (!is_array($steps)) {
        $steps = array_filter(array_map('trim', explode(',', (string) $steps)), 'strlen');
    }
    $steps = array_values(array_unique(array_map('intval', $steps)));
    $data->completionsteps = implode(',', $steps);
}

/**
 * Delete an examcheck instance and all of its steps and marks.
 *
 * @param int $id The instance id.
 * @return bool True on success, false if the instance does not exist.
 */
function examcheck_delete_instance($id) {
    global $DB;

    if (!$examcheck = $DB->get_record('examcheck', ['id' => $id])) {
        return false;
    }

    $DB->delete_records('examcheck_marks', ['examcheckid' => $id]);
    $DB->delete_records('examcheck_steps', ['examcheckid' => $id]);
    $DB->delete_records('examcheck', ['id' => $id]);

    return true;
}

/**
 * Provide the icon-style activity information shown on the course page.
 *
 * @param cm_info $coursemodule The course module.
 * @return cached_cm_info|null Course module info, or null on error.
 */
function examcheck_get_coursemodule_info($coursemodule) {
    global $DB;

    $fields = 'id, name, intro, introformat, completionchecked, completionsteps';
    if (!$examcheck = $DB->get_record('examcheck', ['id' => $coursemodule->instance], $fields)) {
        return null;
    }

    $info = new cached_cm_info();
    $info->name = $examcheck->name;

    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('examcheck', $examcheck, $coursemodule->id, false);
    }

    // Populate the custom completion rules, but only for automatic completion.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionchecked'] = $examcheck->completionchecked;
        $info->customdata['completionsteps'] = $examcheck->completionsteps;
    }

    return $info;
}

/**
 * Human-readable descriptions of the active custom completion rules.
 *
 * @param cm_info|stdClass $cm Course module with completion customdata.
 * @return string[] Rule descriptions.
 */
function mod_examcheck_get_completion_active_rule_descriptions($cm) {
    if (empty($cm->customdata['customcompletionrules'])
            || $cm->completion != COMPLETION_TRACKING_AUTOMATIC) {
        return [];
    }

    $descriptions = [];
    foreach ($cm->customdata['customcompletionrules'] as $key => $val) {
        if ($key === 'completionchecked' && !empty($val)) {
            $descriptions[] = get_string('completionchecked_desc', 'mod_examcheck');
        }
    }
    return $descriptions;
}
