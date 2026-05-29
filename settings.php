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
 * Site administration settings for mod_examcheck.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

if ($ADMIN->fulltree) {
    // Default scan field for new instances.
    $settings->add(new admin_setting_configselect(
        'mod_examcheck/defaultscanfield',
        get_string('defaultscanfield', 'mod_examcheck'),
        get_string('defaultscanfield_desc', 'mod_examcheck'),
        'idnumber',
        \mod_examcheck\local\scanfield::get_field_menu()
    ));

    // Site-wide scan extraction pattern applied by every examcheck scanner.
    // The custom setting rejects a pattern that does not compile.
    $settings->add(new \mod_examcheck\admin\setting_scanregex(
        'mod_examcheck/defaultscanregex',
        get_string('defaultscanregex', 'mod_examcheck'),
        get_string('defaultscanregex_desc', 'mod_examcheck'),
        '',
        PARAM_RAW
    ));

    // Default "require manual confirmation" for new instances.
    $settings->add(new admin_setting_configcheckbox(
        'mod_examcheck/defaultrequireconfirm',
        get_string('defaultrequireconfirm', 'mod_examcheck'),
        get_string('defaultrequireconfirm_desc', 'mod_examcheck'),
        0
    ));

    // Default "enable scanner" for new instances.
    $settings->add(new admin_setting_configcheckbox(
        'mod_examcheck/defaultenablescanner',
        get_string('defaultenablescanner', 'mod_examcheck'),
        get_string('defaultenablescanner_desc', 'mod_examcheck'),
        1
    ));

    // Default "show camera switcher" for new instances.
    $settings->add(new admin_setting_configcheckbox(
        'mod_examcheck/defaultshowcameraswitcher',
        get_string('defaultshowcameraswitcher', 'mod_examcheck'),
        get_string('defaultshowcameraswitcher_desc', 'mod_examcheck'),
        1
    ));

    // How often (seconds) the dashboard polls for marks made by other teachers.
    $settings->add(new admin_setting_configtext(
        'mod_examcheck/pollinterval',
        get_string('pollinterval', 'mod_examcheck'),
        get_string('pollinterval_desc', 'mod_examcheck'),
        5,
        PARAM_INT
    ));
}
