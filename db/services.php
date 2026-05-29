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
 * Web service function definitions for mod_examcheck.
 *
 * @package    mod_examcheck
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_examcheck_mark_user' => [
        'classname'   => 'mod_examcheck\external\mark_user',
        'description' => 'Mark a student as checked for a step.',
        'type'        => 'write',
        'capabilities' => 'mod/examcheck:check',
        'ajax'        => true,
    ],
    'mod_examcheck_unmark_user' => [
        'classname'   => 'mod_examcheck\external\unmark_user',
        'description' => 'Remove a student\'s check for a step.',
        'type'        => 'write',
        'capabilities' => 'mod/examcheck:check',
        'ajax'        => true,
    ],
    'mod_examcheck_scan_lookup' => [
        'classname'   => 'mod_examcheck\external\scan_lookup',
        'description' => 'Resolve a scanned QR/barcode value to a student and mark them.',
        'type'        => 'write',
        'capabilities' => 'mod/examcheck:check',
        'ajax'        => true,
    ],
    'mod_examcheck_get_marks' => [
        'classname'   => 'mod_examcheck\external\get_marks',
        'description' => 'Return the current marks and progress for live dashboard refresh.',
        'type'        => 'read',
        'capabilities' => 'mod/examcheck:view',
        'ajax'        => true,
    ],
];
