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
 * Test data generator for mod_examcheck.
 *
 * @package    mod_examcheck
 * @category   test
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use mod_examcheck\local\steps;

/**
 * mod_examcheck data generator class.
 *
 * @package    mod_examcheck
 * @category   test
 * @copyright  2026 André Camacho
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class mod_examcheck_generator extends testing_module_generator {

    /**
     * Create an examcheck instance.
     *
     * @param array|stdClass|null $record Instance properties.
     * @param array|null $options Generator options.
     * @return stdClass The created instance with cmid.
     */
    public function create_instance($record = null, ?array $options = null) {
        $record = (object) (array) $record;

        $defaults = [
            'scanfield'         => 'idnumber',
            'requireconfirm'    => 0,
            'completionchecked' => 0,
            'completionstep'    => 0,
        ];
        foreach ($defaults as $key => $value) {
            if (!isset($record->$key)) {
                $record->$key = $value;
            }
        }

        return parent::create_instance($record, (array) $options);
    }

    /**
     * Add a check step to an instance.
     *
     * @param int $examcheckid The instance id.
     * @param string $name The step name.
     * @return int The new step id.
     */
    public function create_step(int $examcheckid, string $name): int {
        return steps::add_step($examcheckid, $name);
    }
}
