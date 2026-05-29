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

namespace mod_examcheck\admin;

use mod_examcheck\local\scanfield;

/**
 * Admin text setting that rejects an invalid scan extraction regular expression.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_scanregex extends \admin_setting_configtext {
    /**
     * Validate the submitted pattern.
     *
     * @param string $data The submitted value.
     * @return true|string True if valid, or an error message string.
     */
    public function validate($data) {
        $parent = parent::validate($data);
        if ($parent !== true) {
            return $parent;
        }
        return scanfield::is_valid_regex((string) $data)
            ? true
            : get_string('error_invalidregex', 'mod_examcheck');
    }
}
